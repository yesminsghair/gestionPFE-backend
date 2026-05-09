<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultats_pfe', function (Blueprint $table) {
            $table->boolean('en_biblio')->default(false)->after('publie_le');
            $table->boolean('archive')->default(false)->after('en_biblio');
            $table->timestamp('archive_le')->nullable()->after('archive');
        });
    }
 
    public function down(): void
    {
        Schema::table('resultats_pfe', function (Blueprint $table) {
            $table->dropColumn(['en_biblio', 'archive', 'archive_le']);
        });
    }
};