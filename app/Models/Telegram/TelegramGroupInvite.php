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

    /** The call went through without an error; whether the person is in the group is not known yet. */
    public const STATUS_SENT = 'sent';

    /** In the group: Telegram's "added" message names them, or the check found them joined by the call. */
    public const STATUS_INVITED = 'invited';

    /**
     * Could be added, is not in the group: Telegram took the call and left
     * them out without a word (left the group before, the account held
     * quietly), or only a mutual contact may bring them back.
     */
    public const STATUS_NOT_ADDED = 'not_added';

    /** Was in the group before the call. */
    public const STATUS_ALREADY = 'already';

    /** Cannot be added: Telegram refused because of the person (privacy, a link to their DMs or Premium only, a deleted account ...). */
    public const STATUS_FAILED = 'failed';

    /** Was added, the "who left" check did not find them in the group any more (left_at). */
    public const STATUS_LEFT = 'left';

    /** Every status a try ends in. */
    public const SETTLED = [
        self::STATUS_SENT,
        self::STATUS_INVITED,
        self::STATUS_NOT_ADDED,
        self::STATUS_ALREADY,
        self::STATUS_FAILED,
        self::STATUS_LEFT,
    ];

    /** Got into the group by our call, whether they are still there or not. */
    public const ADDED = [self::STATUS_INVITED, self::STATUS_LEFT];

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
        'checked_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'checked_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** Archived groups too: a person's history stays whole. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id')->withTrashed();
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
