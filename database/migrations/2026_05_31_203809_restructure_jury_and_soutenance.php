<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Full restructure of the jury/soutenance subsystem.
 *
 * BEFORE
 * ──────
 * jury_membres_pfe  : id, soutenance_id (FK→soutenances), enseignant_id, fonction, publie
 * soutenances       : id, projet_id, date_soutenance, heure_debut, heure_fin, salle, statut, calendrier_publie
 * plans_soutenance  : id, proposant_id, role, statut, soutenance_id, date, heure_debut, heure_fin, salle, motif_rejet, date_traitement
 *
 * AFTER
 * ─────
 * jury_membres_pfe  : id, chef_id, projet_id, encadrant_id, president_id, examinateur_id, publie
 *                     → fully independent composition group, one row per project
 *
 * soutenances       : id, jury_id (FK→jury_membres_pfe), projet_id, date_soutenance,
 *                     heure_debut, heure_fin, salle, statut, calendrier_publie
 *                     statut: en_attente | publie | termine
 *                     → created only when chef validates a plan
 *
 * plans_soutenance  : id, jury_id (FK→jury_membres_pfe), proposant_id, fonction,
 *                     date, heure_debut, heure_fin, salle, statut, motif_rejet, date_traitement
 *                     → role/soutenance_id dropped, jury_id + fonction replace them
 */
return new class extends Migration
{
    /** Safely drop a foreign key by column array — ignores if missing. */
    private function dropFkIfExists(string $table, array $columns): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($columns));
        } catch (\Throwable) {}
    }

    /** Safely drop a unique index by column array — ignores if missing. */
    private function dropUniqueIfExists(string $table, array $columns): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($columns));
        } catch (\Throwable) {}
    }

    public function up(): void
    {
        // ── 0. Drop all foreign keys & indexes that block column changes ─────────────
        // Column-array form lets Laravel resolve the real constraint name automatically.
        // Every call is wrapped in try/catch — safe whether or not the constraint exists.

        // plans_soutenance → soutenances
        $this->dropFkIfExists('plans_soutenance', ['soutenance_id']);

        // jury_membres_pfe — old schema used either soutenance_id or jury_id as the FK column
        $this->dropFkIfExists('jury_membres_pfe', ['soutenance_id']);
        $this->dropFkIfExists('jury_membres_pfe', ['jury_id']);
        $this->dropFkIfExists('jury_membres_pfe', ['enseignant_id']);
        $this->dropUniqueIfExists('jury_membres_pfe', ['soutenance_id', 'enseignant_id']);
        $this->dropUniqueIfExists('jury_membres_pfe', ['jury_id', 'enseignant_id']);
        // Also clear any broken partial unique index on projet_id from a previous failed run
        $this->dropUniqueIfExists('jury_membres_pfe', ['projet_id']);

        // ── 1. Rebuild jury_membres_pfe ──────────────────────────────────────────────

        // 1a. Drop old columns that still exist
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $toDrop = array_values(array_filter(
                ['soutenance_id', 'enseignant_id', 'fonction'],
                fn ($col) => Schema::hasColumn('jury_membres_pfe', $col)
            ));
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        // 1b. Add new columns that do not yet exist
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            if (!Schema::hasColumn('jury_membres_pfe', 'chef_id')) {
                $table->unsignedBigInteger('chef_id')->after('id');
            }
            if (!Schema::hasColumn('jury_membres_pfe', 'projet_id')) {
                $table->unsignedBigInteger('projet_id')->after('chef_id');
            }
            if (!Schema::hasColumn('jury_membres_pfe', 'encadrant_id')) {
                $table->unsignedBigInteger('encadrant_id')->nullable()->after('projet_id');
            }
            if (!Schema::hasColumn('jury_membres_pfe', 'president_id')) {
                $table->unsignedBigInteger('president_id')->nullable()->after('encadrant_id');
            }
            if (!Schema::hasColumn('jury_membres_pfe', 'examinateur_id')) {
                $table->unsignedBigInteger('examinateur_id')->nullable()->after('president_id');
            }
            // publie column already exists — keep it
        });

        // 1c. Clean bad data before adding unique constraint
        DB::table('jury_membres_pfe')
            ->where('projet_id', 0)
            ->orWhereNull('projet_id')
            ->delete();

        // Remove duplicate projet_id rows, keeping the newest (highest id) per projet_id
        DB::statement('
            DELETE j1 FROM jury_membres_pfe j1
            INNER JOIN jury_membres_pfe j2
                ON j1.projet_id = j2.projet_id AND j1.id < j2.id
        ');

        // 1d. Add unique constraint + foreign keys
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->unique('projet_id'); // one composition per project
            $table->foreign('chef_id')->references('id')->on('utilisateurs')->onDelete('cascade');
            $table->foreign('projet_id')->references('id')->on('projets_pfe')->onDelete('cascade');
            $table->foreign('encadrant_id')->references('id')->on('utilisateurs')->onDelete('set null');
            $table->foreign('president_id')->references('id')->on('utilisateurs')->onDelete('set null');
            $table->foreign('examinateur_id')->references('id')->on('utilisateurs')->onDelete('set null');
        });

        // ── 2. Rebuild soutenances ────────────────────────────────────────────────────
        Schema::table('soutenances', function (Blueprint $table) {
            if (!Schema::hasColumn('soutenances', 'jury_id')) {
                // nullable — row is created only when chef validates a plan
                $table->unsignedBigInteger('jury_id')->nullable()->after('id');
                $table->foreign('jury_id')->references('id')->on('jury_membres_pfe')->onDelete('set null');
            }
        });

        // Update statut default (varchar(30) already — just change the default)
        DB::statement("ALTER TABLE soutenances MODIFY COLUMN statut VARCHAR(30) NOT NULL DEFAULT 'en_attente'");

        // ── 3. Rebuild plans_soutenance ───────────────────────────────────────────────

        // 3a. Drop old columns
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $toDrop = array_values(array_filter(
                ['soutenance_id', 'role'],
                fn ($col) => Schema::hasColumn('plans_soutenance', $col)
            ));
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        // 3b. Add new columns
        Schema::table('plans_soutenance', function (Blueprint $table) {
            if (!Schema::hasColumn('plans_soutenance', 'jury_id')) {
                $table->unsignedBigInteger('jury_id')->after('id');
            }
            if (!Schema::hasColumn('plans_soutenance', 'fonction')) {
                $table->string('fonction', 30)->after('proposant_id'); // encadrant|president|examinateur
            }
            if (!Schema::hasColumn('plans_soutenance', 'motif_rejet')) {
                $table->text('motif_rejet')->nullable()->after('salle');
            }
            if (!Schema::hasColumn('plans_soutenance', 'date_traitement')) {
                $table->timestamp('date_traitement')->nullable()->after('motif_rejet');
            }
        });

        // 3c. Add FK (separate call — column must exist first)
        $this->dropFkIfExists('plans_soutenance', ['jury_id']); // clear any broken one
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->foreign('jury_id')->references('id')->on('jury_membres_pfe')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        // ── Reverse plans_soutenance ──────────────────────────────────────────────
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->dropForeign(['jury_id']);
            $table->dropColumn(['jury_id', 'fonction']);
            $table->unsignedBigInteger('soutenance_id')->nullable();
            $table->enum('role', ['jury', 'encadrant'])->default('jury');
            $table->foreign('soutenance_id')->references('id')->on('soutenances')->onDelete('set null');
        });

        // ── Reverse soutenances ───────────────────────────────────────────────────
        Schema::table('soutenances', function (Blueprint $table) {
            $table->dropForeign(['jury_id']);
            $table->dropColumn('jury_id');
        });

        // ── Reverse jury_membres_pfe ──────────────────────────────────────────────
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->dropForeign(['chef_id']);
            $table->dropForeign(['projet_id']);
            $table->dropForeign(['encadrant_id']);
            $table->dropForeign(['president_id']);
            $table->dropForeign(['examinateur_id']);
            $table->dropUnique(['projet_id']);
            $table->dropColumn(['chef_id', 'projet_id', 'encadrant_id', 'president_id', 'examinateur_id']);

            $table->unsignedBigInteger('soutenance_id');
            $table->unsignedBigInteger('enseignant_id');
            $table->string('fonction', 30)->default('examinateur');
            $table->unique(['soutenance_id', 'enseignant_id']);
            $table->foreign('soutenance_id')->references('id')->on('soutenances')->onDelete('cascade');
            $table->foreign('enseignant_id')->references('id')->on('utilisateurs')->onDelete('cascade');
        });
    }
};