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
            
            // Foreign keys
            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();
            
            $table->foreignId('chat_session_id')
                ->nullable()
                ->constrained('chat_sessions')
                ->restrictOnDelete();
            
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            
            // Message identification
            $table->string('external_id', 100)->nullable();
            $table->string('channel', 20)->default('whatsapp');
            
            // Message content
            $table->text('content');
            $table->string('direction', 20); // inbound, outbound
            $table->string('sender_type', 20); // client, bot, human
            $table->string('status', 20)->default('sent'); // sent, delivered, read, failed
            
            // Contact information
            $table->string('client_phone', 20)->index();
            $table->string('client_name', 100)->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['company_id', 'chat_session_id']);
            $table->index(['company_id', 'client_phone', 'created_at']);
            $table->unique(['external_id', 'channel'], 'messages_external_id_channel_unique');
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