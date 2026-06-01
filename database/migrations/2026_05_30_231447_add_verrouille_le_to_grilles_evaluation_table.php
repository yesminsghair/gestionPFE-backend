<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
{
    Schema::table('grilles_evaluation', function (Blueprint $table) {
        $table->timestamp('verrouille_le')->nullable()->after('publie_le');
    });
}

public function down(): void
{
    Schema::table('grilles_evaluation', function (Blueprint $table) {
        $table->dropColumn('verrouille_le');
    });
}
};
