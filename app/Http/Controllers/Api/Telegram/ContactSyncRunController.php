<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The history of contact sync runs, one row per run, and one run in detail:
 * which accounts took part, what each of them did, what each group gave.
 *
 * A run is whatever shares a run_id in the sync's log (ContactSyncService
 * writes `started` first and `finished` last); nothing else is stored for it.
 * Read only. Access is the `role:driverCheck,superadmin` middleware of the
 * route group.
 */
final class ContactSyncRunController extends Controller
{
    /** Events that name an account that took part in the run. */
    private const ACCOUNT_EVENTS = ['members', 'joined', 'added', 'skipped', 'failed', 'flood', 'disabled'];

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:5', 'max:50']]);

        $page = TelegramContactSyncLog::query()
            ->where('event', 'started')
            ->latest('id')
            ->paginate($request->integer('per_page', 15), ['*'], 'page', max(1, $request->integer('page', 1)));

        $runIds = $page->getCollection()->pluck('run_id')->all();

        $finished = TelegramContactSyncLog::query()
            ->where('event', 'finished')
            ->whereIn('run_id', $runIds)
            ->get()
            ->keyBy('run_id');

        $counts = TelegramContactSyncLog::query()
            ->whereIn('run_id', $runIds)
            ->selectRaw('run_id, event, COUNT(*) as total')
            ->groupBy('run_id', 'event')
            ->get()
            ->groupBy('run_id');

        $accounts = TelegramContactSyncLog::query()
            ->whereIn('run_id', $runIds)
            ->whereIn('event', self::ACCOUNT_EVENTS)
            ->whereNotNull('telegram_account_id')
            ->selectRaw('run_id, COUNT(DISTINCT telegram_account_id) as total')
            ->groupBy('run_id')
            ->pluck('total', 'run_id');

        $rows = $page->getCollection()->map(
            fn (TelegramContactSyncLog $start) => $this->summary(
                $start,
                $finished->get($start->run_id),
                $counts->get($start->run_id, collect()),
                (int) ($accounts[$start->run_id] ?? 0),
                ! $finished->has($start->run_id) && $this->running($start),
            ),
        )->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(string $runId): JsonResponse
    {
        $start = TelegramContactSyncLog::query()
            ->where('run_id', $runId)
            ->where('event', 'started')
            ->first();

        abort_if($start === null, 404);

        $logs = TelegramContactSyncLog::query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get(['event', 'telegram_account_id', 'group_id', 'message', 'context', 'created_at']);

        $finished = $logs->firstWhere('event', 'finished');
        $counts = $logs->groupBy('event')->map->count()->map(fn (int $n, string $event) => (object) ['event' => $event, 'total' => $n])->values();

        $accountIds = $logs->pluck('telegram_account_id')->filter()->unique()->values();
        $running = $finished === null && $this->running($start);

        return response()->json(['data' => [
            'run' => $this->summary($start, $finished, $counts, $accountIds->count(), $running),
            'accounts' => $this->accounts($logs, $accountIds),
            'groups' => $this->groups($logs),
        ]]);
    }

    /**
     * @param Collection<int, object> $counts rows of {event, total}
     *
     * @return array<string, mixed>
     */
    /**
     * The run's process holds its lock and no later run of the same kind
     * began: the sync's own runs, or one group's "fetch people".
     */
    private function running(TelegramContactSyncLog $start): bool
    {
        $groupId = (($start->context['mode'] ?? null) === 'members') ? (int) $start->group_id : null;

        $lock = $groupId !== null
            ? TelegramSyncContactsCommand::membersLock($groupId)
            : TelegramSyncContactsCommand::LOCK_NAME;

        if (! TelegramProcessLock::isHeld($lock)) {
            return false;
        }

        return $start->id === TelegramContactSyncLog::query()
            ->where('event', 'started')
            ->when(
                $groupId !== null,
                fn ($q) => $q->where('group_id', $groupId),
                fn ($q) => $q->whereNull('group_id'),
            )
            ->max('id');
    }

    private function summary(
        TelegramContactSyncLog $start,
        ?TelegramContactSyncLog $finished,
        Collection $counts,
        int $accounts,
        bool $running,
    ): array {
        $count = fn (string $event): int => (int) ($counts->firstWhere('event', $event)->total ?? 0);
        $context = (array) $start->context;

        $status = match (true) {
            $finished !== null && ! empty($finished->context['stopped']) => 'stopped',
            $finished !== null => 'ok',
            $running => 'running',
            default => 'interrupted',
        };

        return [
            'run_id' => $start->run_id,
            'trigger' => $context['trigger'] ?? null,
            /* "members": a group's people fetched into the database, no contacts saved. */
            'mode' => $context['mode'] ?? 'contacts',
            'status' => $status,
            'started_at' => $start->created_at,
            'finished_at' => $finished?->created_at,
            'duration_seconds' => $finished ? (int) $start->created_at->diffInSeconds($finished->created_at, true) : null,
            'accounts' => $accounts,
            'groups' => count((array) ($context['groups'] ?? [])),
            'members' => (int) ($finished?->context['members'] ?? 0),
            /* People the run brought into the database; null for runs from before it was counted. */
            'new' => isset($finished?->context['new']) ? (int) $finished->context['new'] : null,
            'added' => $count('added'),
            'skipped' => $count('skipped'),
            'failed' => $count('failed'),
            'held' => $count('flood') + $count('disabled'),
            'message' => $finished?->message,
            'settings' => [
                'daily_limit' => $context['daily_limit'] ?? null,
                'nick_base' => $context['nick_base'] ?? null,
            ],
        ];
    }

    /**
     * What each account did in the run.
     *
     * @param Collection<int, TelegramContactSyncLog> $logs
     * @param Collection<int, int>                    $accountIds
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(Collection $logs, Collection $accountIds): array
    {
        $names = TelegramAccount::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'phone', 'username'])
            ->keyBy('id');

        return $accountIds
            ->map(function (int $id) use ($logs, $names): array {
                $mine = $logs->where('telegram_account_id', $id);
                $count = fn (string $event): int => $mine->where('event', $event)->count();
                $stop = $mine->first(fn ($log) => in_array($log->event, ['flood', 'disabled'], true));

                return [
                    'id' => $id,
                    'phone' => $names[$id]->phone ?? null,
                    'username' => $names[$id]->username ?? null,
                    'added' => $count('added'),
                    'skipped' => $count('skipped'),
                    'failed' => $count('failed'),
                    'groups' => $count('members'),
                    'joined' => $count('joined'),
                    'stopped' => $stop?->event,
                    'stopped_reason' => $stop?->message,
                    'first_at' => $mine->first()?->created_at,
                    'last_at' => $mine->last()?->created_at,
                ];
            })
            ->sortByDesc('added')
            ->values()
            ->all();
    }

    /**
     * What each group gave in the run.
     *
     * @param Collection<int, TelegramContactSyncLog> $logs
     *
     * @return list<array<string, mixed>>
     */
    private function groups(Collection $logs): array
    {
        $ids = $logs->pluck('group_id')->filter()->unique()->values();

        $groups = TelegramScrapeGroup::query()->whereIn('id', $ids)->get(['id', 'title', 'link'])->keyBy('id');

        return $ids
            ->map(function (int $id) use ($logs, $groups): array {
                $mine = $logs->where('group_id', $id);
                $read = $mine->firstWhere('event', 'members');
                $reads = $mine->where('event', 'members');

                return [
                    'id' => $id,
                    'title' => $groups[$id]->title ?? $groups[$id]->link ?? (string) $id,
                    'members' => $read && preg_match('/^(\d+)/', (string) $read->message, $m) === 1 ? (int) $m[1] : null,
                    /* New to the database from it; null for runs from before it was counted. */
                    'new' => $reads->contains(fn ($log) => isset($log->context['new']))
                        ? (int) $reads->sum(fn ($log) => (int) ($log->context['new'] ?? 0))
                        : null,
                    'added' => $mine->where('event', 'added')->count(),
                    'skipped' => $mine->where('event', 'skipped')->count(),
                    'failed' => $mine->where('event', 'failed')->count(),
                ];
            })
            ->sortByDesc('added')
            ->values()
            ->all();
    }
}
