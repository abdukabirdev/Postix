<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to join a group that our bot got, and what it did with it.
 *
 * @property int         $id
 * @property int         $chat_id
 * @property int|null    $invite_group_id
 * @property int         $telegram_user_id
 * @property int|null    $scraped_user_id
 * @property int|null    $telegram_account_id
 * @property string      $status
 * @property string|null $error
 */
class TelegramJoinRequest extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'chat_id',
        'chat_title',
        'invite_group_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'scraped_user_id',
        'telegram_account_id',
        'is_new',
        'status',
        'error',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'telegram_user_id' => 'integer',
            'is_new' => 'boolean',
            'requested_at' => 'datetime',
        ];
    }

    /** Archived groups too. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id')->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
