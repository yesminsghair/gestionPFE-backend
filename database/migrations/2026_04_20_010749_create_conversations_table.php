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
        Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user1_id');
    $table->unsignedBigInteger('user2_id');
    $table->timestamps();
 
    $table->foreign('user1_id')->references('id')->on('utilisateurs')->onDelete('cascade');
    $table->foreign('user2_id')->references('id')->on('utilisateurs')->onDelete('cascade');
    $table->unique(['user1_id', 'user2_id']); // one conversation per pair
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
