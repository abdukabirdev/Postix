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

    /** Paused by the person or the admin: no round goes until it is resumed. */
    public const STATUS_PAUSED = 'paused';

    /** Still to finish: running or paused. */
    public const OPEN = [self::STATUS_ACTIVE, self::STATUS_PAUSED];

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DONE, self::STATUS_STOPPED, self::STATUS_FAILED];

    /** A bot deletes its own messages in a group only within this, hours. */
    public const DELETE_HOURS = 48;

    public const STOPPED_BY_USER = 'user';

    public const STOPPED_BY_ADMIN = 'admin';

    protected $fillable = [
        'sender_bot_user_id',
        'source_chat_id',
        'source_message_id',
        'kind',
        'text',
        'group_ids',
        'payload',
        'times',
        'interval_minutes',
        'sent_times',
        'next_at',
        'paused_at',
        'status',
        'stopped_by',
        'repeat_of_id',
        'last_error',
        'finished_at',
        'delete_asked_at',
        'delete_asked_by',
        'copies_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'source_chat_id' => 'integer',
            'source_message_id' => 'integer',
            'times' => 'integer',
            'interval_minutes' => 'integer',
            'sent_times' => 'integer',
            'group_ids' => 'array',
            'payload' => 'array',
            'next_at' => 'datetime',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
            'delete_asked_at' => 'datetime',
            'copies_deleted_at' => 'datetime',
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

    /** The drivers who wanted the author of this post. */
    public function leads(): HasMany
    {
        return $this->hasMany(TelegramSenderBotLead::class, 'sender_bot_post_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** The groups chosen by the person, or null for every group that is on. */
    public function chosenGroups(): ?array
    {
        $ids = is_array($this->group_ids) ? array_values(array_filter(array_map('intval', $this->group_ids))) : [];

        return $ids === [] ? null : $ids;
    }

    /**
     * Copies still in the groups that the bot may delete (sent, not deleted,
     * not older than Telegram lets a bot delete).
     */
    public function deletableCopies(): HasMany
    {
        return $this->sends()
            ->whereNotNull('message_id')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subHours(self::DELETE_HOURS));
    }
}
