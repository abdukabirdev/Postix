<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use App\Enums\Telegram\TelegramClientCheckStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRM penalty forwarded to the person responsible for the request.
 *
 * @property int                       $id
 * @property int                       $telegram_chat_id
 * @property int                       $telegram_message_id
 * @property string|null               $message_text
 * @property array|null                $parsed
 * @property string|null               $request_number
 * @property int                       $repeat_number
 * @property string|null               $responsible_role
 * @property string|null               $responsible_name
 * @property int|null                  $operation_user_id
 * @property int                       $level
 * @property array|null                $metrics
 * @property int|null                  $phrase_index
 * @property string|null               $comment
 * @property int|null                  $batch_count
 * @property TelegramClientCheckStatus $status
 * @property string|null               $reason
 * @property int                       $attempts
 * @property string|null               $peer
 * @property int|null                  $dm_message_id
 * @property string|null               $reply_text
 * @property string|null               $reply_kind
 * @property string|null               $reply_answer
 */
class TelegramClientCheck extends Model
{
    public const REASON_RESPONSIBLE_MISSING = 'responsible_missing';

    public const REASON_RESPONSIBLE_UNREACHABLE = 'responsible_unreachable';

    /**
     * Penalties switched off on the person's card (penalties_enabled).
     */
    public const REASON_PERSON_PENALTIES_OFF = 'person_penalties_off';

    /**
     * Forwarded without the comment: switched off on the person's card
     * (penalty_comments_enabled).
     */
    public const REASON_PERSON_COMMENTS_OFF = 'person_comments_off';

    public const REASON_FORWARD_FAILED = 'forward_failed';

    public const REASON_COMMENT_FAILED = 'comment_failed';

    /**
     * Penalties were switched off in the panel when this one arrived.
     */
    public const REASON_DISABLED = 'disabled';

    /**
     * Forwarded, but comments were switched off: nothing followed it.
     */
    public const REASON_COMMENT_DISABLED = 'comment_disabled';

    /**
     * Penalties are switched off for the person's role (operators / sales).
     */
    public const REASON_ROLE_DISABLED = 'role_disabled';

    /**
     * The person's role sends nothing at this penalty's level.
     */
    public const REASON_LEVEL_OFF = 'level_off';

    /**
     * Forwarded; the level says "no comment" for the person's role.
     */
    public const REASON_FORWARD_ONLY = 'forward_only';

    /**
     * Forwarded without "это тоже": this time it went in silence
     * (auto_replies.send_chance.follow_up).
     */
    public const REASON_QUIET = 'quiet';

    /**
     * Came in outside the working hours (09:00-18:00 by default): ignored.
     */
    public const REASON_OUTSIDE_HOURS = 'outside_hours';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'message_text',
        'telegram_raw',
        'parsed',
        'request_number',
        'repeat_number',
        'crm_status',
        'status_since',
        'responsible_role',
        'responsible_name',
        'operation_user_id',
        'level',
        'metrics',
        'comment_level',
        'phrase_index',
        'comment',
        'batch_count',
        'status',
        'reason',
        'attempts',
        'error',
        'peer',
        'dm_message_id',
        'forwarded_at',
        'opening_sent_at',
        'sent_at',
        'reply_text',
        'replied_at',
        'reply_kind',
        'reply_answer',
        'reply_answered_at',
        'nudged_at',
        'seen_at',
        'nudge_text',
        'pinged_at',
        'ping_text',
    ];

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'telegram_raw' => 'array',
            'parsed' => 'array',
            'repeat_number' => 'integer',
            'status_since' => 'datetime',
            'level' => 'integer',
            'metrics' => 'array',
            'comment_level' => 'integer',
            'phrase_index' => 'integer',
            'batch_count' => 'integer',
            'status' => TelegramClientCheckStatus::class,
            'attempts' => 'integer',
            'dm_message_id' => 'integer',
            'forwarded_at' => 'datetime',
            'opening_sent_at' => 'datetime',
            'sent_at' => 'datetime',
            'replied_at' => 'datetime',
            'reply_answered_at' => 'datetime',
            'nudged_at' => 'datetime',
            'seen_at' => 'datetime',
            'pinged_at' => 'datetime',
        ];
    }

    public function operationUser(): BelongsTo
    {
        return $this->belongsTo(
            OperationUser::class,
            'operation_user_id',
        );
    }

    /**
     * Penalties of the people with one of these roles (DutyOwners): the
     * account that delivers a role's penalties is the only one to touch them.
     * A row without a role reads as an operator.
     *
     * @param list<string> $roles
     */
    public function scopeForRoles(Builder $query, array $roles): Builder
    {
        /* Every role: nothing is split, and the penalties are not narrowed down at all (as before roles existed). */
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
