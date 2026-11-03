<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message of someone on the spam list: waiting for its time, deleted, or
 * one the bot could not delete.
 *
 * @property int                             $id
 * @property int|null                        $spam_sender_id
 * @property int                             $chat_id
 * @property string|null                     $chat_title
 * @property int                             $message_id
 * @property int|null                        $telegram_user_id
 * @property string|null                     $username
 * @property string|null                     $name
 * @property string                          $kind
 * @property string|null                     $text
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

    protected $fillable = [
        'spam_sender_id',
        'chat_id',
        'chat_title',
        'message_id',
        'telegram_user_id',
        'username',
        'name',
        'kind',
        'text',
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
