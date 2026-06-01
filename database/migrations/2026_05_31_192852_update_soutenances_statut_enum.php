<?php
// database/migrations/2026_05_31_000002_update_soutenances_statut_enum.php
//
// soutenances.statut lifecycle:
//   en_attente  → soutenance exists but not yet scheduled
//   planifie    → chef has set date/heure/salle (plan validated)
//   publie      → calendrier_publie set by chef (students/jury notified)
//   termine     → jury president submitted the evaluation (soutenance happened)
//   annule      → cancelled

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // statut is VARCHAR(30) so we just need to document the allowed values.
        // We don't change the column type here — the model will cast/validate.
        // If you want a strict ENUM:
        DB::statement("ALTER TABLE soutenances MODIFY statut ENUM('en_attente','planifie','publie','termine','annule') NOT NULL DEFAULT 'en_attente'");

        // Migrate existing calendrier_publie=1 rows to statut='publie'
        DB::statement("UPDATE soutenances SET statut = 'publie' WHERE calendrier_publie = 1 AND statut = 'planifie'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE soutenances MODIFY statut VARCHAR(30) NOT NULL DEFAULT 'en_attente'");
    }
};