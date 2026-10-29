<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a group-invite run, shown on the page.
 */
class TelegramGroupInviteLog extends Model
{
    public const UPDATED_AT = null;

    /** One invite call that reached Telegram: what the day's quota counts. */
    public const EVENT_CALL = 'batch';

    /** A short FLOOD_WAIT waited out on the spot. */
    public const EVENT_WAIT = 'wait';

    /** People sent earlier, looked up in the group: who is in it and who is not. */
    public const EVENT_CHECK = 'check';

    /** An account is done with a group: its member count before and after, next to what the calls did. */
    public const EVENT_MEMBERS = 'members';

    /**
     * The account was stopped: a flood wait or a PEER_FLOOD (flood), nobody
     * added call after call (rest), a dead or frozen session (disabled).
     * Each carries in context.counters what the account had done before.
     */
    public const STOP_EVENTS = ['flood', 'rest', 'disabled'];

    protected $fillable = [
        'run_id',
        'telegram_account_id',
        'invite_group_id',
        'event',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Invite calls made today, by account id.
     *
     * @param list<int>|null $accountIds
     *
     * @return array<int, int>
     */
    public static function callsToday(?array $accountIds = null): array
    {
        return self::query()
            ->where('event', self::EVENT_CALL)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereNotNull('telegram_account_id')
            ->when($accountIds !== null, fn ($q) => $q->whereIn('telegram_account_id', $accountIds))
            ->selectRaw('telegram_account_id, COUNT(*) as calls')
            ->groupBy('telegram_account_id')
            ->pluck('calls', 'telegram_account_id')
            ->map(fn ($calls) => (int) $calls)
            ->all();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteGroup::class, 'invite_group_id');
    }
}
