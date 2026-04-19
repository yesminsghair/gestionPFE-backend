<?php
// =============================================================================
// 2024_01_01_000001_create_sprint3_tables.php
// Une seule migration regroupant toutes les tables du sprint 3
// =============================================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. PHASES ────────────────────────────────────────────────────────
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chef_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('nom');
            $table->unsignedTinyInteger('ordre')->default(1);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->decimal('coefficient', 4, 2)->default(1.00);
            $table->boolean('livrable_obligatoire')->default(false);
            $table->timestamps();
        });

        // ── 2. SUIVI_ETUDIANT_PHASE ──────────────────────────────────────────
        Schema::create('suivi_etudiant_phase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectations')->cascadeOnDelete();
            $table->foreignId('phase_id')->constrained('phases')->cascadeOnDelete();
            $table->enum('statut', ['en_attente', 'en_cours', 'validee', 'rejetee'])->default('en_attente');
            $table->timestamp('date_lancement')->nullable();
            $table->timestamp('date_validation')->nullable();
            $table->text('commentaire_encadrant')->nullable();
            $table->timestamps();

            $table->unique(['affectation_id', 'phase_id'], 'suivi_unique_affectation_phase');
        });

        // ── 3. GRILLES_EVALUATION ────────────────────────────────────────────
        Schema::create('grilles_evaluation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chef_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('nom');
            $table->enum('statut', ['brouillon', 'publie', 'verrouille'])->default('brouillon');
            $table->timestamp('publie_le')->nullable();
            $table->timestamp('verrouille_le')->nullable();
            $table->timestamps();
        });

        // ── 4. CATEGORIES_GRILLE ─────────────────────────────────────────────
        Schema::create('categories_grille', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grille_id')->constrained('grilles_evaluation')->cascadeOnDelete();
            $table->string('nom');
            $table->decimal('bareme_max', 5, 2)->default(20.00);
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();
        });

        // ── 5. CRITERES_EVALUATION ───────────────────────────────────────────
        Schema::create('criteres_evaluation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categorie_id')->constrained('categories_grille')->cascadeOnDelete();
            $table->string('nom');
            $table->decimal('bareme_max', 5, 2)->default(5.00);
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();
        });

        // ── 6. LIVRABLES ─────────────────────────────────────────────────────
        Schema::create('livrables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained('phases')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('fichier');
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente');
            $table->text('commentaire')->nullable();
            $table->boolean('verrouille')->default(false);
            $table->timestamp('depose_le')->useCurrent();
            $table->timestamps();
        });

        // ── 7. REUNIONS ───────────────────────────────────────────────────────
        Schema::create('reunions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encadrant_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->timestamp('date_reunion');
            $table->enum('type', ['presentiel', 'distanciel', 'mixte'])->default('presentiel');
            $table->enum('statut', ['planifiee', 'confirmee', 'annulee', 'effectuee'])->default('planifiee');
            $table->string('lieu')->nullable();
            $table->text('compte_rendu')->nullable();
            $table->text('motif')->nullable();
            $table->timestamps();
        });

        // ── 8. JURYS ─────────────────────────────────────────────────────────
        Schema::create('jurys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectations')->cascadeOnDelete();
            $table->unique('affectation_id', 'jurys_affectation_unique');
            $table->timestamps();
        });

        // ── 9. JURY_MEMBRES ──────────────────────────────────────────────────
        Schema::create('jury_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jury_id')->constrained('jurys')->cascadeOnDelete();
            $table->foreignId('enseignant_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->enum('fonction', ['president', 'encadrant', 'examinateur'])->default('examinateur');
            $table->timestamp('created_at')->nullable();

            $table->unique(['jury_id', 'enseignant_id'], 'jury_membres_unique');
        });

        // ── 10. SEANCES_SOUTENANCE ───────────────────────────────────────────
        Schema::create('seances_soutenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jury_id')->constrained('jurys')->cascadeOnDelete();
            $table->dateTime('date_seance');
            $table->string('salle', 100);
            $table->enum('statut', ['planifiee', 'terminee', 'annulee'])->default('planifiee');
            $table->timestamps();
        });

        // ── 11. NOTES_JURY ───────────────────────────────────────────────────
        Schema::create('notes_jury', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jury_id')->constrained('jurys')->cascadeOnDelete();
            $table->foreignId('membre_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->decimal('note', 5, 2);
            $table->text('commentaire')->nullable();
            $table->boolean('finalise')->default(false);
            $table->timestamps();

            $table->unique(['jury_id', 'membre_id'], 'notes_jury_unique');
        });

        // ── 12. RESULTATS ────────────────────────────────────────────────────
        Schema::create('resultats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectations')->cascadeOnDelete();
            $table->unique('affectation_id', 'resultats_affectation_unique');
            $table->decimal('note_finale', 5, 2)->nullable();
            $table->string('mention', 50)->nullable();
            $table->enum('decision', ['admis', 'ajourne'])->nullable();
            $table->boolean('publie')->default(false);
            $table->timestamp('publie_le')->nullable();
            $table->timestamps();
        });

        // ── 13. NOTIFICATIONS ────────────────────────────────────────────────
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->text('message');
            $table->boolean('lu')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Supprimer dans l'ordre inverse (contraintes FK)
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('resultats');
        Schema::dropIfExists('notes_jury');
        Schema::dropIfExists('seances_soutenance');
        Schema::dropIfExists('jury_membres');
        Schema::dropIfExists('jurys');
        Schema::dropIfExists('reunions');
        Schema::dropIfExists('livrables');
        Schema::dropIfExists('criteres_evaluation');
        Schema::dropIfExists('categories_grille');
        Schema::dropIfExists('grilles_evaluation');
        Schema::dropIfExists('suivi_etudiant_phase');
        Schema::dropIfExists('phases');
    }
};