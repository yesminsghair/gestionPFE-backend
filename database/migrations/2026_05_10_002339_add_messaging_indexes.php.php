<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes that make the messaging queries fast at scale.
 *
 * Run with: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // messages table
        Schema::table('messages', function (Blueprint $table) {
            // Used by: conversations() unread count query
            $table->index(['conversation_id', 'lu'], 'idx_messages_conv_lu');

            // Used by: messages() cursor-paginated load
            $table->index(['conversation_id', 'created_at'], 'idx_messages_conv_created');

            // Used by: markConversationRead (WHERE conversation_id + expediteur_id + lu)
            $table->index(['conversation_id', 'expediteur_id', 'lu'], 'idx_messages_conv_exp_lu');
        });

        // notifications table
        Schema::table('notifications', function (Blueprint $table) {
            // Used by: unreadCount() and index()
            $table->index(['user_id', 'lu'], 'idx_notifications_user_lu');

            // Used by: index() ORDER BY created_at DESC
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created');
        });

        // conversations table
        Schema::table('conversations', function (Blueprint $table) {
            // Used by: conversations() WHERE user1_id OR user2_id
            $table->index('user1_id', 'idx_conversations_user1');
            $table->index('user2_id', 'idx_conversations_user2');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_conv_lu');
            $table->dropIndex('idx_messages_conv_created');
            $table->dropIndex('idx_messages_conv_exp_lu');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_user_lu');
            $table->dropIndex('idx_notifications_user_created');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_user1');
            $table->dropIndex('idx_conversations_user2');
        });
    }
};