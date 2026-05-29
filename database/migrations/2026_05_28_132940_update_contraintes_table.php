<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contraintes', function (Blueprint $table) {
            // Drop the old catch-all JSON column
            $table->dropColumn('valeur');

            // Add chef_id so contraintes can be scoped to a chef
            // without requiring an existing affectation row
            $table->unsignedBigInteger('chef_id')->after('affectation_id')->nullable();
            $table->foreign('chef_id')->references('id')->on('utilisateurs')->onDelete('cascade');

            // Make affectation_id nullable — contraintes are now saved at
            // step 2 (before any affectation rows exist)
            $table->unsignedBigInteger('affectation_id')->nullable()->change();

            // Dedicated columns matching the Vue payload
            $table->unsignedBigInteger('encadrant_id')->nullable()->after('type');
            $table->unsignedBigInteger('etudiant_id')->nullable()->after('encadrant_id');
            $table->unsignedSmallInteger('cap')->nullable()->after('etudiant_id');
            $table->string('raison', 255)->nullable()->after('cap');
        });
    }

    public function down(): void
    {
        Schema::table('contraintes', function (Blueprint $table) {
            $table->dropForeign(['chef_id']);
            $table->dropColumn(['chef_id', 'encadrant_id', 'etudiant_id', 'cap', 'raison']);
            $table->string('valeur')->nullable();
            $table->unsignedBigInteger('affectation_id')->nullable(false)->change();
        });
    }
};