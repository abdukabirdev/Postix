<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Console\Commands\Telegram\TelegramGroupInviteCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How the group invites went over the last days, for the developer: the
 * runs and how long they took, the scheduled starts that never came, the
 * calls hour by hour and account by account, why accounts were stopped,
 * where every account stands now and how many people it can still reach,
 * and the latest log lines. Read only - nothing here writes, not even an
 * account's meta - and without phone numbers or tokens.
 */
final class GroupInviteReport
{
    /** A scheduled run started this close to its time is that time's run, seconds. */
    private const SCHEDULE_SLACK = 180;

    public function __construct(
        private readonly GroupInviteSettings $settings,
        private readonly GroupInviteService $invites,
        private readonly GroupInviteBase $base,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $days = 3, int $lines = 200, ?string $runId = null): array
    {
        $from = now()->subDays(max(1, $days) - 1)->startOfDay();
        $config = $this->settings->current();
        $group = TelegramInviteGroup::current();
        $runs = $this->runs($from);

        return [
            'generated_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'period' => ['from' => $from->toIso8601String(), 'days' => $days],
            'settings' => $config,
            'times' => GroupInviteSettings::times($config),
            'next_run' => $this->settings->nextRun()?->toIso8601String(),
            'running' => TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME),
            'now' => $this->now(),
            'group' => $group ? [
                'id' => $group->id,
                'title' => $group->title,
                'link' => $group->link,
                'members_before' => $group->members_before,
                'members_after' => $group->members_after,
                'members_checked_at' => $group->members_checked_at?->toIso8601String(),
                'queue' => $this->base->queue($group, $config),
                'usernames_untried' => $this->untried($group)->withUsername()->count(),
            ] : null,
            'groups' => $this->groups(),
            'base' => $this->base->counts($config),
            'schedule' => $this->schedule($from, $config, $runs),
            'runs' => $runs->values(),
            'hours' => $this->hours($from),
            'account_days' => $this->accountDays($from),
            'events' => $this->events($from),
            'stops' => $this->stops($from),
            'accounts' => $this->accounts($config, $group),
            'log' => $this->log($lines, $runId),
        ];
    }

    /**
     * What the run is doing now, as the page sees it, without the phone.
     *
     * @return array<string, mixed>|null
     */
    private function now(): ?array
    {
        $now = TelegramSetting::get(GroupInviteService::NOW_KEY);

        if (! is_array($now)) {
            return null;
        }

        unset($now['account']);

        return $now;
    }

    /**
     * Every run of the period: when, what, how long, what it came to, and
     * per account the calls it made, the first and the last.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function runs(Carbon $from): Collection
    {
        $edges = TelegramGroupInviteLog::query()
            ->whereIn('event', ['started', 'finished'])
            ->where('created_at', '>=', $from)
            ->orderBy('id')
            ->get(['run_id', 'event', 'context', 'created_at'])
            ->groupBy('run_id');

        $calls = TelegramGroupInviteLog::query()
            ->where('event', TelegramGroupInviteLog::EVENT_CALL)
            ->where('created_at', '>=', $from)
            ->orderBy('id')
            ->get(['run_id', 'telegram_account_id', 'context', 'created_at'])
            ->groupBy('run_id');

        return $edges->map(function (Collection $rows, string $runId) use ($calls) {
            $start = $rows->firstWhere('event', 'started');
            $end = $rows->firstWhere('event', 'finished');
            $begun = (array) ($start?->context ?? []);

            $accounts = ($calls->get($runId) ?? collect())
                ->groupBy('telegram_account_id')
                ->map(fn (Collection $mine, $accountId) => [
                    'account_id' => (int) $accountId,
                    'calls' => $mine->count(),
                    'added' => (int) $mine->sum(fn ($line) => (int) ($line->context['invited'] ?? 0)),
                    'first' => $mine->first()->created_at?->toIso8601String(),
                    'last' => $mine->last()->created_at?->toIso8601String(),
                ])
                ->values();

            return [
                'run_id' => $runId,
                'started_at' => $start?->created_at?->toIso8601String(),
                'finished_at' => $end?->created_at?->toIso8601String(),
                'minutes' => $start && $end ? round(($end->created_at->timestamp - $start->created_at->timestamp) / 60, 1) : null,
                'trigger' => $begun['trigger'] ?? null,
                'mode' => ! empty($begun['left_check']) ? 'left' : (! empty($begun['check_only']) ? 'check' : 'invite'),
                'started' => $begun,
                'totals' => $end?->context,
                'accounts' => $accounts,
            ];
        });
    }

    /**
     * Every scheduled time of the period that has come: which run it
     * started, or which run was still going and so kept it from starting.
     *
     * @param array<string, mixed>                    $config
     * @param Collection<string, array<string, mixed>> $runs
     *
     * @return list<array{at: string, run_id: string|null, held_by: string|null}>
     */
    private function schedule(Carbon $from, array $config, Collection $runs): array
    {
        $out = [];

        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            foreach (GroupInviteSettings::times($config) as $time) {
                [$hours, $minutes] = array_map('intval', explode(':', $time));
                $at = $day->copy()->setTime($hours, $minutes);

                if ($at->gt(now())) {
                    continue;
                }

                $started = $runs->first(fn (array $run) => $run['trigger'] === 'schedule'
                    && $run['started_at'] !== null
                    && abs(Carbon::parse($run['started_at'])->timestamp - $at->timestamp) <= self::SCHEDULE_SLACK);

                $held = $started ? null : $runs->first(fn (array $run) => $run['started_at'] !== null
                    && Carbon::parse($run['started_at'])->lte($at)
                    && ($run['finished_at'] === null || Carbon::parse($run['finished_at'])->gte($at)));

                $out[] = [
                    'at' => $at->toIso8601String(),
                    'run_id' => $started['run_id'] ?? null,
                    'held_by' => $held['run_id'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * The calls hour by hour: how many, by how many accounts, what came of
     * the people in them.
     *
     * @return list<array<string, mixed>>
     */
    private function hours(Carbon $from): array
    {
        return $this->callLines($from)
            ->groupBy(fn ($line) => $line->created_at->format('Y-m-d H:00'))
            ->map(fn (Collection $lines, string $hour) => [
                'hour' => $hour,
                'accounts' => $lines->pluck('telegram_account_id')->unique()->count(),
                ...self::sums($lines),
            ])
            ->values()
            ->all();
    }

    /**
     * The calls day by day and account by account, with the first and the
     * last call of the day.
     *
     * @return list<array<string, mixed>>
     */
    private function accountDays(Carbon $from): array
    {
        return $this->callLines($from)
            ->groupBy(fn ($line) => $line->created_at->toDateString() . '#' . $line->telegram_account_id)
            ->map(fn (Collection $lines) => [
                'day' => $lines->first()->created_at->toDateString(),
                'account_id' => (int) $lines->first()->telegram_account_id,
                ...self::sums($lines),
                'first' => $lines->first()->created_at->format('H:i'),
                'last' => $lines->last()->created_at->format('H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TelegramGroupInviteLog>
     */
    private function callLines(Carbon $from): Collection
    {
        return TelegramGroupInviteLog::query()
            ->where('event', TelegramGroupInviteLog::EVENT_CALL)
            ->where('created_at', '>=', $from)
            ->orderBy('id')
            ->get(['telegram_account_id', 'context', 'created_at']);
    }

    /**
     * @param Collection<int, TelegramGroupInviteLog> $lines
     *
     * @return array<string, int>
     */
    private static function sums(Collection $lines): array
    {
        $sum = fn (string $key) => (int) $lines->sum(fn ($line) => (int) ($line->context[$key] ?? 0));

        return [
            'calls' => $lines->count(),
            'people' => $sum('size'),
            'added' => $sum('invited'),
            'not_added' => $sum('not_added'),
            'already' => $sum('already'),
            'failed' => $sum('failed'),
            'sent' => $sum('sent'),
            'by_username' => $lines->filter(fn ($line) => ($line->context['via'] ?? null) === TelegramGroupInvite::VIA_USERNAME)->count(),
            'with_error' => $lines->filter(fn ($line) => ! empty($line->context['error']))->count(),
        ];
    }

    /**
     * Everything else the runs wrote, day by day: by event, and by the
     * reason or the code where there is one.
     *
     * @return list<array{day: string, event: string, why: string|null, count: int}>
     */
    private function events(Carbon $from): array
    {
        return TelegramGroupInviteLog::query()
            ->where('event', '!=', TelegramGroupInviteLog::EVENT_CALL)
            ->where('created_at', '>=', $from)
            ->get(['event', 'context', 'created_at'])
            ->groupBy(fn ($line) => $line->created_at->toDateString() . '#' . $line->event . '#' . ($line->context['reason'] ?? $line->context['rpc'] ?? ''))
            ->map(fn (Collection $lines) => [
                'day' => $lines->first()->created_at->toDateString(),
                'event' => $lines->first()->event,
                'why' => $lines->first()->context['reason'] ?? $lines->first()->context['rpc'] ?? null,
                'count' => $lines->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every stop of the period, newest first, with what the account had
     * done before it.
     *
     * @return list<array<string, mixed>>
     */
    private function stops(Carbon $from): array
    {
        return TelegramGroupInviteLog::query()
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->where('created_at', '>=', $from)
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(fn (TelegramGroupInviteLog $line) => [
                'at' => $line->created_at?->toIso8601String(),
                'account_id' => $line->telegram_account_id,
                'group_id' => $line->invite_group_id,
                'event' => $line->event,
                'context' => $line->context,
            ])
            ->all();
    }

    /**
     * Every account: why the run may not take it, how it stands for the
     * invites (rest, cap, day of inviting), what it did today, and how many
     * of its contacts the current group has not tried yet.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(array $config, ?TelegramInviteGroup $group): array
    {
        $states = TelegramAccountProcess::query()->get()->groupBy('telegram_account_id');
        $calls = TelegramGroupInviteLog::callsToday();
        $listeners = ListenerAccounts::ids();
        $joining = GroupJoinSettings::dedicatedIds();

        return TelegramAccount::query()
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($states, $calls, $listeners, $joining, $config, $group) {
                $mine = $states->get($account->id) ?? collect();
                $invite = $mine->first(fn ($state) => self::processOf($state) === ProcessEnum::GroupInvite->value);
                $meta = $this->invites->meta($account);
                $day = $this->invites->inviteDay($account, $meta);
                $warm = GroupInviteSettings::warmCap($day, $config);

                return [
                    'id' => $account->id,
                    'authorized' => (bool) $account->is_authorized,
                    'has_session' => (string) $account->session_path !== '',
                    'status' => $account->status,
                    'last_error' => $account->last_error !== null ? self::mask(mb_substr((string) $account->last_error, 0, 200)) : null,
                    'excluded' => in_array($account->id, $config['excluded_accounts'], true),
                    'listener' => in_array($account->id, $listeners, true),
                    'group_join' => in_array($account->id, $joining, true),
                    /* Other processes holding the session now. */
                    'busy_in' => $mine->filter(fn ($state) => $state->is_busy && self::processOf($state) !== ProcessEnum::GroupInvite->value)
                        ->map(fn ($state) => self::processOf($state))
                        ->values()
                        ->all(),
                    'invite_state' => $invite ? [
                        'is_available' => (bool) $invite->is_available,
                        'is_busy' => (bool) $invite->is_busy,
                        'consecutive_failures' => (int) $invite->consecutive_failures,
                        'disabled_reason' => $invite->disabled_reason,
                    ] : null,
                    'meta' => $meta,
                    'invite_day' => $day,
                    /* The cap as it stands: the warm-up's, or the lower one a PEER_FLOOD left. */
                    'daily_cap' => isset($meta['cap']) ? max(1, min($warm, (int) $meta['cap'])) : $warm,
                    'run_share' => GroupInviteSettings::runShare(isset($meta['cap']) ? max(1, min($warm, (int) $meta['cap'])) : $warm, $config),
                    'calls_today' => $calls[$account->id] ?? 0,
                    'lookups_today' => $this->invites->resolvesToday($account->id),
                    'contacts' => $this->contacts($account->id)->count(),
                    'contacts_untried' => $group ? $this->untried($group)->whereIn('id', $this->contacts($account->id))->count() : null,
                ];
            })
            ->all();
    }

    /** A phone number that slipped into a text. */
    private static function mask(string $text): string
    {
        return (string) preg_replace('/\+?998\d{9}|\+\d{10,15}/', '+***', $text);
    }

    private static function processOf(TelegramAccountProcess $state): string
    {
        return $state->process instanceof ProcessEnum ? $state->process->value : (string) $state->process;
    }

    /**
     * The people this account has in its contacts, as the run takes them.
     */
    private function contacts(int $accountId): \Illuminate\Database\Query\Builder
    {
        return DB::table('telegram_account_contacts')
            ->where('telegram_account_id', $accountId)
            ->whereIn('status', ['added', 'skipped'])
            ->select('scraped_user_id')
            ->union(
                DB::table('telegram_account_contact_links')
                    ->where('telegram_account_id', $accountId)
                    ->select('scraped_user_id'),
            );
    }

    /**
     * Not a bot, not one of ours, not refused, not tried for the group -
     * what the run itself would still take, the audience aside.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TelegramScrapedUser>
     */
    private function untried(TelegramInviteGroup $group): \Illuminate\Database\Eloquent\Builder
    {
        $own = TelegramAccount::query()->whereNotNull('telegram_user_id')->pluck('telegram_user_id')->all();

        return TelegramScrapedUser::query()
            ->where('is_bot', false)
            ->addableToGroup()
            ->when($own !== [], fn ($q) => $q->whereNotIn('telegram_user_id', $own))
            ->whereNotIn('id', TelegramGroupInvite::query()->where('invite_group_id', $group->id)->select('scraped_user_id'));
    }

    /**
     * The groups not archived, with where their people stand.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(): array
    {
        $byStatus = TelegramGroupInvite::query()
            ->selectRaw('invite_group_id, status, COUNT(*) as total')
            ->groupBy('invite_group_id', 'status')
            ->get()
            ->groupBy('invite_group_id');

        return TelegramInviteGroup::query()
            ->orderBy('id')
            ->get()
            ->map(fn (TelegramInviteGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'link' => $group->link,
                'is_active' => (bool) $group->is_active,
                'last_error' => $group->last_error,
                'statuses' => ($byStatus->get($group->id) ?? collect())->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            ])
            ->all();
    }

    /**
     * The latest log lines, or those of one run; newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function log(int $lines, ?string $runId): array
    {
        if ($lines <= 0) {
            return [];
        }

        return TelegramGroupInviteLog::query()
            ->when($runId !== null, fn ($q) => $q->where('run_id', $runId))
            ->latest('id')
            ->limit($lines)
            ->get()
            ->map(fn (TelegramGroupInviteLog $line) => [
                'id' => $line->id,
                'at' => $line->created_at?->toIso8601String(),
                'run_id' => $line->run_id,
                'event' => $line->event,
                'account_id' => $line->telegram_account_id,
                'group_id' => $line->invite_group_id,
                'message' => self::mask((string) $line->message),
                'context' => $line->context,
            ])
            ->all();
    }
}
