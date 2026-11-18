<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the sender bot's history: who did what to whom - a request,
 * the admin's word, a post started, a round, a pause, a stop, a deletion.
 */
class TelegramSenderBotEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    /** The kinds, by the filter of the history page they fall under. */
    public const GROUPS = [
        'requests' => ['requested', 'approved', 'rejected', 'blocked', 'unblocked'],
        'posts' => ['post_created', 'post_paused', 'post_resumed', 'post_now', 'post_stopped', 'post_done', 'post_failed', 'round', 'copies_deleted'],
        'drivers' => ['lead_link', 'lead_asked', 'lead_contact', 'lead_not_found'],
        'groups' => ['group_joined', 'group_left', 'group_added', 'group_on', 'group_off', 'group_removed'],
    ];

    protected $table = 'telegram_sender_bot_events';

    protected $fillable = [
        'type',
        'actor',
        'sender_bot_user_id',
        'sender_bot_post_id',
        'sender_bot_group_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotUser::class, 'sender_bot_user_id');
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
