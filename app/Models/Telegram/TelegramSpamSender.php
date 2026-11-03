<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person (or channel) whose messages our bot deletes in our groups.
 * Typed in the panel by id or by @username; the id is learnt from their
 * first message and counts from then on.
 *
 * @property int         $id
 * @property int|null    $telegram_user_id
 * @property string|null $username lowercase, no @
 * @property string|null $name
 * @property string|null $note
 * @property int         $deleted_count
 */
class TelegramSpamSender extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'username',
        'name',
        'note',
        'deleted_count',
        'last_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'deleted_count' => 'integer',
            'last_deleted_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramSpamMessage::class, 'spam_sender_id');
    }
}
