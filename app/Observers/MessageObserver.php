<?php

namespace App\Observers;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * MessageObserver handles denormalized fields on ChatSession
 * 
 * Architecture decisions:
 * - Updates last_message_at and last_message_preview on every message
 * - Only increments unread_count for inbound client messages on non-closed sessions
 * - Uses DB transactions to prevent race conditions
 * - Compatible with SQLite and MySQL
 */
class MessageObserver
{
    /**
     * Handle the Message "created" event.
     */
    public function created(Message $message): void
    {
        // Always update last message info
        $this->updateLastMessage($message);
        
        // Only increment unread for inbound client messages on non-closed sessions
        if ($this->shouldIncrementUnread($message)) {
            $this->incrementUnread($message);
        }
    }

    /**
     * Update last_message_at and last_message_preview on the chat session
     */
    private function updateLastMessage(Message $message): void
    {
        DB::transaction(function () use ($message) {
            $message->chatSession()->update([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $this->generatePreview($message),
            ]);
        });
    }

    /**
     * Increment unread_count for the chat session
     * Uses the model method to ensure consistency
     */
    private function incrementUnread(Message $message): void
    {
        DB::transaction(function () use ($message) {
            $message->chatSession->incrementUnreadCount();
        });
    }

    /**
     * Determine if this message should increment unread count
     * 
     * Rules:
     * - Must be inbound (direction = 'inbound')
     * - Must be from client (sender_type = 'client')
     * - Session must not be closed (status != 'closed')
     */
    private function shouldIncrementUnread(Message $message): bool
    {
        // Load the chat session if not already loaded
        if (!$message->relationLoaded('chatSession')) {
            $message->load('chatSession');
        }

        return $message->direction === 'inbound'
            && $message->sender_type === 'client'
            && $message->chatSession->status !== 'closed';
    }

    /**
     * Generate a preview text from the message content
     * Truncates to 100 characters
     */
    private function generatePreview(Message $message): string
    {
        $content = $message->content ?? '';
        
        // Remove extra whitespace and newlines
        $content = preg_replace('/\s+/', ' ', trim($content));
        
        // Truncate to 100 characters
        if (mb_strlen($content) > 100) {
            return mb_substr($content, 0, 97) . '...';
        }
        
        return $content;
    }
}