<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Table affectations ────────────────────────────────────────
        Schema::create('affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chef_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->enum('mode', ['manuel', 'aleatoire', 'semi'])->default('manuel');
            $table->enum('statut', ['en_cours', 'diffusee'])->default('en_cours');
            $table->timestamp('diffuse_at')->nullable();

            // Étudiant affecté
            $table->foreignId('etudiant_id')->constrained('utilisateurs')->onDelete('cascade');
            // Encadrant assigné (nullable — pas encore affecté)
            $table->foreignId('encadrant_id')->nullable()->constrained('utilisateurs')->onDelete('set null');

            // Une ligne par étudiant (un étudiant ne peut être affecté qu'une fois)
            $table->unique('etudiant_id');
            $table->timestamps();
        });

        // ── Table contraintes ─────────────────────────────────────────
        Schema::create('contraintes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectations')->onDelete('cascade');
            $table->string('type', 100);   // specialite | capacite | exclusion
            $table->string('valeur', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contraintes');
        Schema::dropIfExists('affectations');
    }
};