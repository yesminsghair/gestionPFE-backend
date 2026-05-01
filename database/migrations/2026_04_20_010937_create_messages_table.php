<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('conversation_id');
    $table->unsignedBigInteger('expediteur_id');
    $table->text('contenu');
    $table->boolean('lu')->default(false);
    $table->timestamps();
 
    $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
    $table->foreign('expediteur_id')->references('id')->on('utilisateurs')->onDelete('cascade');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
