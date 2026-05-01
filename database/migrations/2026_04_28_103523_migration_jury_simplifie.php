<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. projets_pfe — one row per student project
        Schema::create('projets_pfe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('etudiant_id');
            $table->unsignedBigInteger('encadrant_id')->nullable();
            $table->string('titre', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('specialite', 100)->nullable();
            $table->timestamps();

            $table->foreign('etudiant_id')->references('id')->on('utilisateurs')->onDelete('cascade');
            $table->foreign('encadrant_id')->references('id')->on('utilisateurs')->onDelete('set null');
            $table->unique('etudiant_id'); // one project per student
        });

        // 2. jurys_pfe — one jury per project
        Schema::create('jurys_pfe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('projet_id');
            $table->date('date_soutenance')->nullable();
            $table->time('heure_debut')->nullable();
            $table->time('heure_fin')->nullable();
            $table->string('salle', 100)->nullable();
            $table->string('statut', 30)->default('en_attente'); // en_attente | planifie | termine | annule
            $table->boolean('calendrier_publie')->default(false);
            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets_pfe')->onDelete('cascade');
            $table->unique('projet_id'); // one jury per project
        });

        // 3. jury_membres_pfe — members of a jury
        Schema::create('jury_membres_pfe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jury_id');
            $table->unsignedBigInteger('enseignant_id');
            $table->string('fonction', 30)->default('examinateur'); // president | examinateur | encadrant
            $table->timestamps();

            $table->foreign('jury_id')->references('id')->on('jurys_pfe')->onDelete('cascade');
            $table->foreign('enseignant_id')->references('id')->on('utilisateurs')->onDelete('cascade');
            $table->unique(['jury_id', 'enseignant_id']); // one entry per member per jury
        });

        // 4. notes_pfe — one note per member per jury
        Schema::create('notes_pfe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jury_id');
            $table->unsignedBigInteger('enseignant_id'); // who gave the note
            $table->decimal('note', 4, 2);               // 0.00–20.00
            $table->text('commentaire')->nullable();
            $table->boolean('finalise')->default(false);
            $table->timestamps();

            $table->foreign('jury_id')->references('id')->on('jurys_pfe')->onDelete('cascade');
            $table->foreign('enseignant_id')->references('id')->on('utilisateurs')->onDelete('cascade');
            $table->unique(['jury_id', 'enseignant_id']);
        });

        // 5. resultats_pfe — final deliberation result
        Schema::create('resultats_pfe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jury_id');
            $table->unsignedBigInteger('etudiant_id');
            $table->decimal('note_finale', 4, 2)->nullable();
            $table->string('mention', 30)->nullable();   // Très bien | Bien | Assez bien | Passable | Insuffisant
            $table->string('decision', 20)->nullable();  // admis | ajourne
            $table->boolean('publie')->default(false);
            $table->timestamp('publie_le')->nullable();
            $table->timestamps();

            $table->foreign('jury_id')->references('id')->on('jurys_pfe')->onDelete('cascade');
            $table->foreign('etudiant_id')->references('id')->on('utilisateurs')->onDelete('cascade');
            $table->unique('jury_id');
            $table->unique('etudiant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultats_pfe');
        Schema::dropIfExists('notes_pfe');
        Schema::dropIfExists('jury_membres_pfe');
        Schema::dropIfExists('jurys_pfe');
        Schema::dropIfExists('projets_pfe');
    }
};