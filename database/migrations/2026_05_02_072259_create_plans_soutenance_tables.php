<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. plans_soutenance ────────────────────────────────────────
        Schema::create('plans_soutenance', function (Blueprint $table) {
            $table->id();

            // The encadrant or jury member who proposes the plan
            $table->foreignId('proposant_id')
                  ->constrained('utilisateurs')
                  ->cascadeOnDelete();

            // 'jury' | 'encadrant'
            $table->enum('role', ['jury', 'encadrant']);

            // 'en_attente' | 'approuve' | 'rejete'
            $table->enum('statut', ['en_attente', 'approuve', 'rejete'])
                  ->default('en_attente');

            $table->timestamps();
        });

        // ── 2. creneaux_plan ───────────────────────────────────────────
        Schema::create('creneaux_plan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')
                  ->constrained('plans_soutenance')
                  ->cascadeOnDelete();

            // Optional: which jury session this slot is for
            $table->foreignId('jury_id')
                  ->nullable()
                  ->constrained('jurys_pfe')
                  ->nullOnDelete();

            // Optional: which student this slot is for
            $table->foreignId('etudiant_id')
                  ->nullable()
                  ->constrained('utilisateurs')
                  ->nullOnDelete();

            $table->date('date');
            $table->time('heure_debut');
            $table->string('salle', 100);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creneaux_plan');
        Schema::dropIfExists('plans_soutenance');
    }
};