<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message a person asked the bot to post: copied into every group
 * that is on, `times` rounds, `interval_minutes` apart.
 */
class TelegramSenderBotPost extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DONE = 'done';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_FAILED = 'failed';

    public const STOPPED_BY_USER = 'user';

    public const STOPPED_BY_ADMIN = 'admin';

    protected $fillable = [
        'sender_bot_user_id',
        'source_chat_id',
        'source_message_id',
        'kind',
        'text',
        'times',
        'interval_minutes',
        'sent_times',
        'next_at',
        'status',
        'stopped_by',
        'last_error',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'source_chat_id' => 'integer',
            'source_message_id' => 'integer',
            'times' => 'integer',
            'interval_minutes' => 'integer',
            'sent_times' => 'integer',
            'next_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotUser::class, 'sender_bot_user_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(TelegramSenderBotSend::class, 'sender_bot_post_id');
    }
}
