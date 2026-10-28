<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\ContactSyncSettings;
use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The daily report of the contact sync, for the monitoring section:
 * did the scheduled run happen, how it ended, and what each day added.
 *
 * Everything comes from what the sync already writes (its log and the
 * contacts table); nothing here touches the sync itself. Access is the
 * `role:driverCheck,superadmin` middleware of the route group.
 */
final class ContactSyncReportController extends Controller
{
    public function __construct(private readonly ContactSyncSettings $settings) {}

    /** How long past the scheduled minute a run may still be on its way. */
    private const START_GRACE_MINUTES = 20;

    public function show(Request $request): JsonResponse
    {
        $days = min(60, max(3, $request->integer('days', 14)));

        return response()->json(['data' => [
            'schedule' => $this->schedule(),
            'days' => $this->days($days),
            'accounts_today' => $this->accountsToday(),
            'groups' => TelegramScrapeGroup::query()->forContacts()->orderBy('id')->get(['id', 'title', 'link', 'is_active']),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(): array
    {
        $settings = $this->settings->current();
        $at = $settings['schedule_at'];
        [$hour, $minute] = array_map('intval', explode(':', $at) + [0, 0]);

        $today = now()->copy()->setTime($hour, $minute);
        $next = $today->isFuture() ? $today : $today->copy()->addDay();

        /* The sync's own runs: a group's "fetch people" is logged with its group and is not one. */
        $lastStarted = TelegramContactSyncLog::query()->where('event', 'started')->whereNull('group_id')->latest('id')->first();
        $lastFinished = TelegramContactSyncLog::query()->where('event', 'finished')->whereNull('group_id')->latest('id')->first();

        $running = TelegramProcessLock::isHeld(TelegramSyncContactsCommand::LOCK_NAME);

        $finishedThatRun = $lastStarted && $lastFinished && $lastFinished->run_id === $lastStarted->run_id;

        /*
         * ok          - the last run reached its end
         * running     - it is going now
         * interrupted - it began and never ended, and nothing holds the lock
         * never       - no run yet
         * Then, apart from the run: `overdue` says today's scheduled time
         * has passed and no run began - the scheduler (cron) is not firing,
         * or the command could not start.
         */
        $status = match (true) {
            $running => 'running',
            $lastStarted === null => 'never',
            $finishedThatRun => 'ok',
            default => 'interrupted',
        };

        $startedToday = $lastStarted && $lastStarted->created_at->isToday();
        $overdue = $settings['enabled']
            && ! $running
            && ! $startedToday
            && $today->copy()->addMinutes(self::START_GRACE_MINUTES)->isPast();

        return [
            'at' => $at,
            'enabled' => $settings['enabled'],
            'next_run_at' => $next,
            'status' => $status,
            'overdue' => $overdue,
            'running' => $running,
            'daily_limit' => $settings['daily_limit'],
            'last_started_at' => $lastStarted?->created_at,
            'last_finished_at' => $finishedThatRun ? $lastFinished->created_at : null,
            'last_duration_seconds' => $finishedThatRun
                ? (int) $lastStarted->created_at->diffInSeconds($lastFinished->created_at, true)
                : null,
            'last_result' => $finishedThatRun ? $lastFinished->context : null,
            'last_message' => $finishedThatRun ? $lastFinished->message : $lastStarted?->message,
        ];
    }

    /**
     * One row per day, newest first, empty days included so a missed run
     * is visible as a hole rather than as a missing line.
     *
     * @return list<array<string, mixed>>
     */
    private function days(int $days): array
    {
        $from = now()->startOfDay()->subDays($days - 1);

        $events = TelegramContactSyncLog::query()
            ->where('created_at', '>=', $from)
            ->whereIn('event', ['started', 'added', 'failed', 'skipped', 'flood', 'finished'])
            ->get(['event', 'context', 'created_at'])
            ->groupBy(fn (TelegramContactSyncLog $log) => $log->created_at->toDateString());

        /* Counted by the database, not loaded: the people table is shared with Join group and large. */
        $found = TelegramScrapedUser::query()
            ->where('is_bot', false)
            ->where('created_at', '>=', $from)
            ->forContacts()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $rows = [];

        for ($i = 0; $i < $days; $i++) {
            $day = now()->startOfDay()->subDays($i);
            $key = $day->toDateString();
            $logs = $events->get($key, collect());
            $count = fn (string $event): int => $logs->where('event', $event)->count();

            $finished = $logs->where('event', 'finished');

            $rows[] = [
                'date' => $key,
                'runs' => $count('started'),
                'finished' => $finished->count(),
                'accounts' => (int) $finished->sum(fn ($log) => (int) ($log->context['accounts'] ?? 0)),
                'members' => (int) $finished->sum(fn ($log) => (int) ($log->context['members'] ?? 0)),
                'found' => (int) ($found[$key] ?? 0),
                'added' => $count('added'),
                'skipped' => $count('skipped'),
                'failed' => $count('failed'),
                'flood' => $count('flood'),
            ];
        }

        return $rows;
    }

    /**
     * Who added how many today.
     *
     * @return list<array<string, mixed>>
     */
    private function accountsToday(): array
    {
        $added = TelegramAccountContact::query()
            ->where('status', TelegramAccountContact::STATUS_ADDED)
            ->where('added_at', '>=', Carbon::today())
            ->selectRaw('telegram_account_id, COUNT(*) as total')
            ->groupBy('telegram_account_id')
            ->pluck('total', 'telegram_account_id');

        $failed = TelegramContactSyncLog::query()
            ->where('event', 'failed')
            ->where('created_at', '>=', Carbon::today())
            ->whereNotNull('telegram_account_id')
            ->selectRaw('telegram_account_id, COUNT(*) as total')
            ->groupBy('telegram_account_id')
            ->pluck('total', 'telegram_account_id');

        $ids = $added->keys()->merge($failed->keys())->unique()->values();

        return TelegramAccount::query()
            ->whereIn('id', $ids)
            ->get(['id', 'phone', 'username'])
            ->map(fn (TelegramAccount $account) => [
                'id' => $account->id,
                'phone' => $account->phone,
                'username' => $account->username,
                'added' => (int) ($added[$account->id] ?? 0),
                'failed' => (int) ($failed[$account->id] ?? 0),
            ])
            ->sortByDesc('added')
            ->values()
            ->all();
    }
}
