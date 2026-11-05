<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver found in Telegram, and one of our groups: waiting to be added,
 * or where the try ended. See the migration for the columns.
 *
 * @property int         $id
 * @property int         $invite_group_id
 * @property int         $resolved_phone_id
 * @property string|null $driver_name
 * @property string      $phone
 * @property int|null    $telegram_user_id
 * @property int|null    $resolver_account_id
 * @property int|null    $telegram_account_id
 * @property bool        $re_resolved
 * @property string      $source
 * @property string      $status
 * @property string|null $error
 * @property int         $attempts
 */
class TelegramDriverGroupAdd extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Added by our call. */
    public const STATUS_INVITED = 'invited';

    /** Was in the group before the call. */
    public const STATUS_ALREADY = 'already';

    /** The call went, the driver is not in the group: Telegram left them out without a word. */
    public const STATUS_NOT_ADDED = 'not_added';

    /** Telegram refused because of the driver (privacy, Premium only, not in Telegram any more). */
    public const STATUS_FAILED = 'failed';

    /** The call went, the group could not be looked at afterwards. */
    public const STATUS_SENT = 'sent';

    /** Where a try may end. */
    public const SETTLED = [
        self::STATUS_INVITED,
        self::STATUS_ALREADY,
        self::STATUS_NOT_ADDED,
        self::STATUS_FAILED,
        self::STATUS_SENT,
    ];

    /** Tried again by the "try again" button. */
    public const RETRYABLE = [self::STATUS_NOT_ADDED, self::STATUS_FAILED, self::STATUS_SENT];

    public const SOURCE_RESOLVE = 'resolve';

    public const SOURCE_ALL = 'all';

    protected $fillable = [
        'invite_group_id',
        'resolved_phone_id',
        'driver_name',
        'phone',
        'telegram_user_id',
        'resolver_account_id',
        'telegram_account_id',
        're_resolved',
        'source',
        'status',
        'error',
        'attempts',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'invite_group_id' => 'integer',
            'resolved_phone_id' => 'integer',
            'telegram_user_id' => 'integer',
            'resolver_account_id' => 'integer',
            'telegram_account_id' => 'integer',
            're_resolved' => 'boolean',
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function resolvedPhone(): BelongsTo
    {
        return $this->belongsTo(TelegramResolvedPhone::class, 'resolved_phone_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'resolver_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
