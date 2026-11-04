<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Telegram\TelegramProcessLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How the contact sync went over the last days, for the developer: the
 * runs and how long they took, the contacts added and refused day by day
 * and why, every account's state in the sync (held, resting till when,
 * busy, added today), the groups and the latest log lines. Read only, and
 * without phone numbers.
 */
final class ContactSyncReport
{
    public function __construct(private readonly ContactSyncSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $days = 3, int $lines = 200, ?string $runId = null): array
    {
        $from = now()->subDays(max(1, $days) - 1)->startOfDay();
        $config = $this->settings->current();

        return [
            'generated_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'period' => ['from' => $from->toIso8601String(), 'days' => $days],
            'settings' => [...$config, 'nick_base' => $config['nick_base'] !== '' ? '(set)' : ''],
            'running' => TelegramProcessLock::isHeld(TelegramSyncContactsCommand::LOCK_NAME),
            'now' => ContactSyncService::current(),
            'runs' => $this->runs($from),
            'days' => $this->days($from),
            'refusals' => $this->refusals($from),
            'holds' => $this->holds($from),
            'accounts' => $this->accounts($config),
            'groups' => $this->groups(),
            'log' => $this->log($lines, $runId),
        ];
    }

    /**
     * The sync's runs of the period (a group's "fetch people" aside): when,
     * how long, how many at once, what they came to.
     *
     * @return list<array<string, mixed>>
     */
    private function runs(Carbon $from): array
    {
        $edges = TelegramContactSyncLog::query()
            ->whereIn('event', ['started', 'finished'])
            ->where('created_at', '>=', $from)
            ->orderBy('id')
            ->get(['run_id', 'event', 'group_id', 'context', 'created_at'])
            ->groupBy('run_id');

        return $edges
            ->map(function ($rows, $runId) {
                $start = $rows->firstWhere('event', 'started');
                $end = $rows->firstWhere('event', 'finished');

                if ($start === null || ($start->context['mode'] ?? null) === 'members') {
                    return null;
                }

                $accounts = TelegramContactSyncLog::query()
                    ->where('run_id', $runId)
                    ->whereNotNull('telegram_account_id')
                    ->distinct()
                    ->count('telegram_account_id');

                return [
                    'run_id' => $runId,
                    'trigger' => $start->context['trigger'] ?? null,
                    'parallel' => $start->context['parallel'] ?? 1,
                    'started_at' => $start->created_at?->toIso8601String(),
                    'finished_at' => $end?->created_at?->toIso8601String(),
                    'minutes' => $end ? round($start->created_at->diffInSeconds($end->created_at) / 60, 1) : null,
                    'accounts_in_log' => $accounts,
                    'result' => $end?->context,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Contacts added and refused, day by day.
     *
     * @return array<string, array{added: int, failed: int, skipped: int}>
     */
    private function days(Carbon $from): array
    {
        $rows = DB::table('telegram_account_contacts')
            ->where('updated_at', '>=', $from)
            ->selectRaw('DATE(updated_at) as day, status, COUNT(*) as total')
            ->groupBy('day', 'status')
            ->get();

        return $rows
            ->groupBy('day')
            ->map(fn ($day) => [
                'added' => (int) ($day->firstWhere('status', TelegramAccountContact::STATUS_ADDED)->total ?? 0),
                'failed' => (int) ($day->firstWhere('status', TelegramAccountContact::STATUS_FAILED)->total ?? 0),
                'skipped' => (int) ($day->firstWhere('status', TelegramAccountContact::STATUS_SKIPPED)->total ?? 0),
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * What Telegram said to the people not added, most often first.
     *
     * @return array<string, int>
     */
    private function refusals(Carbon $from): array
    {
        return DB::table('telegram_account_contacts')
            ->where('status', TelegramAccountContact::STATUS_FAILED)
            ->where('updated_at', '>=', $from)
            ->selectRaw('error, COUNT(*) as total')
            ->groupBy('error')
            ->get()
            ->groupBy(fn ($row) => self::mask(mb_substr((string) ($row->error ?? '?'), 0, 80)))
            ->map(fn ($rows) => (int) $rows->sum('total'))
            ->sortDesc()
            ->take(20)
            ->all();
    }

    /**
     * Why accounts were held, and the rests that ended, in the period.
     *
     * @return array<string, int>
     */
    private function holds(Carbon $from): array
    {
        return TelegramContactSyncLog::query()
            ->whereIn('event', ['flood', 'disabled', 'back', 'released'])
            ->where('created_at', '>=', $from)
            ->get(['event', 'message'])
            ->countBy(fn ($row) => in_array($row->event, ['back', 'released'], true)
                ? $row->event
                : $row->event . ': ' . (string) preg_replace('/\d+/', 'N', mb_substr((string) $row->message, 0, 60)))
            ->sortDesc()
            ->all();
    }

    /**
     * Every account and where it stands in the sync now.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(array $config): array
    {
        $states = TelegramAccountProcess::query()->get()->groupBy('telegram_account_id');

        $counts = DB::table('telegram_account_contacts')
            ->selectRaw(
                'telegram_account_id,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as added,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? AND added_at >= ? THEN 1 ELSE 0 END) as today,
                MAX(added_at) as last_added_at',
                [TelegramAccountContact::STATUS_ADDED, TelegramAccountContact::STATUS_FAILED, TelegramAccountContact::STATUS_ADDED, now()->startOfDay()],
            )
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        $listeners = ListenerAccounts::ids();
        $joining = GroupJoinSettings::dedicatedIds();

        return TelegramAccount::query()
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($states, $counts, $config, $listeners, $joining) {
                $mine = $states->get($account->id) ?? collect();
                $sync = $mine->first(fn ($state) => self::processOf($state) === ProcessEnum::ContactSync->value);
                $count = $counts->get($account->id);

                return [
                    'id' => $account->id,
                    'authorized' => (bool) $account->is_authorized,
                    'has_session' => (string) $account->session_path !== '',
                    'status' => $account->status,
                    'excluded' => in_array($account->id, $config['excluded_accounts'], true),
                    'listener' => in_array($account->id, $listeners, true),
                    'group_join' => in_array($account->id, $joining, true),
                    'busy_in' => $mine->filter(fn ($state) => $state->is_busy)->map(fn ($state) => self::processOf($state))->values()->all(),
                    'sync_state' => $sync ? [
                        'is_available' => (bool) $sync->is_available,
                        'is_busy' => (bool) $sync->is_busy,
                        'busy_at' => $sync->busy_at?->toIso8601String(),
                        'consecutive_failures' => (int) $sync->consecutive_failures,
                        'disabled_reason' => $sync->disabled_reason,
                        'disabled_at' => $sync->disabled_at?->toIso8601String(),
                        'rest_until' => ContactSyncService::restEnd($sync)?->toIso8601String(),
                    ] : null,
                    'added' => (int) ($count->added ?? 0),
                    'failed' => (int) ($count->failed ?? 0),
                    'added_today' => (int) ($count->today ?? 0),
                    'last_added_at' => $count->last_added_at ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groups(): array
    {
        return TelegramScrapeGroup::query()
            ->forContacts()
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (TelegramScrapeGroup $group) => [
                'id' => $group->id,
                'link' => $group->link,
                'title' => $group->title,
                'is_active' => (bool) $group->is_active,
                'members_count' => $group->members_count,
                'in_database' => $group->users_count,
                'last_synced_at' => $group->last_synced_at?->toIso8601String(),
                'last_error' => $group->last_error,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function log(int $lines, ?string $runId): array
    {
        if ($lines <= 0) {
            return [];
        }

        return TelegramContactSyncLog::query()
            ->when($runId !== null, fn ($q) => $q->where('run_id', $runId))
            ->latest('id')
            ->limit($lines)
            ->get()
            ->map(fn (TelegramContactSyncLog $line) => [
                'id' => $line->id,
                'at' => $line->created_at?->toIso8601String(),
                'run_id' => $line->run_id,
                'event' => $line->event,
                'account_id' => $line->telegram_account_id,
                'group_id' => $line->group_id,
                'message' => self::mask((string) $line->message),
                'context' => $line->context,
            ])
            ->all();
    }

    private static function mask(string $text): string
    {
        return (string) preg_replace('/\+?998\d{9}|\+\d{10,15}/', '+***', $text);
    }

    private static function processOf(TelegramAccountProcess $state): string
    {
        return $state->process instanceof ProcessEnum ? $state->process->value : (string) $state->process;
    }
}
