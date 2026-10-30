<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group the accounts invite their contacts into. Not a scrape group and
 * not a Join group: nothing is read from it except that the account can
 * invite into it.
 *
 * Deleting archives it (soft delete): who was added into it, who was not
 * and why stays, and the same link added again brings all of it back.
 *
 * @property int         $id
 * @property string|null $title
 * @property string      $link
 * @property bool        $is_active
 */
class TelegramInviteGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'link',
        'telegram_chat_id',
        'members_before',
        'members_after',
        'members_checked_at',
        'is_active',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'telegram_chat_id' => 'integer',
            'members_before' => 'integer',
            'members_after' => 'integer',
            'members_checked_at' => 'datetime',
        ];
    }

    public function invites(): HasMany
    {
        return $this->hasMany(TelegramGroupInvite::class, 'invite_group_id');
    }

    /**
     * People are added into one group at a time: this one becomes it, every
     * other group is switched off (not archived - its history stays and it
     * can be made current again).
     */
    public function makeCurrent(): void
    {
        static::query()->whereKeyNot($this->getKey())->where('is_active', true)->update(['is_active' => false]);

        $this->update(['is_active' => true]);
    }

    /**
     * The group people are added into now; null when none is switched on.
     */
    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->orderByDesc('id')->first();
    }

    /**
     * An invite link (+hash): the account joins by the hash, then invites
     * by the chat id the join returns.
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
        $link = preg_replace('#^https?://#i', '', $link) ?? $link;
        $link = preg_replace('#^(www\.)?(t|telegram)\.me/#i', '', $link) ?? $link;

        return ltrim($link, '@/');
    }
}
