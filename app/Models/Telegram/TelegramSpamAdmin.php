<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin of our anti-spam: their links in our groups are not deleted,
 * they may forward spam to the join request bot. Or an invite link not
 * opened yet: a token and no person.
 *
 * @property int                             $id
 * @property int|null                        $telegram_user_id
 * @property string|null                     $username
 * @property string|null                     $name
 * @property string|null                     $note
 * @property string|null                     $invite_token
 * @property \Illuminate\Support\Carbon|null $invite_expires_at
 * @property \Illuminate\Support\Carbon|null $joined_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TelegramSpamAdmin extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'username',
        'name',
        'note',
        'invite_token',
        'invite_expires_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'invite_expires_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /** An invite link nobody opened yet. */
    public function isInvite(): bool
    {
        return $this->telegram_user_id === null && $this->username === null;
    }
}
