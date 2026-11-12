<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request the CRM bot reported cancelled, and the question "why" we put
 * to the person who got its last penalty.
 *
 * @property int                      $id
 * @property int                      $telegram_chat_id
 * @property int                      $telegram_message_id
 * @property string|null              $message_text
 * @property array|null               $parsed
 * @property string|null              $request_number
 * @property int|null                 $telegram_client_check_id
 * @property int|null                 $operation_user_id
 * @property string                   $status
 * @property string|null              $reason
 * @property int                      $attempts
 * @property string|null              $error
 * @property string|null              $peer
 * @property int|null                 $reply_to_message_id
 * @property string|null              $question
 * @property \Illuminate\Support\Carbon|null $asked_at
 * @property TelegramClientCheck|null $clientCheck
 */
class TelegramRequestCancellation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /** The "Operation:" line names nobody we know or can write to: nobody to ask. */
    public const REASON_NO_OPERATOR = 'no_operator';

    /** Penalties are switched off in the panel. */
    public const REASON_DISABLED = 'disabled';

    /** The person's card: no private messages, or no penalties. */
    public const REASON_PERSON_OFF = 'person_off';

    /** Not asked within the wait (outside the working hours too long, the account down). */
    public const REASON_STALE = 'stale';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'message_text',
        'parsed',
        'request_number',
        'telegram_client_check_id',
        'operation_user_id',
        'status',
        'reason',
        'attempts',
        'error',
        'peer',
        'reply_to_message_id',
        'question',
        'asked_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'parsed' => 'array',
            'telegram_client_check_id' => 'integer',
            'operation_user_id' => 'integer',
            'attempts' => 'integer',
            'reply_to_message_id' => 'integer',
            'asked_at' => 'datetime',
        ];
    }

    public function clientCheck(): BelongsTo
    {
        return $this->belongsTo(TelegramClientCheck::class, 'telegram_client_check_id');
    }

    public function operationUser(): BelongsTo
    {
        return $this->belongsTo(OperationUser::class, 'operation_user_id');
    }

    /**
     * The questions to people of these roles - the ones the asking account
     * delivers penalties to. Goes by the person, not by the penalty: a
     * question with no penalty behind it has only the person.
     *
     * @param list<string> $roles
     */
    public function scopeForRoles(Builder $query, array $roles): Builder
    {
        if (array_diff(OperationUser::ROLES, $roles) === []) {
            return $query;
        }

        return $query->whereHas('operationUser', function (Builder $person) use ($roles): void {
            $person->where(function (Builder $role) use ($roles): void {
                $role->whereIn('role', $roles);

                if (in_array(OperationUser::ROLE_OPERATION, $roles, true)) {
                    $role->orWhereNull('role');
                }
            });
        });
    }
}
