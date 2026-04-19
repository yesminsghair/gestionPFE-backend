<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grille_evaluations') && !Schema::hasColumn('grille_evaluations', 'visibilite')) {
            Schema::table('grille_evaluations', function (Blueprint $table) {
                $table->string('visibilite', 20)->default('directeur')->after('statut');
            });
        }

        if (Schema::hasTable('categorie_grilles') && !Schema::hasColumn('categorie_grilles', 'color')) {
            Schema::table('categorie_grilles', function (Blueprint $table) {
                $table->string('color', 20)->nullable()->after('bareme_max');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('grille_evaluations')) {
            Schema::table('grille_evaluations', function (Blueprint $table) {
                $table->dropColumn('visibilite');
            });
        }

        if (Schema::hasTable('categorie_grilles')) {
            Schema::table('categorie_grilles', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};