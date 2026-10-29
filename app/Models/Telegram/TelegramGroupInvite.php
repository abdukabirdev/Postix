<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try at inviting one person into one group.
 *
 * @property int         $id
 * @property int         $invite_group_id
 * @property int         $scraped_user_id
 * @property string      $status
 * @property string      $via
 * @property string|null $batch_key
 */
class TelegramGroupInvite extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_INVITED = 'invited';

    public const STATUS_FAILED = 'failed';

    /** The account's own contact: its session already knows the person. */
    public const VIA_CONTACT = 'contact';

    /** A person with a public @username from the common base, resolved first. */
    public const VIA_USERNAME = 'username';

    protected $fillable = [
        'invite_group_id',
        'scraped_user_id',
        'telegram_account_id',
        'status',
        'via',
        'error',
        'batch_key',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramScrapedUser::class, 'scraped_user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
