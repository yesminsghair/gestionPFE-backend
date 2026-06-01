<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->boolean('publie')->default(false)->after('fonction');
        });
    }

    public function down(): void
    {
        Schema::table('jury_membres_pfe', function (Blueprint $table) {
            $table->dropColumn('publie');
        });
    }
};