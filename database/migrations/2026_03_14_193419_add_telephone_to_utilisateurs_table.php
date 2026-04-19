<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('utilisateurs', 'telephone')) {
            Schema::table('utilisateurs', function (Blueprint $table) {
                $table->string('telephone', 20)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('utilisateurs', 'telephone')) {
            Schema::table('utilisateurs', function (Blueprint $table) {
                $table->dropColumn('telephone');
            });
        }
    }
};