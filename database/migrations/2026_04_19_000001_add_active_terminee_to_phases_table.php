<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phases', function (Blueprint $table) {
            if (!Schema::hasColumn('phases', 'active')) {
                $table->boolean('active')->default(false)->after('livrable_obligatoire');
            }
            if (!Schema::hasColumn('phases', 'terminee')) {
                $table->boolean('terminee')->default(false)->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('phases', function (Blueprint $table) {
            $table->dropColumn(array_filter(['active', 'terminee'], fn($col) => Schema::hasColumn('phases', $col)));
        });
    }
};