<?php
// database/migrations/2026_05_31_000001_add_motif_date_traitement_to_plans_soutenance.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans_soutenance', function (Blueprint $table) {
            // Rejection reason (text so chef can write freely)
            $table->text('motif_rejet')->nullable()->after('salle');
            // Date the plan was validated or rejected
            $table->timestamp('date_traitement')->nullable()->after('motif_rejet');
        });
    }

    public function down(): void
    {
        Schema::table('plans_soutenance', function (Blueprint $table) {
            $table->dropColumn(['motif_rejet', 'date_traitement']);
        });
    }
};