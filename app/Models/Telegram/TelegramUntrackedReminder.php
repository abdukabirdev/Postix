<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator of a "📍 Не назначенные слежения" report, and the reminder
 * to give their orders a tracking.
 *
 * @property int                             $id
 * @property int                             $telegram_chat_id
 * @property int                             $telegram_message_id
 * @property string                          $report_key
 * @property string|null                     $message_text
 * @property string                          $name
 * @property string                          $name_normalized
 * @property int                             $orders_count
 * @property int                             $level
 * @property int|null                        $operation_user_id
 * @property string                          $status
 * @property string|null                     $reason
 * @property int                             $attempts
 * @property string|null                     $error
 * @property string|null                     $peer
 * @property string|null                     $phrase
 * @property string|null                     $text
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property OperationUser|null              $operationUser
 */
class TelegramUntrackedReminder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /** The name is nobody we know or can write to. */
    public const REASON_NO_OPERATOR = 'no_operator';

    /** Penalties, or these reminders, are switched off. */
    public const REASON_DISABLED = 'disabled';

    /** The person's card: no private messages, or no penalties. */
    public const REASON_PERSON_OFF = 'person_off';

    /** Fewer orders than `min_count`. */
    public const REASON_FEW = 'few';

    /** A newer report came before this one went: that one goes instead. */
    public const REASON_SUPERSEDED = 'superseded';

    /** Not sent within the wait (outside the hours too long, the account down). */
    public const REASON_STALE = 'stale';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'report_key',
        'message_text',
        'name',
        'name_normalized',
        'orders_count',
        'level',
        'operation_user_id',
        'status',
        'reason',
        'attempts',
        'error',
        'peer',
        'phrase',
        'text',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'orders_count' => 'integer',
            'level' => 'integer',
            'operation_user_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function operationUser(): BelongsTo
    {
        return $this->belongsTo(OperationUser::class, 'operation_user_id');
    }
}
