<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livrables', function (Blueprint $table) {
            // Stores the original client-side filename (e.g. "rapport_phase2.pdf")
            // so it can be shown in the UI instead of the hashed server path.
            $table->string('file_name')->nullable()->after('fichier');
        });
    }

    public function down(): void
    {
        Schema::table('livrables', function (Blueprint $table) {
            $table->dropColumn('file_name');
        });
    }
};