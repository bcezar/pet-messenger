<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class ChatSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'client_phone',
        'state',
        'data',
        'last_message_at',
        'last_message_direction',
        'last_message_preview',
        'unread_count',
        'status',
        'locked_by_user_id',
        'locked_at',
    ];

    protected $casts = [
        'data' => 'array',
        'last_message_at' => 'datetime',
        'locked_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    protected $attributes = [
        'status' => 'bot',
        'unread_count' => 0,
    ];

    // Status constants
    const STATUS_BOT = 'bot';
    const STATUS_HUMAN = 'human';
    const STATUS_CLOSED = 'closed';

    // Direction constants
    const DIRECTION_INBOUND = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    /**
     * Relacionamento com a empresa (multi-tenant)
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relacionamento com mensagens
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Relacionamento com o usuário que travou a sessão
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    /**
     * Scope: Sessões de uma empresa específica (multi-tenant obrigatório)
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope: Sessões ordenadas por última mensagem (para inbox)
     */
    public function scopeLatestMessages(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at');
    }

    /**
     * Scope: Sessões com mensagens não lidas
     */
    public function scopeWithUnread(Builder $query): Builder
    {
        return $query->where('unread_count', '>', 0);
    }

    /**
     * Scope: Sessões por status
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Sessões em atendimento bot
     */
    public function scopeBot(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BOT);
    }

    /**
     * Scope: Sessões em atendimento humano
     */
    public function scopeHuman(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HUMAN);
    }

    /**
     * Scope: Sessões fechadas
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    /**
     * Scope: Sessões ativas (bot ou human, não fechadas)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_BOT, self::STATUS_HUMAN]);
    }

    /**
     * Scope: Sessões travadas por um usuário específico
     */
    public function scopeLockedByUser(Builder $query, int $userId): Builder
    {
        return $query->where('locked_by_user_id', $userId);
    }

    /**
     * Scope: Sessões disponíveis (não travadas ou travadas por mim)
     */
    public function scopeAvailableFor(Builder $query, int $userId): Builder
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('locked_by_user_id')
              ->orWhere('locked_by_user_id', $userId);
        });
    }

    /**
     * Scope: Query otimizada para inbox (carrega relacionamentos necessários)
     */
    public function scopeForInbox(Builder $query): Builder
    {
        return $query->with(['company:id,name', 'lockedBy:id,name'])
                    ->latestMessages();
    }

    /**
     * Travar sessão para atendimento humano
     */
    public function lockForUser(int $userId): bool
    {
        return $this->update([
            'locked_by_user_id' => $userId,
            'locked_at' => now(),
            'status' => self::STATUS_HUMAN,
        ]);
    }

    /**
     * Destravar sessão
     */
    public function unlock(): bool
    {
        return $this->update([
            'locked_by_user_id' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Transferir para atendimento humano
     */
    public function transferToHuman(): bool
    {
        return $this->update([
            'status' => self::STATUS_HUMAN,
        ]);
    }

    /**
     * Retornar para atendimento bot
     */
    public function transferToBot(): bool
    {
        return $this->update([
            'status' => self::STATUS_BOT,
            'locked_by_user_id' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Fechar sessão
     */
    public function close(): bool
    {
        return $this->update([
            'status' => self::STATUS_CLOSED,
            'locked_by_user_id' => null,
            'locked_at' => null,
        ]);
    }

    /**
     * Marcar mensagens como lidas
     */
    public function markAsRead(): bool
    {
        return $this->update(['unread_count' => 0]);
    }

    /**
     * Incrementar contador de não lidas
     * NEVER update unread_count directly, always use this method
     */
    public function incrementUnread(): bool
    {
        return $this->increment('unread_count');
    }

    /**
     * Alias for incrementUnread to match Observer usage
     */
    public function incrementUnreadCount(): void
    {
        $this->increment('unread_count');
    }

    /**
     * Reset unread count to zero
     */
    public function resetUnreadCount(): void
    {
        $this->update(['unread_count' => 0]);
    }

    /**
     * Atualizar última mensagem
     */
    public function updateLastMessage(string $direction): bool
    {
        return $this->update([
            'last_message_at' => now(),
            'last_message_direction' => $direction,
        ]);
    }

    /**
     * Verificar se sessão está travada
     */
    public function isLocked(): bool
    {
        return !is_null($this->locked_by_user_id);
    }

    /**
     * Verificar se sessão está travada por um usuário específico
     */
    public function isLockedBy(int $userId): bool
    {
        return $this->locked_by_user_id === $userId;
    }

    /**
     * Verificar se está em atendimento humano
     */
    public function isHumanAttendance(): bool
    {
        return $this->status === self::STATUS_HUMAN;
    }

    /**
     * Verificar se está em atendimento bot
     */
    public function isBotAttendance(): bool
    {
        return $this->status === self::STATUS_BOT;
    }

    /**
     * Verificar se está fechada
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}