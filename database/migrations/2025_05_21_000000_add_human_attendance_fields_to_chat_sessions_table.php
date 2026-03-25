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
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Campos para rastreamento de mensagens
            $table->timestamp('last_message_at')->nullable()->after('company_id');
            $table->string('last_message_direction', 20)->nullable()->after('last_message_at');
            $table->unsignedInteger('unread_count')->default(0)->after('last_message_direction');
            
            // Status da sessão: bot, human, closed
            $table->string('status', 20)->default('bot')->after('unread_count');
            
            // Lock para atendimento humano
            $table->foreignId('locked_by_user_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by_user_id');
            
            // Índices para otimizar queries do inbox
            $table->index(['company_id', 'status', 'last_message_at'], 'inbox_listing_index');
            $table->index(['locked_by_user_id', 'status'], 'user_sessions_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex('inbox_listing_index');
            $table->dropIndex('user_sessions_index');
            
            $table->dropForeign(['locked_by_user_id']);
            $table->dropColumn([
                'last_message_at',
                'last_message_direction',
                'unread_count',
                'status',
                'locked_by_user_id',
                'locked_at',
            ]);
        });
    }
};