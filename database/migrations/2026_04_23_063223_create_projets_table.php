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
        Schema::create('projets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('affectation_id')->unique(); // one project per affectation
            $table->string('titre', 255);
            $table->text('description')->nullable();
            $table->string('source', 20)->default('etudiant'); // 'demande' | 'etudiant' | 'livrable'
            $table->boolean('valide')->default(false);          // encadrant validates the sujet
            $table->timestamps();

            $table->foreign('affectation_id')
                  ->references('id')
                  ->on('affectations')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projets');
    }
};