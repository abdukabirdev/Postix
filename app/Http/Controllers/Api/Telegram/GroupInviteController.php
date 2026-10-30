<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteBase;
use App\Application\Telegram\Services\GroupInviteService;
use App\Application\Telegram\Services\GroupInviteSettings;
use App\Console\Commands\Telegram\TelegramGroupInviteCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartGroupInviteJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group invites: the overview, the buttons that start and stop a run, the
 * run log and the settings. The groups, the people, the accounts and the
 * analysis have controllers of their own.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteController extends Controller
{
    public function __construct(
        private readonly GroupInviteSettings $settings,
        private readonly GroupInviteBase $base,
    ) {}

    /**
     * The overview: what runs now, the base in numbers, what the groups
     * got, today, how the accounts stand, the last lines of the run log.
     */
    public function show(): JsonResponse
    {
        $config = $this->settings->current();
        $running = TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME);
        $today = now()->startOfDay();

        /* Archived groups are left out of the overview; their rows stay counted on the groups page. */
        $byStatus = TelegramGroupInvite::query()
            ->selectRaw('invite_group_id, status, COUNT(*) as total')
            ->groupBy('invite_group_id', 'status')
            ->get()
            ->groupBy('invite_group_id');

        $groups = TelegramInviteGroup::query()
            ->orderBy('id')
            ->get()
            ->map(fn (TelegramInviteGroup $group) => [
                'id' => $group->id,
                'title' => $group->title ?: $group->link,
                'link' => $group->link,
                'is_active' => $group->is_active,
                'last_error' => $group->last_error,
                ...GroupInviteGroupController::counts($byStatus->get($group->id, collect())->pluck('total', 'status')->all()),
            ]);

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->keyBy('telegram_account_id');

        $accounts = TelegramAccount::query()->where('is_authorized', true)->pluck('id');
        $taking = $accounts->reject(fn (int $id) => in_array($id, $config['excluded_accounts'], true));

        $lastLeft = TelegramGroupInviteLog::query()
            ->where('event', 'finished')
            ->where('context->left_check', true)
            ->latest('id')
            ->first();

        $latest = TelegramGroupInviteLog::query()->where('event', 'started')->latest('id')->value('run_id');

        $current = TelegramInviteGroup::current();

        return response()->json([
            'running' => $running,
            'now' => self::now($running),
            'base' => $this->base->cachedCounts($config),
            'added' => [
                'total' => (int) $groups->sum('added'),
                'still' => (int) $groups->sum('invited'),
                'left' => (int) $groups->sum('left'),
                'not_added' => (int) $groups->sum('not_added'),
                'failed' => (int) $groups->sum('failed'),
                'already' => (int) $groups->sum('already'),
                'sent' => (int) $groups->sum('sent'),
            ],
            'today' => [
                'added' => TelegramGroupInvite::query()
                    ->whereIn('status', TelegramGroupInvite::ADDED)
                    ->where('attempted_at', '>=', $today)
                    ->count(),
                'calls' => array_sum(TelegramGroupInviteLog::callsToday()),
                'lookups' => TelegramGroupInvite::query()
                    ->where('via', TelegramGroupInvite::VIA_USERNAME)
                    ->whereIn('status', TelegramGroupInvite::SETTLED)
                    ->where('attempted_at', '>=', $today)
                    ->count(),
                'stops' => TelegramGroupInviteLog::query()
                    ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
                    ->where('created_at', '>=', $today)
                    ->count(),
            ],
            'groups' => $groups->values(),
            /* The one group people are added into now, and who is still left to try for it. */
            'current' => $current ? [
                ...($groups->firstWhere('id', $current->id) ?? []),
                'queue' => $this->base->queue($current, $config),
            ] : null,
            'accounts' => [
                'total' => $accounts->count(),
                'taking_part' => $taking->count(),
                'resting' => $taking->filter(fn (int $id) => self::future(((array) ($states->get($id)?->meta ?? []))['rest_until'] ?? null) !== null)->count(),
                'held' => $taking->filter(fn (int $id) => $states->has($id) && ! $states->get($id)->is_available)->count(),
            ],
            'settings' => $config,
            'last_left_check' => $lastLeft ? [
                'at' => $lastLeft->created_at,
                'left' => (int) ($lastLeft->context['left'] ?? 0),
                'stayed' => (int) ($lastLeft->context['stayed'] ?? 0),
                'stopped' => (bool) ($lastLeft->context['stopped'] ?? false),
            ] : null,
            'recent' => $latest !== null ? self::lines($latest, 8) : [],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'settings' => $this->settings->current(),
            'saved' => $this->settings->isSaved(),
            'limits' => GroupInviteSettings::LIMITS,
            'audiences' => GroupInviteSettings::AUDIENCES,
            'defaults' => $this->settings->defaults(),
        ]);
    }

    /**
     * The settings page leaves the accounts out: who takes part is set on
     * the accounts page, and stays as it is when absent here.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        [$batchMin, $batchMax] = GroupInviteSettings::LIMITS['batch_size'];
        [$timesMin, $timesMax] = GroupInviteSettings::LIMITS['batches_per_day'];
        [$delayMin, $delayMax] = GroupInviteSettings::LIMITS['delay_min'];
        [$usernamesMin, $usernamesMax] = GroupInviteSettings::LIMITS['usernames_per_day'];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'batch_size' => ['required', 'integer', "min:{$batchMin}", "max:{$batchMax}"],
            'batches_per_day' => ['required', 'integer', "min:{$timesMin}", "max:{$timesMax}"],
            'delay_min' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}"],
            'delay_max' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}", 'gte:delay_min'],
            'use_usernames' => ['required', 'boolean'],
            'usernames_per_day' => ['required', 'integer', "min:{$usernamesMin}", "max:{$usernamesMax}"],
            'audience' => ['nullable', 'in:' . implode(',', GroupInviteSettings::AUDIENCES)],
            'excluded_accounts' => ['nullable', 'array'],
            'excluded_accounts.*' => ['integer', 'exists:telegram_accounts,id'],
        ]);

        $current = $this->settings->current();

        return response()->json([
            'settings' => $this->settings->save([
                ...$current,
                ...array_filter($data, fn ($value) => $value !== null),
                'excluded_accounts' => $request->has('excluded_accounts')
                    ? ($data['excluded_accounts'] ?? [])
                    : $current['excluded_accounts'],
            ]),
            'saved' => true,
            'message' => __('driver_check.invites.messages.settings_saved'),
        ]);
    }

    public function run(): JsonResponse
    {
        return $this->start(new StartGroupInviteJob, 'run_queued');
    }

    /**
     * Look the people already sent up in their groups, nobody invited.
     */
    public function check(): JsonResponse
    {
        return $this->start(new StartGroupInviteJob(check: true), 'check_queued');
    }

    /**
     * "Who left": everybody added is looked up in the group again, nobody
     * invited. Pressed by hand, once a week or so.
     */
    public function checkLeft(): JsonResponse
    {
        return $this->start(new StartGroupInviteJob(left: true), 'left_queued');
    }

    /**
     * Ask the run to stop: it finishes the step in hand, gives back the
     * people it has taken and ends. The run may be another OS user's
     * process, so it is asked, not killed.
     */
    public function stop(): JsonResponse
    {
        if (! TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME)) {
            return response()->json([
                'message' => __('driver_check.invites.messages.not_running'),
            ], 409);
        }

        $now = (array) TelegramSetting::get(GroupInviteService::NOW_KEY, []);
        $runId = is_string($now['run_id'] ?? null) && ($now['finished_at'] ?? null) === null ? $now['run_id'] : null;

        GroupInviteService::requestStop($runId);

        TelegramGroupInviteLog::query()->create([
            'run_id' => $runId ?? '',
            'event' => TelegramGroupInviteLog::EVENT_STOP,
            'message' => 'Stop asked from the panel',
            'context' => ['user_id' => auth()->id()],
        ]);

        return response()->json([
            'message' => __('driver_check.invites.messages.stop_queued'),
        ]);
    }

    /**
     * One run's log, newest line first, with the last runs to pick from.
     * Without a run id: the latest run.
     */
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id' => ['nullable', 'string', 'max:36'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:1000'],
        ]);

        $starts = TelegramGroupInviteLog::query()
            ->where('event', 'started')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'run_id', 'context', 'created_at']);

        $finished = TelegramGroupInviteLog::query()
            ->where('event', 'finished')
            ->whereIn('run_id', $starts->pluck('run_id'))
            ->get(['run_id', 'context', 'created_at'])
            ->keyBy('run_id');

        $runs = $starts->map(fn (TelegramGroupInviteLog $start) => [
            'run_id' => $start->run_id,
            'started_at' => $start->created_at,
            'finished_at' => $finished->get($start->run_id)?->created_at,
            'check_only' => (bool) ($start->context['check_only'] ?? false),
            'left_check' => (bool) ($start->context['left_check'] ?? false),
            'trigger' => $start->context['trigger'] ?? 'manual',
            'stopped' => (bool) ($finished->get($start->run_id)?->context['stopped'] ?? false),
            'totals' => $finished->get($start->run_id)?->context,
        ])->values();

        $runId = $data['run_id'] ?? $starts->first()?->run_id;

        return response()->json([
            'run_id' => $runId,
            'runs' => $runs,
            'lines' => $runId === null ? [] : self::lines($runId, (int) ($data['limit'] ?? 400)),
        ]);
    }

    private function start(StartGroupInviteJob $job, string $message): JsonResponse
    {
        if (TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME)) {
            return response()->json([
                'message' => __('driver_check.invites.messages.already_running'),
            ], 409);
        }

        dispatch($job->onQueue('telegram'));

        return response()->json([
            'message' => __("driver_check.invites.messages.{$message}"),
        ]);
    }

    /**
     * A run's lines, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(string $runId, int $limit): array
    {
        return TelegramGroupInviteLog::query()
            ->where('run_id', $runId)
            ->with(['account:id,phone', 'group:id,title,link'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (TelegramGroupInviteLog $line) => [
                'id' => $line->id,
                'event' => $line->event,
                'message' => $line->message,
                'context' => $line->context,
                'created_at' => $line->created_at,
                'account' => $line->account?->phone,
                'group' => $line->group ? ($line->group->title ?: $line->group->link) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * What the run is doing now, as it last wrote it; or how the last one
     * ended. silent_for: seconds since it last said anything - a live run
     * says something at least every few seconds, so minutes of silence is a
     * run that hangs.
     *
     * @return array<string, mixed>|null
     */
    private static function now(bool $running): ?array
    {
        $now = TelegramSetting::get(GroupInviteService::NOW_KEY);

        if (! is_array($now) || ! isset($now['run_id'])) {
            return null;
        }

        $heartbeat = is_string($now['heartbeat_at'] ?? null) ? strtotime($now['heartbeat_at']) : false;
        $asked = TelegramSetting::get(GroupInviteService::STOP_KEY);

        return [
            ...$now,
            'running' => $running && ($now['finished_at'] ?? null) === null,
            'silent_for' => $heartbeat !== false ? max(0, now()->timestamp - $heartbeat) : null,
            'stop_asked' => $running && $asked !== null && ($asked === '*' || $asked === $now['run_id']),
        ];
    }

    /**
     * An ISO time still ahead, or null.
     */
    public static function future(mixed $at): ?string
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        $time = strtotime($at);

        return $time !== false && $time > now()->timestamp ? $at : null;
    }
}
