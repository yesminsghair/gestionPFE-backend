<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
{
    DB::statement("ALTER TABLE grilles_evaluation MODIFY COLUMN statut ENUM('brouillon','en_attente','valide','publie','verrouille') NOT NULL DEFAULT 'brouillon'");
}

public function down(): void
{
    DB::statement("ALTER TABLE grilles_evaluation MODIFY COLUMN statut ENUM('brouillon','publie','verrouille','actif','ferme') NOT NULL DEFAULT 'brouillon'");
}
};