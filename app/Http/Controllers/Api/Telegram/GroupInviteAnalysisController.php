<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the invites did over a period, and why the accounts were stopped:
 * every stop keeps in its log line (context.counters) what the account had
 * done since its previous stop. From those: after how many calls each kind
 * of stop comes, every stop one by one, a day by day table per account,
 * what each group got and lost. The stops and the days export to CSV.
 */
final class GroupInviteAnalysisController extends Controller
{
    /** The kinds a stop is told by: PEER_FLOOD, a long flood wait, nobody added call after call, a dead session. */
    private const KINDS = ['spam', 'wait', 'empty', 'dead'];

    private const PERIODS = [7, 14, 30, 90];

    public function show(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->period($request);

        $stops = $this->stops($from, $to);
        $rows = $this->days($from, $to);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'periods' => self::PERIODS,
            'totals' => [
                'calls' => (int) $rows->sum('calls'),
                'added' => (int) $rows->sum('added'),
                'not_added' => (int) $rows->sum('not_added'),
                'failed' => (int) $rows->sum('failed'),
                'already' => (int) $rows->sum('already'),
                'lookups' => (int) $rows->sum('lookups'),
                'waits' => (int) $rows->sum('waits'),
                'stops' => $stops->count(),
                'left' => (int) $rows->sum('left'),
                'accounts' => $rows->pluck('account_id')->unique()->count(),
            ],
            'reasons' => $this->reasons($stops),
            'trend' => $this->trend($rows, $from, $to),
            'stops' => $stops->take(300)->values(),
            'days' => $rows->values(),
            'groups' => $this->groups($from, $to),
        ]);
    }

    /**
     * CSV for a spreadsheet: ';' between the columns, a BOM so Excel reads
     * Cyrillic right. type=stops - every stop with what came before it;
     * type=days - a row per day per account.
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:stops,days'],
        ]);

        [$from, $to] = $this->period($request);
        $h = fn (string $key) => __("driver_check.invites.analysis.csv.{$key}");

        if ($data['type'] === 'stops') {
            $header = ['at', 'account', 'group', 'kind', 'rpc', 'until', 'step', 'via', 'size', 'calls', 'invited', 'not_added', 'failed', 'lookups', 'waits', 'minutes', 'calls_today', 'cap', 'peer_floods_before', 'authorized_days'];
            $lines = $this->stops($from, $to)->map(fn (array $stop) => [
                $stop['at'],
                $stop['account'],
                $stop['group'],
                $stop['kind'],
                $stop['rpc'],
                $stop['until'],
                $stop['counters']['step'] ?? '',
                $stop['counters']['via'] ?? '',
                $stop['counters']['size'] ?? '',
                $stop['counters']['calls'] ?? '',
                $stop['counters']['invited'] ?? '',
                $stop['counters']['not_added'] ?? '',
                $stop['counters']['failed'] ?? '',
                $stop['counters']['lookups'] ?? '',
                $stop['counters']['waits'] ?? '',
                $stop['counters']['minutes'] ?? '',
                $stop['counters']['calls_today'] ?? '',
                $stop['counters']['cap'] ?? '',
                $stop['counters']['peer_floods_before'] ?? '',
                $stop['counters']['authorized_days'] ?? '',
            ]);
        } else {
            $header = ['day', 'account', 'calls', 'added', 'not_added', 'failed', 'already', 'lookups', 'waits', 'stops', 'left'];
            $lines = $this->days($from, $to)->map(fn (array $row) => [
                $row['day'],
                $row['account'],
                $row['calls'],
                $row['added'],
                $row['not_added'],
                $row['failed'],
                $row['already'],
                $row['lookups'],
                $row['waits'],
                $row['stops'],
                $row['left'],
            ]);
        }

        $name = "group-invites-{$data['type']}-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($header, $lines, $h): void {
            $out = fopen('php://output', 'wb');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map($h, $header), ';');

            foreach ($lines as $line) {
                fputcsv($out, array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $line), ';');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int}
     */
    private function period(Request $request): array
    {
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        return [now()->subDays($days - 1)->startOfDay(), now()->endOfDay(), $days];
    }

    /**
     * Every stop of the period, newest first, with what the account had
     * done before it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function stops(Carbon $from, Carbon $to): Collection
    {
        return TelegramGroupInviteLog::query()
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->whereBetween('created_at', [$from, $to])
            ->with(['account:id,phone', 'group:id,title,link'])
            ->latest('id')
            ->get()
            ->map(function (TelegramGroupInviteLog $log) {
                $context = (array) $log->context;

                return [
                    'id' => $log->id,
                    'at' => $log->created_at?->toDateTimeString(),
                    'account_id' => $log->telegram_account_id,
                    'account' => $log->account?->phone,
                    'group' => $log->group ? ($log->group->title ?: $log->group->link) : null,
                    'event' => $log->event,
                    'kind' => self::kindOf($log->event, $context),
                    'rpc' => $context['rpc'] ?? null,
                    'until' => $context['until'] ?? null,
                    'seconds' => $context['seconds'] ?? null,
                    'counters' => (array) ($context['counters'] ?? []),
                ];
            });
    }

    /**
     * Per kind of stop: how many, on how many accounts, after how many calls
     * (average, median, least, most), and what else came before it.
     *
     * @param Collection<int, array<string, mixed>> $stops
     *
     * @return list<array<string, mixed>>
     */
    private function reasons(Collection $stops): array
    {
        $out = [];

        foreach (self::KINDS as $kind) {
            $mine = $stops->where('kind', $kind);

            if ($mine->isEmpty()) {
                continue;
            }

            $calls = $mine->map(fn (array $stop) => (int) ($stop['counters']['calls'] ?? 0))->sort()->values();
            $avg = fn (string $key) => round((float) $mine->avg(fn (array $stop) => (float) ($stop['counters'][$key] ?? 0)), 1);

            $out[] = [
                'kind' => $kind,
                'stops' => $mine->count(),
                'accounts' => $mine->pluck('account_id')->filter()->unique()->count(),
                'calls_avg' => round((float) $calls->avg(), 1),
                'calls_median' => self::median($calls),
                'calls_min' => (int) $calls->first(),
                'calls_max' => (int) $calls->last(),
                'invited_avg' => $avg('invited'),
                'not_added_avg' => $avg('not_added'),
                'lookups_avg' => $avg('lookups'),
                'waits_avg' => $avg('waits'),
                'minutes_avg' => $avg('minutes'),
                'calls_today_avg' => $avg('calls_today'),
                'rpcs' => $mine->pluck('rpc')->filter()->countBy()->sortDesc()->take(4)->all(),
            ];
        }

        return $out;
    }

    /**
     * A row per day per account: the calls, where the people ended, the
     * @username lookups, the short flood waits, the stops, who left.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function days(Carbon $from, Carbon $to): Collection
    {
        $rows = [];

        $row = function (string $day, ?int $accountId) use (&$rows): string {
            $key = $day . '|' . (int) $accountId;

            $rows[$key] ??= [
                'day' => $day,
                'account_id' => $accountId,
                'calls' => 0,
                'added' => 0,
                'not_added' => 0,
                'failed' => 0,
                'already' => 0,
                'lookups' => 0,
                'waits' => 0,
                'stops' => 0,
                'left' => 0,
            ];

            return $key;
        };

        TelegramGroupInviteLog::query()
            ->whereIn('event', [TelegramGroupInviteLog::EVENT_CALL, TelegramGroupInviteLog::EVENT_WAIT, ...TelegramGroupInviteLog::STOP_EVENTS])
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('telegram_account_id')
            ->selectRaw('DATE(created_at) as day, telegram_account_id, event, COUNT(*) as total')
            ->groupByRaw('DATE(created_at), telegram_account_id, event')
            ->get()
            ->each(function ($line) use (&$rows, $row): void {
                $key = $row((string) $line->day, (int) $line->telegram_account_id);
                $field = match ($line->event) {
                    TelegramGroupInviteLog::EVENT_CALL => 'calls',
                    TelegramGroupInviteLog::EVENT_WAIT => 'waits',
                    default => 'stops',
                };
                $rows[$key][$field] += (int) $line->total;
            });

        TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->whereBetween('attempted_at', [$from, $to])
            ->selectRaw('DATE(attempted_at) as day, telegram_account_id, status, via, COUNT(*) as total')
            ->groupByRaw('DATE(attempted_at), telegram_account_id, status, via')
            ->get()
            ->each(function ($line) use (&$rows, $row): void {
                $key = $row((string) $line->day, $line->telegram_account_id !== null ? (int) $line->telegram_account_id : null);
                $field = match ($line->status) {
                    TelegramGroupInvite::STATUS_INVITED, TelegramGroupInvite::STATUS_LEFT => 'added',
                    TelegramGroupInvite::STATUS_NOT_ADDED => 'not_added',
                    TelegramGroupInvite::STATUS_FAILED => 'failed',
                    TelegramGroupInvite::STATUS_ALREADY => 'already',
                    default => null,
                };

                if ($field !== null) {
                    $rows[$key][$field] += (int) $line->total;
                }

                if ($line->via === TelegramGroupInvite::VIA_USERNAME) {
                    $rows[$key]['lookups'] += (int) $line->total;
                }
            });

        TelegramGroupInvite::query()
            ->where('status', TelegramGroupInvite::STATUS_LEFT)
            ->whereBetween('left_at', [$from, $to])
            ->selectRaw('DATE(left_at) as day, telegram_account_id, COUNT(*) as total')
            ->groupByRaw('DATE(left_at), telegram_account_id')
            ->get()
            ->each(function ($line) use (&$rows, $row): void {
                $key = $row((string) $line->day, $line->telegram_account_id !== null ? (int) $line->telegram_account_id : null);
                $rows[$key]['left'] += (int) $line->total;
            });

        $phones = TelegramAccount::query()->pluck('phone', 'id');

        return collect($rows)
            ->map(fn (array $r) => [...$r, 'account' => $r['account_id'] !== null ? ($phones[$r['account_id']] ?? '#' . $r['account_id']) : null])
            ->sortBy([['day', 'desc'], ['account', 'asc']])
            ->values();
    }

    /**
     * The period day by day for all the accounts together.
     *
     * @param Collection<int, array<string, mixed>> $rows
     *
     * @return list<array{day: string, calls: int, added: int, not_added: int, failed: int, stops: int, left: int}>
     */
    private function trend(Collection $rows, Carbon $from, Carbon $to): array
    {
        $byDay = $rows->groupBy('day');
        $out = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $mine = $byDay->get($day->toDateString(), collect());

            $out[] = [
                'day' => $day->toDateString(),
                'calls' => (int) $mine->sum('calls'),
                'added' => (int) $mine->sum('added'),
                'not_added' => (int) $mine->sum('not_added'),
                'failed' => (int) $mine->sum('failed'),
                'stops' => (int) $mine->sum('stops'),
                'left' => (int) $mine->sum('left'),
            ];
        }

        return $out;
    }

    /**
     * What each group got in the period and who left it.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(Carbon $from, Carbon $to): array
    {
        $tried = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->whereBetween('attempted_at', [$from, $to])
            ->selectRaw('invite_group_id, status, COUNT(*) as total')
            ->groupBy('invite_group_id', 'status')
            ->get()
            ->groupBy('invite_group_id');

        $left = TelegramGroupInvite::query()
            ->where('status', TelegramGroupInvite::STATUS_LEFT)
            ->whereBetween('left_at', [$from, $to])
            ->selectRaw('invite_group_id, COUNT(*) as total')
            ->groupBy('invite_group_id')
            ->pluck('total', 'invite_group_id');

        return TelegramInviteGroup::query()
            ->withTrashed()
            ->whereIn('id', $tried->keys()->merge($left->keys())->unique()->values())
            ->orderBy('id')
            ->get()
            ->map(function (TelegramInviteGroup $group) use ($tried, $left) {
                $counts = GroupInviteGroupController::counts($tried->get($group->id, collect())->pluck('total', 'status')->all());

                return [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->link,
                    'archived' => $group->trashed(),
                    'added' => $counts['added'],
                    'not_added' => $counts['not_added'],
                    'failed' => $counts['failed'],
                    'already' => $counts['already'],
                    'left' => (int) ($left[$group->id] ?? 0),
                    'members_before' => $group->members_before,
                    'members_after' => $group->members_after,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function kindOf(string $event, array $context): string
    {
        return match (true) {
            $event === 'disabled' => 'dead',
            $event === 'rest' => 'empty',
            in_array($context['kind'] ?? null, self::KINDS, true) => $context['kind'],
            default => 'wait',
        };
    }

    /**
     * @param Collection<int, int> $sorted
     */
    private static function median(Collection $sorted): float
    {
        $n = $sorted->count();

        if ($n === 0) {
            return 0.0;
        }

        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
