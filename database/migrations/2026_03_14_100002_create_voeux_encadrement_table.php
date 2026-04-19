<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voeux_encadrement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulaire_id')->constrained('formulaires_voeux')->onDelete('cascade');
            $table->foreignId('enseignant_id')->constrained('utilisateurs')->onDelete('cascade');

            // Champs du formulaire de vœux
            $table->unsignedSmallInteger('nbre_etudiants')->default(0);
            $table->unsignedSmallInteger('nbre_max_pfe')->default(3);
            $table->enum('disponibilite', ['oui', 'partielle', 'non'])->nullable();
            // encadrement = domaine/type d'encadrement souhaité
            $table->string('encadrement')->nullable();
            // Spécialités souhaitées en JSON : ["GL","IA","SI"]
            $table->json('specialites')->nullable();
            $table->text('themes')->nullable();
            $table->text('commentaire')->nullable();
            $table->boolean('cotutelle')->default(false);

            $table->enum('statut', ['brouillon', 'soumis'])->default('brouillon');
            $table->timestamp('soumis_at')->nullable();

            // Un enseignant ne peut répondre qu'une fois par formulaire
            $table->unique(['formulaire_id', 'enseignant_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voeux_encadrement');
    }
};