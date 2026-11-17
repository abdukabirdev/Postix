<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A group the sender bot posts into while it is switched on.
 */
class TelegramSenderBotGroup extends Model
{
    protected $fillable = [
        'chat_id',
        'title',
        'username',
        'type',
        'is_active',
        'bot_status',
        'last_error',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /** Switched on, and the bot was not taken out of it. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('chat_id')
            ->where(fn (Builder $q) => $q->whereNull('bot_status')->orWhereNotIn('bot_status', ['left', 'kicked']));
    }

    /** The bot is still there and may write. */
    public function botIsIn(): bool
    {
        return ! in_array($this->bot_status, ['left', 'kicked'], true);
    }

    public function label(): string
    {
        return $this->title ?: ($this->username !== null ? '@' . $this->username : (string) $this->chat_id);
    }
}
