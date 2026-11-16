<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram group of the system - one table for both of its uses:
 *   type contacts - the contact sync reads it and saves its members into
 *                   the accounts' contacts (the groups added on its page);
 *   type database - only who is in it is kept (what Join group finds).
 * A group Join group looks after has a join_status (new, approved,
 * rejected, joined, dead) and what its check read; the contact sync's own
 * groups have none.
 *
 * @property int         $id
 * @property string|null $title
 * @property string      $link
 * @property bool        $is_active
 * @property string      $type
 * @property string|null $join_status
 * @property string      $decided_by
 */
class TelegramScrapeGroup extends Model
{
    public const TYPE_CONTACTS = 'contacts';
    public const TYPE_DATABASE = 'database';

    /* Found, not read yet. */
    public const JOIN_NEW = 'new';

    /* Good enough: waits for an account. */
    public const JOIN_APPROVED = 'approved';

    /* Too quiet, too small, off topic, other letters: see reject_reason. */
    public const JOIN_REJECTED = 'rejected';

    /* At least one account is in. */
    public const JOIN_JOINED = 'joined';

    /* Gone, private or not a group: nothing more to do with it. */
    public const JOIN_DEAD = 'dead';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SEARCH = 'search';
    public const SOURCE_LINK = 'link';
    public const SOURCE_FORWARD = 'forward';

    protected $fillable = [
        'title',
        'link',
        'telegram_chat_id',
        'is_active',
        'members_count',
        'last_synced_at',
        'last_error',
        'type',
        'source',
        'found_via',
        'keyword_id',
        'join_status',
        'last_message_at',
        'messages_day',
        'messages_week',
        'senders_week',
        'per_day',
        'script_share',
        'topic_hits',
        'score',
        'reject_reason',
        'decided_by',
        'checked_at',
        'members_read',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'telegram_chat_id' => 'integer',
            'members_count' => 'integer',
            'last_synced_at' => 'datetime',
            'messages_day' => 'integer',
            'messages_week' => 'integer',
            'senders_week' => 'integer',
            'per_day' => 'float',
            'script_share' => 'integer',
            'topic_hits' => 'integer',
            'score' => 'integer',
            'members_read' => 'integer',
            'last_message_at' => 'datetime',
            'checked_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            TelegramScrapedUser::class,
            'telegram_scrape_group_user',
            'group_id',
            'scraped_user_id',
        )->withPivot('seen_at');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(TelegramJoinKeyword::class, 'keyword_id');
    }

    /**
     * Which accounts of Join group are in it (or tried).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TelegramJoinMembership::class, 'group_id');
    }

    /**
     * The groups the contact sync reads.
     */
    public function scopeForContacts(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CONTACTS);
    }

    /**
     * The groups Join group looks after.
     */
    public function scopeJoinable(Builder $query): Builder
    {
        return $query->whereNotNull('join_status');
    }

    /**
     * Groups with a public @name: anybody reads them by it, without joining.
     */
    public function scopePublicLink(Builder $query): Builder
    {
        return $query->where('link', 'not like', '+%')->where('link', 'not like', 'joinchat/%');
    }

    /**
     * An invite link (+hash): opens only by joining, so nothing can be read
     * from it beforehand.
     */
    public function isInvite(): bool
    {
        return str_starts_with($this->link, '+') || str_starts_with($this->link, 'joinchat/');
    }

    public function inviteHash(): string
    {
        return ltrim((string) preg_replace('#^joinchat/#', '', $this->link), '+');
    }

    /**
     * t.me/name, https://t.me/name, @name -> name; invite links keep their form.
     */
    public static function normalizeLink(string $link): string
    {
        $link = trim($link);
        $link = preg_replace('#^https?://#i', '', $link);
        $link = preg_replace('#^(www\.)?(t|telegram)\.me/#i', '', $link);

        return ltrim($link, '@/');
    }
}
