<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a member of one of our groups, as our bot saw it: came,
 * left, removed and by whom.
 *
 * @property int                        $id
 * @property int|null                   $invite_group_id
 * @property int                        $chat_id
 * @property string|null                $chat_title
 * @property int                        $telegram_user_id
 * @property string|null                $username
 * @property string|null                $name
 * @property bool                       $is_bot
 * @property int|null                   $scraped_user_id
 * @property int|null                   $member_account_id
 * @property int|null                   $invited_by_account_id
 * @property string                     $event
 * @property string|null                $via
 * @property string|null                $old_status
 * @property string|null                $new_status
 * @property string                     $actor_kind
 * @property int|null                   $actor_user_id
 * @property string|null                $actor_name
 * @property int|null                   $actor_account_id
 * @property \Illuminate\Support\Carbon $happened_at
 */
class TelegramGroupMemberEvent extends Model
{
    /** Came in by themselves: a link, a request our bot approved, a folder link. */
    public const JOINED = 'joined';

    /** Brought in by somebody (one of our accounts, as a rule). */
    public const ADDED = 'added';

    /** Went by themselves. */
    public const LEFT = 'left';

    /** Taken out by an admin (removed or banned). */
    public const REMOVED = 'removed';

    /** Banned while not in the group. */
    public const BANNED = 'banned';

    public const UNBANNED = 'unbanned';

    public const RESTRICTED = 'restricted';

    public const UNRESTRICTED = 'unrestricted';

    public const PROMOTED = 'promoted';

    public const DEMOTED = 'demoted';

    /** Some other change of rights. */
    public const CHANGED = 'changed';

    /** The events that take a member out of the group. */
    public const GONE = [self::LEFT, self::REMOVED];

    /** The events that bring one in. */
    public const CAME = [self::JOINED, self::ADDED];

    /** The member themselves did it. */
    public const BY_SELF = 'self';

    /** One of our accounts did it. */
    public const BY_OURS = 'ours';

    /** Our join request bot did it. */
    public const BY_BOT = 'bot';

    /** Somebody else: an admin of the group, another member. */
    public const BY_OTHER = 'other';

    protected $fillable = [
        'invite_group_id',
        'chat_id',
        'chat_title',
        'telegram_user_id',
        'username',
        'name',
        'is_bot',
        'scraped_user_id',
        'member_account_id',
        'invited_by_account_id',
        'event',
        'via',
        'old_status',
        'new_status',
        'actor_kind',
        'actor_user_id',
        'actor_name',
        'actor_account_id',
        'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'happened_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramScrapedUser::class, 'scraped_user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'invited_by_account_id');
    }
}
