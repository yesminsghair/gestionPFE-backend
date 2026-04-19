<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->string('domaine_expertise', 200)->nullable()->after('etablissement');
            $table->date('date_affectation')->nullable()->after('specialite_id');
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropColumn(['domaine_expertise', 'date_affectation']);
        });
    }
};