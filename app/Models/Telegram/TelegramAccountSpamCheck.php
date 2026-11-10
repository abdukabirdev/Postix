<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer of @SpamBot about one of our accounts.
 *
 * @property int                             $id
 * @property int                             $telegram_account_id
 * @property string                          $status
 * @property \Illuminate\Support\Carbon|null $limited_until
 * @property string|null                     $text
 * @property string|null                     $error
 * @property \Illuminate\Support\Carbon      $created_at
 */
class TelegramAccountSpamCheck extends Model
{
    /** "Good news, no limits are currently applied to your account." */
    public const FREE = 'free';

    /** "Your account is now limited until ..." */
    public const LIMITED = 'limited';

    /** The bot answered, we could not read it: the text is kept. */
    public const UNKNOWN = 'unknown';

    /** The session did not answer, or the bot did not: the error is kept. */
    public const FAILED = 'failed';

    protected $fillable = [
        'telegram_account_id',
        'status',
        'limited_until',
        'text',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'limited_until' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
