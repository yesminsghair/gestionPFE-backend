<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulaires_voeux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chef_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->string('titre');
            $table->date('date_limite');
            $table->unsignedTinyInteger('nb_max_etudiants')->default(3);
            $table->text('message')->nullable();
            // Champs dynamiques inclus : ["disponibilite","specialites","nbEtudiants","themes","commentaire","cotutelle"]
            $table->json('champs');
            $table->enum('statut', ['brouillon', 'publie', 'verrouille'])->default('brouillon');
            $table->timestamp('publie_at')->nullable();
            $table->timestamp('verrouille_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulaires_voeux');
    }
};