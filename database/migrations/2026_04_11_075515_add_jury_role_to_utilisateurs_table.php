<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modifier la colonne role pour ajouter 'jury'
        DB::statement("ALTER TABLE utilisateurs MODIFY COLUMN role ENUM('admin','directeur','chef','encadrant','enseignant','etudiant','jury') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE utilisateurs MODIFY COLUMN role ENUM('admin','directeur','chef','encadrant','enseignant','etudiant') NOT NULL");
    }
};