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
        Schema::create('jurys_pfe', function (Blueprint $table) {
    $table->id();
    $table->foreignId('projet_id')->unique()->constrained('projets_pfe')->cascadeOnDelete();
    $table->date('date_soutenance')->nullable();
    $table->time('heure_debut')->nullable();
    $table->time('heure_fin')->nullable();
    $table->string('salle')->nullable();
    $table->enum('statut', ['en_attente','planifie','termine','annule'])->default('en_attente');
    $table->boolean('calendrier_publie')->default(false);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jurys_pfe');
    }
};
