<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration : restructuration des tables de soutenance
 * ─────────────────────────────────────────────────────
 * AVANT :
 *   jurys_pfe        → jury + planning dans une même table
 *   plans_soutenance → proposition (sans créneau inline)
 *   creneaux_plan    → détail du créneau (date/heure/salle) lié à un plan
 *
 * APRÈS :
 *   soutenances      → ancienne jurys_pfe renommée (structure inchangée)
 *   plans_soutenance → un plan = un créneau (date/heure/salle inline + soutenance_id FK)
 *   creneaux_plan    → SUPPRIMÉE (données migrées dans plans_soutenance)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Renommer jurys_pfe → soutenances ──────────────────────────────
        Schema::rename('jurys_pfe', 'soutenances');

        // ── 2. Mettre à jour les tables enfants (renommer la FK jury_id) ─────

        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->renameColumn('jury_id', 'soutenance_id');
        });
        // Note : jury_membres_pfe garde son nom de table

        Schema::table('notes_pfe', function (Blueprint $table) {
            $table->renameColumn('jury_id', 'soutenance_id');
        });

        Schema::table('resultats_pfe', function (Blueprint $table) {
            $table->renameColumn('jury_id', 'soutenance_id');
        });

        // ── 3. Restructurer plans_soutenance ─────────────────────────────────
        //    Ajout de : soutenance_id (FK nullable), date, heure_debut, salle
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->foreignId('soutenance_id')
                  ->nullable()
                  ->after('statut')
                  ->constrained('soutenances')
                  ->nullOnDelete();

            $table->date('date')->nullable()->after('soutenance_id');
            $table->time('heure_debut')->nullable()->after('date');
            $table->string('salle', 100)->nullable()->after('heure_debut');
        });

        // ── 4. Migrer les données de creneaux_plan → plans_soutenance ────────
        //    Chaque créneau existant devient une ligne plans_soutenance
        //    avec les champs date/heure/salle copiés directement.
        if (Schema::hasTable('creneaux_plan')) {
            $creneaux = DB::table('creneaux_plan')->get();

            foreach ($creneaux as $c) {
                // Récupérer le plan parent
                $plan = DB::table('plans_soutenance')->find($c->plan_id);
                if (!$plan) continue;

                // Si le plan n'a pas encore de date (premier créneau), on met à jour la ligne existante
                if ($plan->date === null) {
                    DB::table('plans_soutenance')->where('id', $c->plan_id)->update([
                        'date'          => $c->date,
                        'heure_debut'   => $c->heure_debut,
                        'salle'         => $c->salle,
                        'soutenance_id' => $c->jury_id,   // jury_id dans creneaux_plan = ancienne jurys_pfe.id
                    ]);
                } else {
                    // Sinon, créer une nouvelle ligne plans_soutenance pour ce créneau supplémentaire
                    DB::table('plans_soutenance')->insert([
                        'proposant_id'  => $plan->proposant_id,
                        'role'          => $plan->role,
                        'statut'        => $plan->statut,
                        'soutenance_id' => $c->jury_id,
                        'date'          => $c->date,
                        'heure_debut'   => $c->heure_debut,
                        'salle'         => $c->salle,
                        'created_at'    => $plan->created_at,
                        'updated_at'    => now(),
                    ]);
                }
            }
        }

        // ── 5. Supprimer creneaux_plan (données migrées) ─────────────────────
        Schema::dropIfExists('creneaux_plan');
    }

    public function down(): void
    {
        // Recréer creneaux_plan
        Schema::create('creneaux_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans_soutenance')->cascadeOnDelete();
            $table->foreignId('jury_id')->nullable()->constrained('soutenances')->nullOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->date('date');
            $table->time('heure_debut');
            $table->string('salle', 100);
            $table->timestamps();
        });

        // Retirer les colonnes ajoutées à plans_soutenance
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('soutenance_id');
            $table->dropColumn(['date', 'heure_debut', 'salle']);
        });

        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->renameColumn('soutenance_id', 'jury_id');
        });
        Schema::table('notes_pfe', function (Blueprint $table) {
            $table->renameColumn('soutenance_id', 'jury_id');
        });
        Schema::table('resultats_pfe', function (Blueprint $table) {
            $table->renameColumn('soutenance_id', 'jury_id');
        });

        // Renommer soutenances → jurys_pfe
        Schema::rename('soutenances', 'jurys_pfe');
    }
};