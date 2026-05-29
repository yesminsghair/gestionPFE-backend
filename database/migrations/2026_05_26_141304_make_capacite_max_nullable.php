<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialites', function (Blueprint $table) {
            $table->unsignedInteger('capacite_max')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('specialites', function (Blueprint $table) {
            $table->unsignedInteger('capacite_max')->nullable(false)->default(30)->change();
        });
    }
};