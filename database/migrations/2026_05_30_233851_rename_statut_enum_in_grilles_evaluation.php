<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remap existing rows to new enum values before altering the column
        DB::statement("UPDATE grilles_evaluation SET statut = 'en_attente' WHERE statut = 'publie'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'valide'     WHERE statut = 'verrouille'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'publie'     WHERE statut = 'actif'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'verrouille' WHERE statut = 'ferme'");

        DB::statement("ALTER TABLE grilles_evaluation MODIFY COLUMN statut ENUM('brouillon','en_attente','valide','publie','verrouille') NOT NULL DEFAULT 'brouillon'");
    }

    public function down(): void
    {
        DB::statement("UPDATE grilles_evaluation SET statut = 'publie'     WHERE statut = 'en_attente'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'verrouille' WHERE statut = 'valide'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'actif'      WHERE statut = 'publie'");
        DB::statement("UPDATE grilles_evaluation SET statut = 'ferme'      WHERE statut = 'verrouille'");

        DB::statement("ALTER TABLE grilles_evaluation MODIFY COLUMN statut ENUM('brouillon','publie','verrouille','actif','ferme') NOT NULL DEFAULT 'brouillon'");
    }
};