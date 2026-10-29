<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group the accounts invite their contacts into. Not a scrape group and
 * not a Join group: nothing is read from it except that the account can
 * invite into it.
 *
 * @property int         $id
 * @property string|null $title
 * @property string      $link
 * @property bool        $is_active
 */
class TelegramInviteGroup extends Model
{
    protected $fillable = [
        'title',
        'link',
        'telegram_chat_id',
        'is_active',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'telegram_chat_id' => 'integer',
        ];
    }

    public function invites(): HasMany
    {
        return $this->hasMany(TelegramGroupInvite::class, 'invite_group_id');
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
