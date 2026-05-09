<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_grille_pfe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_pfe_id')
                  ->constrained('notes_pfe')
                  ->cascadeOnDelete();
            $table->foreignId('critere_id')
                  ->constrained('criteres_evaluation')
                  ->cascadeOnDelete();
            $table->decimal('note', 5, 2);
            $table->timestamps();
 
            $table->unique(['note_pfe_id', 'critere_id']);
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('notes_grille_pfe');
    }
};