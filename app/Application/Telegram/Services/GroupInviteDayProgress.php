<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;

/**
 * The day of the invites for the overview: the ideal day and how far the
 * accounts are into it.
 *
 * The ideal day: every account taking part makes all the calls it should
 * make today (warm-up and a cap lowered after PEER_FLOOD included, as the
 * run counts them), each call taking the people one call holds.
 * The progress: per account, the calls it made today out of those it
 * should make; the accounts summed up.
 * Read only: nothing of an account is written here.
 */
final class GroupInviteDayProgress
{
    /**
     * @param array<string, mixed> $config the invite settings
     *
     * @return array{max: int, due: int, made: int, tried: int, added: int, accounts: int, batch_size: int}
     */
    public function today(array $config): array
    {
        $batch = max(1, (int) $config['batch_size']);
        $full = max(1, (int) $config['batches_per_day']);
        $excluded = array_map('intval', (array) $config['excluded_accounts']);

        $calls = TelegramGroupInviteLog::callsToday();

        $tried = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->whereNotNull('telegram_account_id')
            ->where('attempted_at', '>=', now()->startOfDay())
            ->selectRaw('telegram_account_id, COUNT(*) as total, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as added', TelegramGroupInvite::ADDED)
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        /* Taking part: connected and not left out; and whoever made calls today, whatever it is now. */
        $ids = TelegramAccount::query()
            ->where('is_authorized', true)
            ->whereNotNull('session_path')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $excluded, true))
            ->merge(array_keys($calls))
            ->unique()
            ->values()
            ->all();

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->whereIn('telegram_account_id', $ids)
            ->get()
            ->keyBy('telegram_account_id');
        /* Where the warm-up starts: kept in the meta once an account has invited; looked up only for the others. */
        $unknown = array_values(array_filter($ids, fn (int $id) => ! isset(((array) ($states->get($id)?->meta ?? []))['first_day'])));
        $firstDays = $unknown === [] ? [] : TelegramGroupInviteLog::firstCallDays($unknown);

        $day = ['max' => 0, 'due' => 0, 'made' => 0, 'tried' => 0, 'added' => 0, 'accounts' => count($ids), 'batch_size' => $batch];

        foreach ($ids as $id) {
            $meta = (array) ($states->get($id)?->meta ?? []);

            $warm = GroupInviteSettings::warmCap(TelegramGroupInviteLog::inviteDay($meta['first_day'] ?? $firstDays[$id] ?? null), $config);
            $due = GroupInviteService::capFromMeta($meta, $warm, $full);

            $day['due'] += $due;
            $day['made'] += min($calls[$id] ?? 0, $due);
            $day['max'] += $due * $batch;
            $day['tried'] += (int) ($tried->get($id)?->total ?? 0);
            $day['added'] += (int) ($tried->get($id)?->added ?? 0);
        }

        return $day;
    }
}
