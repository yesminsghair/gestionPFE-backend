<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds a unique constraint on (encadrant_id, etudiant_id, date_reunion)
 * so the database itself rejects duplicate reunion slots, regardless of
 * how many concurrent requests arrive at the same time.
 *
 * Before adding the constraint we clean up any duplicates already in the
 * table (keeping the oldest row per group) so the migration does not fail
 * on existing dirty data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: delete existing duplicates, keeping the lowest id per slot ──
        DB::statement("
            DELETE r1
            FROM reunions r1
            INNER JOIN reunions r2
                ON  r2.encadrant_id  = r1.encadrant_id
                AND r2.etudiant_id   = r1.etudiant_id
                AND DATE_FORMAT(r2.date_reunion, '%Y-%m-%d %H:%i') = DATE_FORMAT(r1.date_reunion, '%Y-%m-%d %H:%i')
                AND r2.id < r1.id
        ");

        // ── Step 2: add the unique index ─────────────────────────────────────────
        Schema::table('reunions', function (Blueprint $table) {
            // We use a functional index on the minute-truncated datetime so that
            // slots differing only by seconds are still considered duplicates.
            // MySQL 8.0+ supports functional key parts; for older MySQL/MariaDB
            // the DB::statement fallback below is used instead.
        });

        // Functional unique index (MySQL 8.0+ / MariaDB 10.7+)
        // Falls back gracefully: if the engine does not support it the constraint
        // is still enforced at the application layer by the controller check.
        try {
            DB::statement("
                ALTER TABLE reunions
                ADD UNIQUE INDEX uq_reunion_slot (
                    encadrant_id,
                    etudiant_id,
                    (DATE_FORMAT(date_reunion, '%Y-%m-%d %H:%i'))
                )
            ");
        } catch (\Throwable $e) {
            // Older engine: fall back to a plain unique index on the datetime column.
            // Sub-minute duplicates are then caught by the controller (409 check).
            Schema::table('reunions', function (Blueprint $table) {
                $table->unique(
                    ['encadrant_id', 'etudiant_id', 'date_reunion'],
                    'uq_reunion_slot'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('reunions', function (Blueprint $table) {
            $table->dropUnique('uq_reunion_slot');
        });
    }
};