<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\DriverGroupService;
use App\Application\Telegram\Services\DriverGroupSettings;
use App\Console\Commands\Telegram\TelegramDriverGroupAddCommand;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartDriverGroupAddJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramDriverGroupAdd;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramResolvedPhone;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Group invites -> Drivers into a group: the group and the settings, what
 * the run does now, the drivers in numbers, how each account that adds
 * stands, the list of drivers, and the buttons.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class DriverGroupController extends Controller
{
    private const STATUSES = ['pending', ...TelegramDriverGroupAdd::SETTLED];

    public function __construct(
        private readonly DriverGroupSettings $settings,
        private readonly DriverGroupService $drivers,
    ) {}

    public function show(): JsonResponse
    {
        $config = $this->settings->current();
        $group = $this->settings->group();
        $running = TelegramProcessLock::isHeld(TelegramDriverGroupAddCommand::LOCK_NAME);
        $today = now()->startOfDay();

        $groups = TelegramInviteGroup::query()
            ->orderBy('id')
            ->get(['id', 'title', 'link', 'is_active', 'telegram_chat_id', 'last_error'])
            ->map(fn (TelegramInviteGroup $item) => [
                'id' => $item->id,
                'title' => $item->title ?: $item->link,
                'link' => $item->link,
                'is_current' => $item->is_active,
            ])
            ->values();

        $found = TelegramResolvedPhone::query()->whereNotNull('telegram_user_id')->count();
        $counts = array_fill_keys(self::STATUSES, 0);
        $accounts = [];
        $todayCounts = ['tried' => 0, 'added' => 0];

        if ($group !== null) {
            TelegramDriverGroupAdd::query()
                ->where('invite_group_id', $group->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->each(function ($total, $status) use (&$counts): void {
                    $counts[$status] = (int) $total;
                });

            $todayRows = TelegramDriverGroupAdd::query()
                ->where('invite_group_id', $group->id)
                ->where('attempted_at', '>=', $today);

            $todayCounts = [
                'tried' => (clone $todayRows)->count(),
                'added' => (clone $todayRows)->where('status', TelegramDriverGroupAdd::STATUS_INVITED)->count(),
            ];

            $accounts = $this->accounts($group, $config);
        }

        $queued = array_sum($counts);

        return response()->json([
            'running' => $running,
            'now' => self::now($running, $config['paused']),
            /* Stopped from the panel: the scheduler starts nothing until a start button. */
            'paused' => $config['paused'],
            'settings' => $config,
            'groups' => $groups,
            'group' => $group ? [
                'id' => $group->id,
                'title' => $group->title ?: $group->link,
                'link' => $group->link,
                'is_current' => $group->is_active,
            ] : null,
            'counts' => [
                /* Every driver found in Telegram by the number. */
                'found' => $found,
                /* In the queue of the group or tried for it. */
                'queued' => $queued,
                'not_queued' => max(0, $found - $queued),
                ...$counts,
                'retryable' => $counts['not_added'] + $counts['failed'] + $counts['sent'],
            ],
            'today' => $todayCounts,
            'accounts' => $accounts,
        ]);
    }

    /**
     * The drivers of the group, newest try first; waiting ones in the order
     * they will go.
     */
    public function rows(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...self::STATUSES])],
            'q' => ['nullable', 'string', 'max:100'],
            'account' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $group = $this->settings->group();

        if ($group === null) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'pages' => 1]]);
        }

        $status = $data['status'] ?? 'all';
        $q = trim((string) ($data['q'] ?? ''));

        $query = TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when(isset($data['account']), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('telegram_account_id', (int) $data['account'])
                ->orWhere('resolver_account_id', (int) $data['account'])))
            ->when($q !== '', function (Builder $query) use ($q): void {
                $digits = preg_replace('/\D+/', '', $q);
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], ltrim($q, '@')) . '%';

                $query->where(fn (Builder $query) => $query
                    ->where('driver_name', 'like', $like)
                    ->when($digits !== '', fn (Builder $query) => $query->orWhere('phone', 'like', '%' . $digits . '%'))
                    ->orWhereHas('resolvedPhone', fn (Builder $query) => $query
                        ->where('telegram_username', 'like', $like)
                        ->orWhere('telegram_first_name', 'like', $like)
                        ->orWhere('telegram_last_name', 'like', $like)));
            })
            ->with(['resolvedPhone:id,telegram_username,telegram_first_name,telegram_last_name', 'resolver:id,phone', 'account:id,phone']);

        $status === 'pending'
            ? $query->orderBy('id')
            : $query->orderByRaw('attempted_at IS NULL')->orderByDesc('attempted_at')->orderByDesc('id');

        $page = $query->paginate(50, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (TelegramDriverGroupAdd $row) => [
                'id' => $row->id,
                'driver_name' => $row->driver_name,
                'phone' => $row->phone,
                'telegram_user_id' => $row->telegram_user_id,
                'username' => $row->resolvedPhone?->telegram_username,
                'telegram_name' => trim(($row->resolvedPhone?->telegram_first_name ?? '') . ' ' . ($row->resolvedPhone?->telegram_last_name ?? '')) ?: null,
                'resolver' => $row->resolver?->phone,
                'resolver_id' => $row->resolver_account_id,
                'account' => $row->account?->phone,
                'account_id' => $row->telegram_account_id,
                're_resolved' => $row->re_resolved,
                'source' => $row->source,
                'status' => $row->status,
                'error' => $row->error,
                'attempts' => $row->attempts,
                'attempted_at' => $row->attempted_at,
                'created_at' => $row->created_at,
            ])->values(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'integer', Rule::exists('telegram_invite_groups', 'id')->whereNull('deleted_at')],
            'on_resolve' => ['required', 'boolean'],
            'per_account_day' => ['required', 'integer', 'min:1', 'max:200'],
            'delay_min' => ['required', 'integer', 'min:0', 'max:600'],
            'delay_max' => ['required', 'integer', 'min:0', 'max:600', 'gte:delay_min'],
        ]);

        $before = $this->settings->current()['group_id'];
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : null;

        $this->settings->save([...$data, 'group_id' => $groupId]);

        /* Another group: who waited for the old one waits no more. */
        if ($before !== $groupId) {
            $this->drivers->dropOtherGroups($groupId);
        }

        return response()->json([
            'message' => __('driver_check.drivers_group.messages.saved'),
        ]);
    }

    /**
     * Every driver found in Telegram and not in the queue yet goes into it;
     * the run starts (one going on picks them up).
     */
    public function all(): JsonResponse
    {
        if ($this->settings->group() === null) {
            return $this->noGroup();
        }

        $made = $this->drivers->queueAll();
        $this->settings->resume();
        $this->start();

        return response()->json([
            'message' => __('driver_check.drivers_group.messages.all_queued', ['n' => $made]),
            'queued' => $made,
        ]);
    }

    /**
     * "Continue": a stop from the panel is lifted, and the queue as it is
     * starts now, not at the next minute.
     */
    public function run(): JsonResponse
    {
        if ($this->settings->group() === null) {
            return $this->noGroup();
        }

        $resumed = $this->settings->resume();

        if (TelegramProcessLock::isHeld(TelegramDriverGroupAddCommand::LOCK_NAME)) {
            return response()->json(['message' => __('driver_check.drivers_group.messages.already_running')], 409);
        }

        if (! $this->drivers->hasPending()) {
            return $resumed
                ? response()->json(['message' => __('driver_check.drivers_group.messages.resumed_empty')])
                : response()->json(['message' => __('driver_check.drivers_group.messages.nothing_waiting')], 422);
        }

        $this->start();

        return response()->json(['message' => __('driver_check.drivers_group.messages.run_queued')]);
    }

    /**
     * Whoever the group did not take goes into the queue again.
     */
    public function retry(): JsonResponse
    {
        if ($this->settings->group() === null) {
            return $this->noGroup();
        }

        $back = $this->drivers->retry();

        if ($back > 0) {
            $this->settings->resume();
            $this->start();
        }

        return response()->json([
            'message' => __('driver_check.drivers_group.messages.retry_queued', ['n' => $back]),
        ]);
    }

    /**
     * "Stop": the run going on finishes the driver in hand and ends, and
     * the scheduler starts no new one until "Continue" - with a run every
     * minute a stop of the run alone would last a minute.
     */
    public function stop(): JsonResponse
    {
        $this->settings->pause();

        if (! TelegramProcessLock::isHeld(TelegramDriverGroupAddCommand::LOCK_NAME)) {
            return response()->json(['message' => __('driver_check.drivers_group.messages.paused')]);
        }

        $now = (array) TelegramSetting::get(DriverGroupService::NOW_KEY, []);
        $runId = is_string($now['run_id'] ?? null) && ($now['finished_at'] ?? null) === null ? $now['run_id'] : null;

        DriverGroupService::requestStop($runId);

        return response()->json(['message' => __('driver_check.drivers_group.messages.stop_queued')]);
    }

    /**
     * Every account with drivers of the group to add, and every one that
     * tried today: how many wait for it, how many it tried and added today,
     * how it stands.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(TelegramInviteGroup $group, array $config): array
    {
        $waiting = TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->where('status', TelegramDriverGroupAdd::STATUS_PENDING)
            ->whereNotNull('resolver_account_id')
            ->selectRaw('resolver_account_id, COUNT(*) as total')
            ->groupBy('resolver_account_id')
            ->pluck('total', 'resolver_account_id');

        $today = TelegramDriverGroupAdd::query()
            ->whereNotNull('telegram_account_id')
            ->where('attempted_at', '>=', now()->startOfDay())
            ->selectRaw('telegram_account_id, COUNT(*) as tried, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as added', [TelegramDriverGroupAdd::STATUS_INVITED])
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        $ids = collect([...$waiting->keys(), ...$today->keys()])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($ids === []) {
            return [];
        }

        $states = $this->drivers->states($ids, $group);
        $phones = TelegramAccount::query()->whereKey($ids)->pluck('phone', 'id');

        return collect($ids)
            ->map(fn (int $id) => [
                'id' => $id,
                'phone' => $phones[$id] ?? null,
                'waiting' => (int) ($waiting[$id] ?? 0),
                'tried_today' => (int) ($today->get($id)?->tried ?? 0),
                'added_today' => (int) ($today->get($id)?->added ?? 0),
                'cap' => (int) $config['per_account_day'],
                ...($states[$id] ?? ['state' => 'ready', 'until' => null, 'reason' => null]),
            ])
            ->sortByDesc(fn (array $row) => [$row['waiting'], $row['tried_today']])
            ->values()
            ->all();
    }

    private function start(): void
    {
        if (! TelegramProcessLock::isHeld(TelegramDriverGroupAddCommand::LOCK_NAME)) {
            dispatch((new StartDriverGroupAddJob)->onQueue('telegram'));
        }
    }

    private function noGroup(): JsonResponse
    {
        return response()->json(['message' => __('driver_check.drivers_group.messages.no_group')], 422);
    }

    /**
     * What the run does now, or how the last one ended.
     *
     * @return array<string, mixed>|null
     */
    private static function now(bool $running, bool $paused): ?array
    {
        $now = TelegramSetting::get(DriverGroupService::NOW_KEY);

        if (! is_array($now)) {
            return null;
        }

        $stop = TelegramSetting::get(DriverGroupService::STOP_KEY);

        return [
            ...$now,
            'stop_asked' => $running && ($paused || $stop === '*' || ($stop !== null && $stop === ($now['run_id'] ?? null))),
            /* The run died without saying it finished. */
            'broken' => ! $running && ($now['finished_at'] ?? null) === null,
        ];
    }
}
