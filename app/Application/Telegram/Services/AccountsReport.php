<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\AccountAge;
use App\Application\Telegram\Support\PhoneCountry;
use App\Console\Commands\Telegram\TelegramDriverGroupAddCommand;
use App\Console\Commands\Telegram\TelegramGroupInviteCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramDriverGroupAdd;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where every account stands, for the developer: which process holds it
 * now, which rests it, and why the invite run would not take it this
 * minute - the same checks the run makes, in the same order - and the
 * drivers into a group: the queue, who waits for which account, what the
 * runs did day by day. Read only - nothing here writes, not even an
 * account's meta - and without phone numbers or tokens.
 */
final class AccountsReport
{
    /** A busy mark older than this was most likely left by a process that died. Hours. */
    private const STALE_BUSY_HOURS = 6;

    private const RECENT = 60;

    /** @var array<string, int> phone => account id, to name accounts in the run lines without their numbers */
    private array $phoneIds = [];

    public function __construct(
        private readonly GroupInviteSettings $inviteSettings,
        private readonly GroupInviteService $invites,
        private readonly DriverGroupSettings $driverSettings,
        private readonly DriverGroupService $drivers,
    ) {}

    /**
     * @param bool $people also count each account's contacts the current invite group has not tried (one heavier query)
     *
     * @return array<string, mixed>
     */
    public function build(int $days = 3, bool $people = false): array
    {
        $from = now()->subDays(max(1, $days) - 1)->startOfDay();
        $inviteConfig = $this->inviteSettings->current();
        $inviteGroup = TelegramInviteGroup::current();
        $driverConfig = $this->driverSettings->current();
        $driverGroup = $this->driverSettings->group();

        $this->phoneIds = TelegramAccount::query()->pluck('id', 'phone')->map(fn ($id) => (int) $id)->all();

        $driverQueue = $driverGroup !== null ? $this->driverQueue($driverGroup) : [];
        $accounts = $this->accounts($inviteConfig, $inviteGroup, $driverQueue, $people);

        return [
            'generated_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'period' => ['from' => $from->toIso8601String(), 'days' => $days],
            'summary' => $this->summary($accounts, $driverQueue),
            'invites' => [
                'running' => TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME),
                'now' => $this->now(GroupInviteService::NOW_KEY),
                'group' => $inviteGroup ? ['id' => $inviteGroup->id, 'title' => $inviteGroup->title, 'link' => $inviteGroup->link] : null,
                'audience' => $inviteConfig['audience'],
                'use_usernames' => (bool) $inviteConfig['use_usernames'],
                'usernames_per_day' => (int) $inviteConfig['usernames_per_day'],
                'excluded_accounts' => $inviteConfig['excluded_accounts'],
            ],
            'drivers' => $this->drivers($driverConfig, $driverGroup, $driverQueue, $from),
            'accounts' => $accounts,
        ];
    }

    /**
     * Every account: its flags, every process row it has, why the invite
     * run would pass it by now, how the drivers into a group see it.
     *
     * @param array<string, mixed> $inviteConfig
     * @param array<int, array{waiting: int, state: array<string, mixed>}> $driverQueue
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(array $inviteConfig, ?TelegramInviteGroup $inviteGroup, array $driverQueue, bool $people): array
    {
        $states = TelegramAccountProcess::query()->get()->groupBy('telegram_account_id');
        $calls = TelegramGroupInviteLog::callsToday();
        $listeners = array_flip(ListenerAccounts::ids());
        $joining = array_flip(GroupJoinSettings::dedicatedIds());
        $excluded = array_flip(array_map('intval', (array) $inviteConfig['excluded_accounts']));
        $untried = $people && $inviteGroup !== null ? $this->untriedContacts($inviteGroup, $inviteConfig) : null;

        $driversToday = TelegramDriverGroupAdd::query()
            ->whereNotNull('telegram_account_id')
            ->where('attempted_at', '>=', now()->startOfDay())
            ->selectRaw('telegram_account_id, COUNT(*) as tried, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as added', [TelegramDriverGroupAdd::STATUS_INVITED])
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        return TelegramAccount::query()
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($states, $calls, $listeners, $joining, $excluded, $untried, $inviteConfig, $driverQueue, $driversToday) {
                $mine = $states->get($account->id) ?? collect();
                $processes = $mine->mapWithKeys(fn (TelegramAccountProcess $state) => [self::processOf($state) => self::state($state)])->all();
                $invite = $processes[ProcessEnum::GroupInvite->value] ?? null;

                $meta = $this->invites->meta($account);
                $warm = GroupInviteSettings::warmCap($this->invites->inviteDay($account, $meta), $inviteConfig);
                $cap = isset($meta['cap']) ? max(1, min($warm, (int) $meta['cap'])) : $warm;
                $callsToday = $calls[$account->id] ?? 0;

                /* Held by another process now: the invite run passes it by, however old the mark. */
                $busyIn = collect($processes)
                    ->filter(fn (array $state, string $process) => $state['busy'] && $process !== ProcessEnum::GroupInvite->value)
                    ->map(fn (array $state) => $state['busy_at'])
                    ->all();

                /* The invite run's own checks, in its order: the first one is why it passes the account by. */
                $blockers = array_values(array_filter([
                    ! $account->is_authorized ? 'not_authorized' : null,
                    (string) $account->session_path === '' ? 'no_session' : null,
                    isset($listeners[$account->id]) ? 'listener' : null,
                    isset($joining[$account->id]) ? 'group_join' : null,
                    isset($excluded[$account->id]) ? 'excluded' : null,
                    $invite !== null && ! $invite['available'] ? 'disabled' . ($invite['disabled_reason'] ? ':' . $invite['disabled_reason'] : '') : null,
                    $invite !== null && $invite['busy'] ? 'busy:group_invite' : null,
                    $invite !== null && $invite['consecutive_failures'] >= TelegramAccountProcessService::MAX_CONSECUTIVE_FAILURES ? 'failures' : null,
                    ...array_map(fn (string $process) => 'busy:' . $process, array_keys($busyIn)),
                    $invite !== null && $invite['rest_until'] !== null ? 'resting:' . ($invite['rest_kind'] ?? '') : null,
                    $callsToday >= $cap ? 'cap' : null,
                    $untried !== null && ($untried[$account->id] ?? 0) === 0 ? 'no_contacts_left' : null,
                ]));

                $driver = $driverQueue[$account->id] ?? null;
                $today = $driversToday->get($account->id);

                return [
                    'id' => $account->id,
                    'country' => PhoneCountry::of($account->phone)['code'] ?? null,
                    'age' => AccountAge::band($account->telegram_user_id),
                    'authorized' => (bool) $account->is_authorized,
                    'has_session' => (string) $account->session_path !== '',
                    'status' => $account->status,
                    'last_error' => $account->last_error !== null ? $this->mask(mb_substr((string) $account->last_error, 0, 200)) : null,
                    'listener' => isset($listeners[$account->id]),
                    'group_join' => isset($joining[$account->id]),
                    'processes' => $processes,
                    'invite' => [
                        'free_now' => $blockers === [],
                        'blockers' => $blockers,
                        'calls_today' => $callsToday,
                        'daily_cap' => $cap,
                        'contacts_untried' => $untried !== null ? ($untried[$account->id] ?? 0) : null,
                    ],
                    'drivers' => [
                        'waiting' => $driver['waiting'] ?? 0,
                        'state' => $driver['state'] ?? null,
                        'tried_today' => (int) ($today?->tried ?? 0),
                        'added_today' => (int) ($today?->added ?? 0),
                    ],
                ];
            })
            ->all();
    }

    /**
     * One process row as the report shows it.
     *
     * @return array<string, mixed>
     */
    private static function state(TelegramAccountProcess $state): array
    {
        $meta = (array) ($state->meta ?? []);
        $restUntil = is_string($meta['rest_until'] ?? null) && strtotime($meta['rest_until']) > time() ? $meta['rest_until'] : null;

        return [
            'available' => (bool) $state->is_available,
            'busy' => (bool) $state->is_busy,
            'busy_at' => $state->busy_at?->toIso8601String(),
            'busy_minutes' => $state->is_busy && $state->busy_at !== null ? (int) $state->busy_at->diffInMinutes(now(), true) : null,
            'successes' => (int) $state->successes,
            'failures' => (int) $state->failures,
            'consecutive_failures' => (int) $state->consecutive_failures,
            'disabled_reason' => $state->disabled_reason,
            'rest_until' => $restUntil,
            'rest_kind' => $restUntil !== null ? ($meta['rest_kind'] ?? null) : null,
            'rest_reason' => $restUntil !== null ? ($meta['rest_reason'] ?? null) : null,
        ];
    }

    /**
     * The counts that answer "who can work now": per process how many are
     * busy (and how many of those for hours - a dead process's mark),
     * resting, switched off; how many the invite run could take, and what
     * stops the rest; the drivers' queue by the state of its accounts.
     *
     * @param list<array<string, mixed>> $accounts
     * @param array<int, array{waiting: int, state: array<string, mixed>}> $driverQueue
     *
     * @return array<string, mixed>
     */
    private function summary(array $accounts, array $driverQueue): array
    {
        $processes = [];

        foreach ($accounts as $account) {
            foreach ($account['processes'] as $process => $state) {
                $processes[$process] ??= ['rows' => 0, 'busy' => 0, 'busy_stale' => 0, 'resting' => 0, 'unavailable' => 0, 'failing' => 0];
                $processes[$process]['rows']++;
                $processes[$process]['busy'] += $state['busy'] ? 1 : 0;
                $processes[$process]['busy_stale'] += $state['busy'] && ($state['busy_minutes'] ?? 0) >= self::STALE_BUSY_HOURS * 60 ? 1 : 0;
                $processes[$process]['resting'] += $state['rest_until'] !== null ? 1 : 0;
                $processes[$process]['unavailable'] += $state['available'] ? 0 : 1;
                $processes[$process]['failing'] += $state['consecutive_failures'] >= TelegramAccountProcessService::MAX_CONSECUTIVE_FAILURES ? 1 : 0;
            }
        }

        ksort($processes);

        /* "disabled:<the panel's words>" counted as one. */
        $first = collect($accounts)
            ->reject(fn (array $account) => $account['invite']['free_now'])
            ->countBy(fn (array $account) => str_starts_with($account['invite']['blockers'][0], 'disabled') ? 'disabled' : $account['invite']['blockers'][0])
            ->sortDesc()
            ->all();

        return [
            'accounts' => count($accounts),
            'usable' => collect($accounts)->filter(fn (array $account) => $account['authorized'] && $account['has_session'])->count(),
            'processes' => $processes,
            'invite' => [
                'free_now' => collect($accounts)->filter(fn (array $account) => $account['invite']['free_now'])->count(),
                /* Each account counted once, by the first thing that stops it. */
                'stopped_by' => $first,
            ],
            'drivers' => [
                'accounts_with_queue' => count($driverQueue),
                'waiting' => array_sum(array_column($driverQueue, 'waiting')),
                'by_state' => collect($driverQueue)
                    ->groupBy(fn (array $row) => $row['state']['state'] ?? 'ready')
                    ->map(fn (Collection $rows) => ['accounts' => $rows->count(), 'waiting' => $rows->sum('waiting')])
                    ->all(),
            ],
        ];
    }

    /**
     * The drivers waiting for each account that found them, and how the
     * drivers' run sees that account - the page's own states.
     *
     * @return array<int, array{waiting: int, state: array<string, mixed>}>
     */
    private function driverQueue(TelegramInviteGroup $group): array
    {
        $waiting = TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->where('status', TelegramDriverGroupAdd::STATUS_PENDING)
            ->selectRaw('COALESCE(resolver_account_id, 0) as account_id, COUNT(*) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id')
            ->map(fn ($total) => (int) $total)
            ->all();

        $ids = array_values(array_filter(array_map('intval', array_keys($waiting))));
        $states = $this->drivers->states($ids, $group);

        $out = [];

        foreach ($waiting as $id => $total) {
            $out[(int) $id] = [
                'waiting' => $total,
                'state' => (int) $id === 0 ? ['state' => 'no_resolver', 'until' => null, 'reason' => null] : ($states[(int) $id] ?? ['state' => 'ready', 'until' => null, 'reason' => null]),
            ];
        }

        return $out;
    }

    /**
     * The drivers into a group: the settings, the run now, the queue, who
     * tried how many day by day, the errors, the latest tries.
     *
     * @param array<string, mixed> $config
     * @param array<int, array{waiting: int, state: array<string, mixed>}> $queue
     *
     * @return array<string, mixed>
     */
    private function drivers(array $config, ?TelegramInviteGroup $group, array $queue, \Illuminate\Support\Carbon $from): array
    {
        $out = [
            'settings' => $config,
            'running' => TelegramProcessLock::isHeld(TelegramDriverGroupAddCommand::LOCK_NAME),
            'stop_asked' => TelegramSetting::get(DriverGroupService::STOP_KEY),
            'now' => $this->now(DriverGroupService::NOW_KEY),
            'group' => $group ? ['id' => $group->id, 'title' => $group->title, 'link' => $group->link, 'chat_id' => $group->telegram_chat_id] : null,
        ];

        if ($group === null) {
            return $out;
        }

        $rows = fn () => TelegramDriverGroupAdd::query()->where('invite_group_id', $group->id);

        return [
            ...$out,
            'counts' => $rows()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total) => (int) $total)
                ->all(),
            'queue' => collect($queue)
                ->map(fn (array $row, int $id) => ['account_id' => $id ?: null, ...$row])
                ->sortByDesc('waiting')
                ->values()
                ->all(),
            'days' => $rows()
                ->where('attempted_at', '>=', $from)
                ->selectRaw('DATE(attempted_at) as day, telegram_account_id, status, COUNT(*) as total')
                ->groupBy('day', 'telegram_account_id', 'status')
                ->toBase()
                ->get()
                ->groupBy(fn ($row) => $row->day . '#' . $row->telegram_account_id)
                ->map(fn (Collection $mine) => [
                    'day' => $mine->first()->day,
                    'account_id' => $mine->first()->telegram_account_id !== null ? (int) $mine->first()->telegram_account_id : null,
                    'tried' => (int) $mine->sum('total'),
                    ...$mine->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
                ])
                ->values()
                ->all(),
            'errors' => $rows()
                ->where('attempted_at', '>=', $from)
                ->whereNotNull('error')
                ->selectRaw('status, error, COUNT(*) as total')
                ->groupBy('status', 'error')
                ->orderByDesc('total')
                ->toBase()
                ->get()
                ->map(fn ($row) => ['status' => $row->status, 'error' => $row->error, 'total' => (int) $row->total])
                ->all(),
            'recent' => $rows()
                ->whereNotNull('attempted_at')
                ->latest('attempted_at')
                ->limit(self::RECENT)
                ->get()
                ->map(fn (TelegramDriverGroupAdd $row) => [
                    'id' => $row->id,
                    'at' => $row->attempted_at?->toIso8601String(),
                    'account_id' => $row->telegram_account_id,
                    'resolver_id' => $row->resolver_account_id,
                    're_resolved' => $row->re_resolved,
                    'source' => $row->source,
                    'status' => $row->status,
                    'error' => $row->error,
                    'attempts' => $row->attempts,
                ])
                ->all(),
        ];
    }

    /**
     * Each account's contacts the group has not tried yet - what the invite
     * run would still take, the audience minded, the "proven wait for the
     * account that added them" rule aside. One grouped query, not one per
     * account.
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, int>
     */
    private function untriedContacts(TelegramInviteGroup $group, array $config): array
    {
        $own = TelegramAccount::query()->whereNotNull('telegram_user_id')->pluck('telegram_user_id')->all();

        $contacts = DB::table('telegram_account_contacts')
            ->whereIn('status', ['added', 'skipped'])
            ->select('telegram_account_id', 'scraped_user_id')
            ->union(DB::table('telegram_account_contact_links')->select('telegram_account_id', 'scraped_user_id'));

        return DB::query()
            ->fromSub($contacts, 'c')
            ->join('telegram_scraped_users as u', 'u.id', '=', 'c.scraped_user_id')
            ->where('u.is_bot', false)
            ->when(
                $config['audience'] === GroupInviteSettings::AUDIENCE_PROVEN,
                fn ($q) => $q->where('u.can_add_to_group', true),
                fn ($q) => $q->where(fn ($q) => $q->whereNull('u.can_add_to_group')->orWhere('u.can_add_to_group', true)),
            )
            ->when($own !== [], fn ($q) => $q->whereNotIn('u.telegram_user_id', $own))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('telegram_group_invites as gi')
                ->whereColumn('gi.scraped_user_id', 'u.id')
                ->where('gi.invite_group_id', $group->id))
            ->groupBy('c.telegram_account_id')
            ->selectRaw('c.telegram_account_id, COUNT(DISTINCT u.id) as total')
            ->pluck('total', 'telegram_account_id')
            ->mapWithKeys(fn ($total, $id) => [(int) $id => (int) $total])
            ->all();
    }

    /**
     * What a run does now as its page sees it, accounts by their id instead
     * of their number, no drivers' numbers.
     *
     * @return array<string, mixed>|null
     */
    private function now(string $key): ?array
    {
        $now = TelegramSetting::get($key);

        if (! is_array($now)) {
            return null;
        }

        unset($now['account']);

        if (isset($now['lines']) && is_array($now['lines'])) {
            $now['lines'] = array_map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                if (isset($line['account'])) {
                    $line['account_id'] = $this->phoneIds[(string) $line['account']] ?? null;
                    unset($line['account']);
                }

                unset($line['phone']);

                return $line;
            }, $now['lines']);
        }

        return $now;
    }

    /** A phone number that slipped into a text. */
    private function mask(string $text): string
    {
        return (string) preg_replace('/\+?998\d{9}|\+\d{10,15}/', '+***', $text);
    }

    private static function processOf(TelegramAccountProcess $state): string
    {
        return $state->process instanceof ProcessEnum ? $state->process->value : (string) $state->process;
    }
}
