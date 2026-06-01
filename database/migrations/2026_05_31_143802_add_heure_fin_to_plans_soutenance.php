<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans_soutenance', function (Blueprint $table) {
            // Added after heure_debut to keep column order logical
            $table->time('heure_fin')->nullable()->after('heure_debut');
        });
    }

    public function down(): void
    {
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->dropColumn('heure_fin');
        });
    }
};