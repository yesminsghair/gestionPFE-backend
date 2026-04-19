<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('prenom');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('matricule')->nullable();
            $table->enum('role', ['admin','directeur','chef','encadrant','enseignant','etudiant']);
            $table->string('etablissement')->nullable();
            $table->enum('status', ['pending','active','inactive'])->default('pending');
            $table->foreignId('specialite_id')->nullable()->constrained('specialites')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('utilisateurs');
    }
};