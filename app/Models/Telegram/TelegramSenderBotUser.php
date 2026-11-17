<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Whoever pressed Start in the sender bot, and where they stand: asked the
 * admin, let in, turned away, or let in and then blocked.
 */
class TelegramSenderBotUser extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_BLOCKED,
    ];

    protected $fillable = [
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'language',
        'status',
        'step',
        'draft',
        'requested_at',
        'decided_at',
        'decided_by',
        'notified_at',
        'notify_error',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'draft' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'notified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(TelegramSenderBotPost::class, 'sender_bot_user_id');
    }

    public function name(): string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->username !== null ? '@' . $this->username : (string) $this->telegram_user_id);
    }

    /** The bot speaks to the person in the language they chose; Russian until then. */
    public function locale(): string
    {
        return in_array($this->language, ['ru', 'uz'], true) ? $this->language : 'ru';
    }
}
