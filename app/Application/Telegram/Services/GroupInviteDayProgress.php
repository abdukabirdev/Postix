<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use Illuminate\Support\Carbon;

/**
 * The day of the invites in people, for the overview: the most the
 * accounts may try today, what they tried, what the schedule will still
 * try, and what will most likely not be tried at all - and why.
 *
 * The most: every account taking part, its calls of the day (warm-up and a
 * cap lowered after PEER_FLOOD included, as the run counts them) times the
 * people one call takes. What is left of it goes one of these ways:
 *  - still to come: the runs left today, each its share of an account's
 *    calls; an account resting until a later hour only gets the runs after;
 *  - lost, held: the account is switched off for the invites, logged out or
 *    left out in the panel since;
 *  - lost, resting: Telegram stopped the account (a flood, PEER_FLOOD, a
 *    hung session, nobody added in a row) over the runs it would have made;
 *  - lost, runs gone by: a scheduled run only makes its share, so a run
 *    that made less (the account was busy, the run ended early) does not
 *    give it back later;
 *  - lost, partial calls: calls that took fewer people than one call holds -
 *    split after a refusal, the last people of a queue;
 *  - lost, the queue: fewer people left to try for the group than the
 *    accounts could still try.
 * Read only: nothing of an account is written here.
 */
final class GroupInviteDayProgress
{
    /**
     * @param array<string, mixed>              $config the invite settings
     * @param int|null                          $queue  people still to try for the current group; null: no current group
     * @param array{run_id: string, trigger: string}|null $run the run going on now
     *
     * @return array<string, mixed>
     */
    public function today(array $config, ?int $queue, ?array $run = null): array
    {
        $batch = max(1, (int) $config['batch_size']);
        $full = max(1, (int) $config['batches_per_day']);
        $excluded = array_map('intval', (array) $config['excluded_accounts']);
        $now = now()->timestamp;

        $calls = TelegramGroupInviteLog::callsToday();

        $tried = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->whereNotNull('telegram_account_id')
            ->where('attempted_at', '>=', now()->startOfDay())
            ->selectRaw('telegram_account_id, COUNT(*) as total, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as added', TelegramGroupInvite::ADDED)
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        /* The calls each account made in the run going on now. */
        $inRun = $run !== null
            ? TelegramGroupInviteLog::query()
                ->where('run_id', $run['run_id'])
                ->where('event', TelegramGroupInviteLog::EVENT_CALL)
                ->whereNotNull('telegram_account_id')
                ->selectRaw('telegram_account_id, COUNT(*) as calls')
                ->groupBy('telegram_account_id')
                ->pluck('calls', 'telegram_account_id')
                ->map(fn ($n) => (int) $n)
                ->all()
            : [];

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

        $accounts = TelegramAccount::query()->whereKey($ids)->get(['id', 'is_authorized', 'session_path'])->keyBy('id');
        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->whereIn('telegram_account_id', $ids)
            ->get()
            ->keyBy('telegram_account_id');
        /* Where the warm-up starts: kept in the meta once an account has invited; looked up only for the others. */
        $unknown = array_values(array_filter($ids, fn (int $id) => ! isset(((array) ($states->get($id)?->meta ?? []))['first_day'])));
        $firstDays = $unknown === [] ? [] : TelegramGroupInviteLog::firstCallDays($unknown);
        $runTimes = $config['enabled'] ? self::runTimes($config) : [];

        $total = ['max' => 0, 'done' => 0, 'added' => 0, 'expected' => 0];
        $lost = ['held' => 0, 'resting' => 0, 'schedule' => 0, 'partial' => 0, 'queue' => 0];
        $count = ['accounts' => count($ids), 'working' => 0, 'resting' => 0, 'held' => 0, 'capped' => 0];

        foreach ($ids as $id) {
            $account = $accounts->get($id);
            $state = $states->get($id);
            $meta = (array) ($state?->meta ?? []);

            $warm = GroupInviteSettings::warmCap(TelegramGroupInviteLog::inviteDay($meta['first_day'] ?? $firstDays[$id] ?? null), $config);
            $cap = GroupInviteService::capFromMeta($meta, $warm, $full);
            $made = min($calls[$id] ?? 0, $cap);
            $people = min((int) ($tried->get($id)?->total ?? 0), $made * $batch);
            $left = $cap - $made;

            $total['max'] += $cap * $batch;
            $total['done'] += $people;
            $total['added'] += (int) ($tried->get($id)?->added ?? 0);
            $lost['partial'] += $made * $batch - $people;

            if ($left <= 0) {
                $count['capped']++;

                continue;
            }

            $gone = $account === null || ! $account->is_authorized || ! $account->session_path || in_array($id, $excluded, true);

            if ($gone || ($state !== null && ! $state->is_available)) {
                $count['held']++;
                $lost['held'] += $left * $batch;

                continue;
            }

            $restUntil = self::until($meta['rest_until'] ?? null);
            $from = max($now, $restUntil ?? 0);
            $share = GroupInviteSettings::runShare($cap, $config);

            /* The runs it still gets today, and what the run going on now may still give it. */
            $budget = $share * count(array_filter($runTimes, fn (int $at) => $at > $from));

            if ($run !== null && $restUntil === null) {
                $budget += $run['trigger'] === 'manual' ? $left : max(0, $share - ($inRun[$id] ?? 0));
            }

            $will = min($left, $budget);
            $missed = ($left - $will) * $batch;

            $restUntil !== null ? $count['resting']++ : $count['working']++;

            if ($restUntil !== null) {
                /* The runs the rest covers: those were the account's, Telegram took them. */
                $covered = $share * count(array_filter($runTimes, fn (int $at) => $at > $now && $at <= $restUntil)) * $batch;
                $byRest = min($missed, $covered);

                $lost['resting'] += $byRest;
                $lost['schedule'] += $missed - $byRest;
            } else {
                $lost['schedule'] += $missed;
            }

            $total['expected'] += $will * $batch;
        }

        /* Nobody more is tried than are waiting for the group. */
        $waiting = max(0, (int) ($queue ?? 0));

        if ($total['expected'] > $waiting) {
            $lost['queue'] = $total['expected'] - $waiting;
            $total['expected'] = $waiting;
        }

        $nextRun = collect($runTimes)->first(fn (int $at) => $at > $now);

        return [
            ...$total,
            'lost' => array_sum($lost),
            'lost_by' => $lost,
            'accounts' => $count,
            'batch_size' => $batch,
            'runs_left' => count(array_filter($runTimes, fn (int $at) => $at > $now)),
            'next_run' => $nextRun !== null ? Carbon::createFromTimestamp($nextRun)->toIso8601String() : null,
            'schedule_on' => (bool) $config['enabled'],
            'has_group' => $queue !== null,
        ];
    }

    /**
     * Today's run times, as timestamps.
     *
     * @param array<string, mixed> $config
     *
     * @return list<int>
     */
    private static function runTimes(array $config): array
    {
        return array_map(function (string $time): int {
            [$hours, $minutes] = array_map('intval', explode(':', $time));

            return now()->startOfDay()->setTime($hours, $minutes)->timestamp;
        }, GroupInviteSettings::times($config));
    }

    private static function until(mixed $at): ?int
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        $time = strtotime($at);

        return $time !== false && $time > now()->timestamp ? $time : null;
    }
}
