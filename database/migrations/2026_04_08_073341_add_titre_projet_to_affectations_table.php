<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affectations', function (Blueprint $table) {
            // Ajouter la colonne titre_projet après encadrant_id
            $table->string('titre_projet', 255)->nullable()->after('encadrant_id');
            
            // Ajouter la colonne description après titre_projet
            $table->text('description')->nullable()->after('titre_projet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affectations', function (Blueprint $table) {
            // Supprimer les colonnes
            $table->dropColumn('titre_projet');
            $table->dropColumn('description');
        });
    }
};
