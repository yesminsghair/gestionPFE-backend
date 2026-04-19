<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_encadrement', function (Blueprint $table) {
            $table->id();
            // Numéro lisible : DEM-2026-001
            $table->string('numero', 50)->unique()->nullable();
            $table->string('sujet', 255);
            $table->text('description')->nullable();
            $table->enum('statut', ['en_attente', 'acceptee', 'rejetee'])->default('en_attente');
            $table->date('date_demande');
            $table->string('doc_pdf', 255)->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamp('traite_at')->nullable();

            // Étudiant qui soumet
            $table->foreignId('etudiant_id')->constrained('utilisateurs')->onDelete('cascade');
            // Encadrant souhaité (optionnel à la soumission, rempli à l'acceptation)
            $table->foreignId('encadrant_id')->nullable()->constrained('utilisateurs')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_encadrement');
    }
};