<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver who wanted to reach the operator of a post: forwarded the bot's
 * message from a group to the bot, or pressed the post's button.
 */
class TelegramSenderBotLead extends Model
{
    /** The driver got the operator's own chat. */
    public const STATUS_LINK = 'link_given';

    /** The operator's chat could not be given: the driver was asked for a contact. */
    public const STATUS_ASKED = 'contact_asked';

    /** The driver's contact went to the operator. */
    public const STATUS_SENT = 'contact_sent';

    /** No post of ours matched what was forwarded. */
    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUSES = [self::STATUS_LINK, self::STATUS_ASKED, self::STATUS_SENT, self::STATUS_NOT_FOUND];

    public const SOURCE_FORWARD = 'forward';

    public const SOURCE_BUTTON = 'button';

    protected $table = 'telegram_sender_bot_leads';

    protected $fillable = [
        'sender_bot_post_id',
        'sender_bot_user_id',
        'driver_telegram_id',
        'driver_username',
        'driver_name',
        'driver_phone',
        'source',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'driver_telegram_id' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotPost::class, 'sender_bot_post_id');
    }

    /** The operator: the author of the post. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramSenderBotUser::class, 'sender_bot_user_id');
    }

    public function driverLabel(): string
    {
        $name = trim((string) $this->driver_name);

        return $name !== '' ? $name : ($this->driver_username ? '@' . $this->driver_username : (string) $this->driver_telegram_id);
    }
}
