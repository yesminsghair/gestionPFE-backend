<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            // Token unique envoyé par email pour vérifier l'adresse
            $table->string('email_verification_token')->nullable()->after('email');
            // Date à laquelle l'email a été vérifié (null = pas encore vérifié)
            $table->timestamp('email_verified_at')->nullable()->after('email_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropColumn(['email_verification_token', 'email_verified_at']);
        });
    }
};