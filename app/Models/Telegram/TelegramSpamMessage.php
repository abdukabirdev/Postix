<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message of someone on the spam list, or one with a link: waiting for
 * its time, deleted, one the bot could not delete, or a link it left
 * because an admin wrote it.
 *
 * @property int                             $id
 * @property int|null                        $spam_sender_id
 * @property string                          $reason
 * @property int                             $chat_id
 * @property string|null                     $chat_title
 * @property int                             $message_id
 * @property int|null                        $telegram_user_id
 * @property string|null                     $username
 * @property string|null                     $name
 * @property string                          $kind
 * @property string|null                     $text
 * @property int|null                        $copy_message_id
 * @property \Illuminate\Support\Carbon      $delete_at
 * @property string                          $status
 * @property string|null                     $error
 * @property \Illuminate\Support\Carbon|null $removed_at
 */
class TelegramSpamMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_FAILED = 'failed';

    /** A link left in the group: its author is an admin. `error` says why (KEPT_*). */
    public const STATUS_KEPT = 'kept';

    /** Kept: the author is an admin of the bot (the owner, the Admins page). */
    public const KEPT_BOT_ADMIN = 'bot_admin';

    /** Kept: the author is an admin of the group (or the group, its channel). */
    public const KEPT_GROUP_ADMIN = 'group_admin';

    /** Kept: the bot could not learn the group's admins from Telegram. */
    public const KEPT_ADMINS_UNKNOWN = 'admins_unknown';

    /** Its author is on the spam list. */
    public const REASON_LIST = 'list';

    /** It carries a link: the owner gets a copy before it goes. */
    public const REASON_LINK = 'link';

    protected $fillable = [
        'spam_sender_id',
        'reason',
        'chat_id',
        'chat_title',
        'message_id',
        'telegram_user_id',
        'username',
        'name',
        'kind',
        'text',
        'copy_message_id',
        'sent_at',
        'delete_at',
        'status',
        'error',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'message_id' => 'integer',
            'telegram_user_id' => 'integer',
            'copy_message_id' => 'integer',
            'sent_at' => 'datetime',
            'delete_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(TelegramSpamSender::class, 'spam_sender_id');
    }
}
