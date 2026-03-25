<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    // Direction constants
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    // Sender type constants
    public const SENDER_CLIENT = 'client';
    public const SENDER_BOT = 'bot';
    public const SENDER_HUMAN = 'human';

    // Status constants
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    // Channel constants
    public const CHANNEL_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'company_id',
        'chat_session_id',
        'user_id',
        'external_id',
        'channel',
        'content',
        'direction',
        'sender_type',
        'status',
        'client_phone',
        'client_name',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Relationships
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scopes
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeFromClient($query)
    {
        return $query->where('sender_type', self::SENDER_CLIENT);
    }

    public function scopeFromBot($query)
    {
        return $query->where('sender_type', self::SENDER_BOT);
    }

    public function scopeFromHuman($query)
    {
        return $query->where('sender_type', self::SENDER_HUMAN);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Helper methods
     */
    public function isFromClient(): bool
    {
        return $this->sender_type === self::SENDER_CLIENT;
    }

    public function isFromBot(): bool
    {
        return $this->sender_type === self::SENDER_BOT;
    }

    public function isFromHuman(): bool
    {
        return $this->sender_type === self::SENDER_HUMAN;
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsDelivered(): bool
    {
        return $this->update(['status' => self::STATUS_DELIVERED]);
    }

    public function markAsRead(): bool
    {
        return $this->update(['status' => self::STATUS_READ]);
    }

    public function markAsFailed(string $errorMessage = null): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }
}