<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phases', function (Blueprint $table) {
            // active: phase is currently accessible to students & encadrants
            $table->boolean('active')->default(false)->after('livrable_obligatoire');

            // terminee: phase has been closed by the chef; the next one can now be activated
            $table->boolean('terminee')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('phases', function (Blueprint $table) {
            $table->dropColumn(['active', 'terminee']);
        });
    }
};