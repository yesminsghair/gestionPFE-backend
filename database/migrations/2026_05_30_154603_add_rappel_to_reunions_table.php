<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reunions', function (Blueprint $table) {
            $table->timestamp('rappel_scheduled_at')->nullable()->after('motif');
            $table->boolean('rappel_fired')->default(false)->after('rappel_scheduled_at');
        });
    }
 
    public function down(): void
    {
        Schema::table('reunions', function (Blueprint $table) {
            $table->dropColumn(['rappel_scheduled_at', 'rappel_fired']);
        });
    }
};
 