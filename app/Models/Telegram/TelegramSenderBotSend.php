<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One copy of a post into one group in one round: its message id, or why
 * it did not get there.
 */
class TelegramSenderBotSend extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'sender_bot_post_id',
        'sender_bot_group_id',
        'round',
        'message_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'message_id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotPost::class, 'sender_bot_post_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotGroup::class, 'sender_bot_group_id');
    }
}
