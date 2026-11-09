<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApiError;
use App\Application\Telegram\Support\DeadSession;
use App\Application\Telegram\Support\JoinRefused;
use App\Application\Telegram\Support\SessionHung;
use App\Application\Telegram\Support\SessionProbe;
use App\Application\Telegram\Support\SessionSleep;
use App\Application\Telegram\Support\VendorNoticeShield;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Driver\TelegramDriverCheck;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramDriverGroupAdd;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramResolvedPhone;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\API;
use danog\MadelineProto\PeerNotInDbException;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Drivers into one of our groups.
 *
 * Who: every driver found in Telegram by the number (telegram_resolved_phones).
 * They come into the queue two ways - the "add every driver" button, and,
 * while "add on resolve" is on, every driver checked from then on, the name
 * matched or not (queueResolved(): it reads the checks, nothing in the
 * check flow itself is touched).
 *
 * Who adds: the account that found the driver by the number - the access
 * hash it got belongs to its session, nothing has to be looked up again.
 * One that cannot work any more (logged out, a dead session, switched off
 * in the panel, a listener now, not let into the group) leaves its drivers
 * to another account: that one finds the driver by the number itself
 * (contacts.resolvePhone) and adds them. One that only rests (a flood, its
 * day used up, busy in another process) is waited for.
 *
 * One call adds one driver; an account adds per_account_day a day, with a
 * pause between two. Where a try ends: added, in the group already, not in
 * it after the call (Telegram left them out without a word), refused
 * because of the driver, sent but not looked up. Telegram's answer that is
 * the account's (a flood, PEER_FLOOD, a dead session) rests the account
 * and the driver waits; the group's (gone, may not add there) stops the
 * account for the group or the run.
 *
 * A run takes the accounts one after another until nobody is left who may
 * add now; the scheduler asks every minute, so a rested account and the
 * next day's share go on by themselves.
 */
final class DriverGroupService
{
    /** What the run does now, for the page: a TelegramSetting, the run and the page are different processes. */
    public const NOW_KEY = 'driver_group.now';

    /** The run id the panel asked to stop ("*": whichever runs). */
    public const STOP_KEY = 'driver_group.stop';

    /** The lines of the run the page shows. */
    private const LINES = 80;

    private const STOP_POLL = 2;

    /** A FLOOD_WAIT up to this is waited out on the spot, at most WAIT_RETRIES times in a row. Seconds. */
    private const WAIT_IN_PLACE = 60;

    private const WAIT_RETRIES = 3;

    /** Over what Telegram said. Seconds. */
    private const WAIT_EXTRA = 30;

    /** PEER_FLOOD: the account may not add people it does not know for a while. */
    private const SPAM_REST_HOURS = 24;

    /** A frozen account. */
    private const FROZEN_REST_HOURS = 168;

    private const HUNG_REST_HOURS = 20;

    /** Network, Telegram's own trouble, a session that would not start. Minutes. */
    private const TRANSIENT_REST_MINUTES = 15;

    private const SESSION_REST_MINUTES = 30;

    /** An account not let into the group: its drivers go to another account for this long. Days. */
    private const DENIED_DAYS = 3;

    /** This many accounts in a row may not add into the group, none may: the run stops. */
    private const DENIED_LIMIT = 3;

    /** A member who joined this long before the call, or later, joined by it: two clocks never agree. Seconds. */
    private const JOIN_SLACK = 300;

    /** An account marked busy by another process longer ago than this was left so by a process that died. Hours. */
    private const STALE_BUSY_HOURS = 6;

    /** Within a run the drivers checked meanwhile are put into the queue this often. Seconds. */
    private const SWEEP_EVERY = 60;

    /** One account taken this many times in a run at most (new drivers checked while it ran). */
    private const PICKS_PER_ACCOUNT = 5;

    /** Phone lookups that say the number is in nobody's Telegram. */
    private const NOT_REGISTERED = ['PHONE_NOT_OCCUPIED', 'PHONE_NUMBER_UNOCCUPIED', 'PHONE_NUMBER_INVALID'];

    private const FROZEN = ['FROZEN_METHOD_INVALID', 'FROZEN_PARTICIPANT_MISSING'];

    private const ZERO = ['accounts' => 0, 'invited' => 0, 'already' => 0, 'not_added' => 0, 'failed' => 0, 'sent' => 0, 're_resolved' => 0];

    private string $runId = '';

    /** @var array<string, mixed> */
    private array $now = [];

    private bool $stopping = false;

    private int $stopLookedAt = 0;

    private int $sweptAt = 0;

    /** @var array<int, true> accounts this run is done with: trouble, the day used up, not let in */
    private array $done = [];

    /** @var array<int, int> account id => how many times this run took it */
    private array $picks = [];

    /** @var array<int, true> accounts that are in the group in this run */
    private array $entered = [];

    /** Accounts not let into the group in a row, none let in. */
    private int $denials = 0;

    /** Why the run stops for the group: nobody can add into it. */
    private ?string $groupStop = null;

    public function __construct(
        private readonly MadelineService $madeline,
        private readonly TelegramAccountProcessService $pool,
        private readonly DriverGroupSettings $settings,
        private readonly JoinBotSettings $joinBot,
        private readonly JoinRequestService $joinRequests,
        private readonly SessionProbe $probe,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The queue
    |--------------------------------------------------------------------------
    */

    /**
     * Every driver checked since "add on resolve" was switched on - found in
     * Telegram, the name matched or not - and not in the queue of the
     * group yet. Read from the checks: the check flow is not touched.
     *
     * @return int how many came into the queue
     */
    public function queueResolved(): int
    {
        $config = $this->settings->current();
        $group = $this->settings->group();

        if ($group === null || ! $config['on_resolve'] || $config['on_resolve_since'] === null) {
            return 0;
        }

        $ids = TelegramDriverCheck::query()
            ->whereNotNull('telegram_resolved_phone_id')
            ->where('checked_at', '>=', Carbon::parse($config['on_resolve_since']))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('telegram_driver_group_adds')
                ->whereColumn('telegram_driver_group_adds.resolved_phone_id', 'telegram_driver_checks.telegram_resolved_phone_id')
                ->where('telegram_driver_group_adds.invite_group_id', $group->id))
            ->distinct()
            ->pluck('telegram_resolved_phone_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        return $this->enqueue($group, TelegramResolvedPhone::query()->whereKey($ids), TelegramDriverGroupAdd::SOURCE_RESOLVE);
    }

    /**
     * The button: every driver ever found in Telegram who is not in the
     * queue of the group yet.
     */
    public function queueAll(): int
    {
        $group = $this->settings->group();

        return $group === null ? 0 : $this->enqueue($group, TelegramResolvedPhone::query(), TelegramDriverGroupAdd::SOURCE_ALL);
    }

    /**
     * Whoever the group did not take - left out, refused, not looked up -
     * goes into the queue again.
     */
    public function retry(): int
    {
        $group = $this->settings->group();

        if ($group === null) {
            return 0;
        }

        return TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->whereIn('status', TelegramDriverGroupAdd::RETRYABLE)
            ->update(['status' => TelegramDriverGroupAdd::STATUS_PENDING, 'error' => null, 'updated_at' => now()]);
    }

    /**
     * Another group picked: who waited for the old one waits no more. What
     * the old group got stays.
     */
    public function dropOtherGroups(?int $groupId): int
    {
        return TelegramDriverGroupAdd::query()
            ->where('status', TelegramDriverGroupAdd::STATUS_PENDING)
            ->when($groupId !== null, fn (Builder $query) => $query->where('invite_group_id', '!=', $groupId))
            ->delete();
    }

    /**
     * @param Builder<TelegramResolvedPhone> $phones
     */
    private function enqueue(TelegramInviteGroup $group, Builder $phones, string $source): int
    {
        $made = 0;

        $phones
            ->whereNotNull('telegram_user_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('telegram_driver_group_adds')
                ->whereColumn('telegram_driver_group_adds.resolved_phone_id', 'telegram_resolved_phones.id')
                ->where('telegram_driver_group_adds.invite_group_id', $group->id))
            ->with('driver:id,name')
            ->chunkById(500, function (Collection $chunk) use ($group, $source, &$made): void {
                /* The name the driver was checked with last, when the phone has no driver of its own. */
                $named = TelegramDriverCheck::query()
                    ->whereIn('telegram_resolved_phone_id', $chunk->pluck('id'))
                    ->whereNotNull('driver_name')
                    ->orderByDesc('id')
                    ->get(['telegram_resolved_phone_id', 'driver_name'])
                    ->unique('telegram_resolved_phone_id')
                    ->pluck('driver_name', 'telegram_resolved_phone_id');

                $now = now();

                $rows = $chunk->map(fn (TelegramResolvedPhone $phone) => [
                    'invite_group_id' => $group->id,
                    'resolved_phone_id' => $phone->id,
                    'driver_name' => mb_substr((string) ($phone->driver?->name ?: ($named[$phone->id] ?? '')), 0, 255) ?: null,
                    'phone' => mb_substr((string) $phone->phone_normalized, 0, 32),
                    'telegram_user_id' => $phone->telegram_user_id,
                    'resolver_account_id' => $phone->telegram_account_id,
                    'source' => $source,
                    'status' => TelegramDriverGroupAdd::STATUS_PENDING,
                    'attempts' => 0,
                    're_resolved' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                $made += DB::table('telegram_driver_group_adds')->insertOrIgnore($rows);
            });

        if ($made > 0) {
            Log::info('Driver group: drivers queued', ['group_id' => $group->id, 'source' => $source, 'count' => $made]);
        }

        return $made;
    }

    /*
    |--------------------------------------------------------------------------
    | The run
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a run has anything to do now: drivers waiting for the group.
     */
    public function hasPending(): bool
    {
        $group = $this->settings->group();

        return $group !== null && TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->where('status', TelegramDriverGroupAdd::STATUS_PENDING)
            ->exists();
    }

    /**
     * Asked from the panel: the run finishes the driver in hand and ends.
     * The panel's "Stop" also stops the scheduler (DriverGroupSettings::pause()).
     */
    public static function requestStop(?string $runId): void
    {
        TelegramSetting::set(self::STOP_KEY, $runId ?? '*');
    }

    /**
     * A scheduled run that finds nobody who may add now ends without a
     * word: the page keeps showing the last real run.
     *
     * @param callable(string): void|null $output
     *
     * @return array{accounts: int, invited: int, already: int, not_added: int, failed: int, sent: int, re_resolved: int}
     */
    public function run(?callable $output = null, string $trigger = 'manual'): array
    {
        $this->runId = (string) Str::uuid();
        $this->stopping = false;
        $this->stopLookedAt = 0;
        $this->sweptAt = time();
        $this->done = [];
        $this->picks = [];
        $this->entered = [];
        $this->denials = 0;
        $this->groupStop = null;

        $say = $output ?? static function (string $line): void {};
        $totals = self::ZERO;
        $config = $this->settings->current();
        $group = $this->settings->group();

        if ($group === null) {
            if ($trigger !== 'schedule') {
                $this->begin($trigger, null);
                $this->line('no_group');
                $this->finish($totals);
            }

            $say('No group picked.');

            return $totals;
        }

        $this->queueResolved();
        $this->releaseStuck();

        $plan = $this->next($group, $config);

        if ($plan === null && $trigger === 'schedule') {
            return $totals;
        }

        TelegramSetting::set(self::STOP_KEY, null);
        $this->begin($trigger, $group);

        while ($plan !== null && ! $this->stopping()) {
            [$account, $rows, $fallback] = $plan;
            $plan = null;

            $totals['accounts']++;
            $say("Account #{$account->id} {$account->phone}: {$rows->count()} drivers" . ($fallback ? ' (for another account)' : ''));

            foreach ($this->runAccount($account, $group, $rows, $fallback, $config, $say) as $key => $value) {
                $totals[$key] += $value;
            }

            $this->now['totals'] = $totals;

            if ($this->groupStop !== null || $this->stopping()) {
                break;
            }

            if (time() - $this->sweptAt >= self::SWEEP_EVERY) {
                $this->sweptAt = time();
                $this->queueResolved();
            }

            /* Another group picked meanwhile, or the group archived: this run is over. */
            $config = $this->settings->current();

            if ($config['group_id'] !== $group->id || ! TelegramInviteGroup::query()->whereKey($group->id)->exists()) {
                $this->line('group_changed');

                break;
            }

            $group->refresh();
            $plan = $this->next($group, $config);
        }

        /* Taken for a turn the stop came before: not left busy for the other processes. */
        if ($plan !== null) {
            $this->pool->release($plan[0], ProcessEnum::DriverGroupAdd);
        }

        $this->finish($totals);

        return $totals;
    }

    /**
     * The next account and its drivers: the account that found them first,
     * in the order they came into the queue; the drivers of accounts that
     * cannot work any more go to any other account free now.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: TelegramAccount, 1: Collection<int, TelegramDriverGroupAdd>, 2: bool}|null
     */
    private function next(TelegramInviteGroup $group, array $config): ?array
    {
        $pending = fn () => TelegramDriverGroupAdd::query()
            ->where('invite_group_id', $group->id)
            ->where('status', TelegramDriverGroupAdd::STATUS_PENDING);

        if (! $pending()->exists()) {
            return null;
        }

        $resolvers = $pending()
            ->whereNotNull('resolver_account_id')
            ->selectRaw('resolver_account_id, MIN(id) as first_id')
            ->groupBy('resolver_account_id')
            ->orderBy('first_id')
            ->pluck('resolver_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $gone = $this->goneIds($resolvers, $group);
        $waiting = array_values(array_unique([
            ...$this->restingIds(),
            ...$this->busyElsewhere(),
            ...array_keys($this->done),
            ...array_keys(array_filter($this->picks, fn (int $n) => $n >= self::PICKS_PER_ACCOUNT)),
        ]));
        $capped = $this->cappedIds($config);

        foreach (array_diff($resolvers, $gone, $waiting, $capped) as $id) {
            $account = TelegramAccount::query()->find($id);

            if ($account === null || ! $this->claim($account)) {
                continue;
            }

            $rows = $pending()
                ->where('resolver_account_id', $id)
                ->with('resolvedPhone')
                ->orderBy('id')
                ->limit($this->leftToday($id, $config))
                ->get();

            if ($rows->isEmpty()) {
                $this->pool->release($account, ProcessEnum::DriverGroupAdd);

                continue;
            }

            $this->picks[$id] = ($this->picks[$id] ?? 0) + 1;

            return [$account, $rows, false];
        }

        /* The drivers whose account cannot work any more, or was never known. */
        $orphans = fn () => $pending()->where(fn (Builder $query) => $query
            ->whereNull('resolver_account_id')
            ->when($gone !== [], fn (Builder $query) => $query->orWhereIn('resolver_account_id', $gone)));

        if (! $orphans()->exists()) {
            return null;
        }

        $account = $this->pool->claimRandomAvailableAccount(
            ProcessEnum::DriverGroupAdd,
            array_values(array_unique([...$waiting, ...$capped, ...$this->deniedIds($group)])),
        );

        if ($account === null) {
            return null;
        }

        $rows = $orphans()->with('resolvedPhone')->orderBy('id')->limit($this->leftToday($account->id, $config))->get();

        if ($rows->isEmpty()) {
            $this->pool->release($account, ProcessEnum::DriverGroupAdd);

            return null;
        }

        $this->picks[$account->id] = ($this->picks[$account->id] ?? 0) + 1;

        return [$account, $rows, true];
    }

    /**
     * One account's drivers.
     *
     * @param Collection<int, TelegramDriverGroupAdd> $rows
     * @param array<string, mixed>                    $config
     *
     * @return array{invited: int, already: int, not_added: int, failed: int, sent: int, re_resolved: int}
     */
    private function runAccount(TelegramAccount $account, TelegramInviteGroup $group, Collection $rows, bool $fallback, array $config, callable $say): array
    {
        $done = ['invited' => 0, 'already' => 0, 'not_added' => 0, 'failed' => 0, 'sent' => 0, 're_resolved' => 0];
        $clean = true;

        $this->now = [...$this->now, 'account_id' => $account->id, 'account' => $account->phone, 'driver' => null, 'fallback' => $fallback];
        $this->line('account', ['account' => $account->phone, 'fallback' => $fallback, 'size' => $rows->count()]);
        $this->doing('connecting');

        $api = null;

        try {
            $api = $this->connect($account);

            if ($api === null) {
                return $done;
            }

            try {
                $this->enter($api, $account, $group);
            } catch (Throwable $e) {
                $this->joinTrouble($account, $group, $e);
                $say('  join: ' . self::code($e));

                return $done;
            }

            if (! $group->telegram_chat_id && $group->isInvite()) {
                $this->deny($account, $group, 'NO_CHAT_ID');

                return $done;
            }

            foreach ($rows->values() as $i => $row) {
                if ($this->stopping()) {
                    $clean = false;

                    break;
                }

                if ($i > 0) {
                    $this->pause($config);

                    if ($this->stopping()) {
                        $clean = false;

                        break;
                    }
                }

                $status = $this->addOne($api, $account, $group, $row, $fallback);

                if ($status === null) {
                    $clean = false;

                    break;
                }

                $done[$status]++;
                $done['re_resolved'] += $row->re_resolved ? 1 : 0;
                $say("  {$row->phone}: {$status}" . ($row->error ? " ({$row->error})" : ''));
            }

            if ($clean) {
                $this->pool->registerSuccess($account, ProcessEnum::DriverGroupAdd);
                $this->denials = 0;
            }
        } catch (Throwable $e) {
            $clean = false;
            $this->accountTrouble($account, $e, GroupInviteService::classify($e, GroupInviteService::STEP_SESSION), GroupInviteService::STEP_SESSION);
            $say('  failed: ' . $e->getMessage());
        } finally {
            SessionSleep::put($api, ['account_id' => $account->id]);

            $this->pool->release($account, ProcessEnum::DriverGroupAdd);
        }

        if (! $clean || $this->leftToday($account->id, $config) <= 0) {
            $this->done[$account->id] = true;
        }

        return $done;
    }

    /**
     * One driver: found (from the account's own lookup, or by the number
     * now), invited, looked up in the group.
     *
     * @return string|null the status the driver ended in; null: the account stops, the driver waits
     */
    private function addOne(API $api, TelegramAccount $account, TelegramInviteGroup $group, TelegramDriverGroupAdd $row, bool $fallback): ?string
    {
        $this->now['driver'] = $row->driver_name ?: $row->phone;
        $row->re_resolved = $row->resolver_account_id !== $account->id;

        $user = $row->re_resolved ? null : self::knownUser($row->resolvedPhone);

        if ($user === null) {
            $this->doing('resolving');

            try {
                $user = $this->lookUp($api, $row->phone);
            } catch (Throwable $e) {
                return $this->callTrouble($account, $group, $row, $e, GroupInviteService::STEP_RESOLVE);
            }

            if ($user === null) {
                return $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_FAILED, 'NOT_REGISTERED');
            }

            $row->telegram_user_id = $user['id'];
        }

        $sentAt = time();
        $this->doing('adding');

        try {
            $response = $this->patiently(fn () => $api->channels->inviteToChannel(
                channel: $this->peer($group),
                users: [self::inputUser($user)],
                floodWaitLimit: 0,
            ));
        } catch (Throwable $e) {
            return $this->callTrouble($account, $group, $row, $e, GroupInviteService::STEP_INVITE);
        }

        $missing = self::missingIds($response);

        if (isset($missing[$user['id']])) {
            return $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_FAILED, $missing[$user['id']]);
        }

        if (isset(self::addedIds($response)[$user['id']])) {
            return $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_INVITED);
        }

        $this->doing('checking');

        try {
            [$status, $error] = $this->presence($api, $group, $user, $sentAt);
        } catch (Throwable $e) {
            $kind = GroupInviteService::classify($e, GroupInviteService::STEP_CHECK);
            $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_SENT, mb_substr('CHECK_' . self::code($e), 0, 120));

            if (in_array($kind, [GroupInviteService::KIND_WAIT, GroupInviteService::KIND_SPAM, GroupInviteService::KIND_DEAD, GroupInviteService::KIND_TRANSIENT], true)) {
                $this->accountTrouble($account, $e, $kind, GroupInviteService::STEP_CHECK);

                return null;
            }

            return TelegramDriverGroupAdd::STATUS_SENT;
        }

        return $this->settle($row, $account, $status, $error);
    }

    /**
     * What an error of a lookup or an invite call means: the account's
     * trouble (the driver waits), the group's (the driver waits), or the
     * driver's (the try ends).
     *
     * @return string|null the status; null: the account stops
     */
    private function callTrouble(TelegramAccount $account, TelegramInviteGroup $group, TelegramDriverGroupAdd $row, Throwable $e, string $step): ?string
    {
        $kind = GroupInviteService::classify($e, $step);
        $code = self::code($e);

        if (in_array($kind, [GroupInviteService::KIND_WAIT, GroupInviteService::KIND_SPAM, GroupInviteService::KIND_DEAD, GroupInviteService::KIND_TRANSIENT, GroupInviteService::KIND_HUNG], true)) {
            $this->accountTrouble($account, $e, $kind, $step);

            return null;
        }

        if ($kind === GroupInviteService::KIND_GROUP_GONE || $kind === GroupInviteService::KIND_GROUP_DENIED) {
            $this->groupTrouble($account, $group, $kind, $code);

            return null;
        }

        return match ($kind) {
            GroupInviteService::KIND_ALREADY => $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_ALREADY),
            GroupInviteService::KIND_NOT_ADDED => $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_NOT_ADDED, $code),
            default => $this->settle($row, $account, TelegramDriverGroupAdd::STATUS_FAILED, self::peerMissing($e) ? 'PEER_ID_INVALID' : $code),
        };
    }

    private function settle(TelegramDriverGroupAdd $row, TelegramAccount $account, string $status, ?string $error = null): string
    {
        $row->forceFill([
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 120) : null,
            'telegram_account_id' => $account->id,
            'attempts' => $row->attempts + 1,
            'attempted_at' => now(),
        ])->save();

        $this->line('driver', [
            'account' => $account->phone,
            'driver' => $row->driver_name,
            'phone' => $row->phone,
            'status' => $status,
            'code' => $error,
            're_resolved' => $row->re_resolved,
        ]);

        return $status;
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    /**
     * The account's session, started once a process of its own showed that
     * it starts and answers (as in the group invites). Null: stopped from
     * the panel meanwhile.
     */
    private function connect(TelegramAccount $account): ?API
    {
        $probe = $this->probe->check($account, GroupInviteService::SESSION_START_LIMIT, fn () => $this->stopping());

        if ($probe['status'] === SessionProbe::STOPPED) {
            return null;
        }

        if ($probe['status'] === SessionProbe::HUNG) {
            throw new SessionHung($probe['error'] ?? GroupInviteService::SESSION_HUNG);
        }

        $api = $this->madeline->for($account);

        if (! $api) {
            throw $this->madeline->lastError ?? new RuntimeException('Failed to start MadelineProto.');
        }

        return $api;
    }

    /**
     * The driver as the account that found them knows them: their id and
     * the access hash its own lookup got. Null when that answer is not
     * kept: the account looks the number up again.
     *
     * @return array{id: int, access_hash: int|string}|null
     */
    private static function knownUser(?TelegramResolvedPhone $phone): ?array
    {
        if ($phone === null || ! $phone->telegram_user_id) {
            return null;
        }

        $raw = is_array($phone->telegram_raw) ? $phone->telegram_raw : [];

        foreach ((array) ($raw['users'] ?? []) as $user) {
            if (is_array($user) && (int) ($user['id'] ?? 0) === (int) $phone->telegram_user_id && isset($user['access_hash'])) {
                return ['id' => (int) $user['id'], 'access_hash' => $user['access_hash']];
            }
        }

        return null;
    }

    /**
     * The driver found by the number, by this account.
     *
     * @return array{id: int, access_hash: int|string|null}|null null: nobody has the number in Telegram
     *
     * @throws Throwable the account's own trouble, anything else Telegram refused
     */
    private function lookUp(API $api, string $phone): ?array
    {
        try {
            $result = $this->patiently(fn () => VendorNoticeShield::guard(
                'contacts.resolvePhone',
                static fn () => $api->contacts->resolvePhone(phone: $phone, floodWaitLimit: 0),
            ));
        } catch (Throwable $e) {
            if (in_array(self::rpcError($e)?->rpc, self::NOT_REGISTERED, true)) {
                return null;
            }

            throw $e;
        }

        $user = is_array($result) ? ($result['users'][0] ?? null) : null;

        if (! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        return ['id' => (int) $user['id'], 'access_hash' => $user['access_hash'] ?? null];
    }

    /**
     * @param array{id: int, access_hash: int|string|null} $user
     */
    private static function inputUser(array $user): array|int
    {
        return ($user['access_hash'] ?? null) !== null
            ? ['_' => 'inputUser', 'user_id' => $user['id'], 'access_hash' => $user['access_hash']]
            : $user['id'];
    }

    /**
     * @param array{id: int, access_hash: int|string|null} $user
     */
    private static function inputPeer(array $user): array|int
    {
        return ($user['access_hash'] ?? null) !== null
            ? ['_' => 'inputPeerUser', 'user_id' => $user['id'], 'access_hash' => $user['access_hash']]
            : $user['id'];
    }

    /**
     * Is the driver in the group after the call, and since when.
     *
     * @param array{id: int, access_hash: int|string|null} $user
     *
     * @return array{0: string, 1: string|null}
     */
    private function presence(API $api, TelegramInviteGroup $group, array $user, int $sentAt): array
    {
        try {
            $answer = $this->patiently(fn () => $api->channels->getParticipant(
                channel: $this->peer($group),
                participant: self::inputPeer($user),
                floodWaitLimit: 0,
            ));
        } catch (Throwable $e) {
            if (self::rpcError($e)?->rpc === 'USER_NOT_PARTICIPANT') {
                return [TelegramDriverGroupAdd::STATUS_NOT_ADDED, GroupInviteService::NOT_IN_GROUP];
            }

            throw $e;
        }

        $member = is_array($answer) && is_array($answer['participant'] ?? null) ? $answer['participant'] : [];
        $type = $member['_'] ?? null;

        return match (true) {
            $type === null => [TelegramDriverGroupAdd::STATUS_SENT, 'CHECK_NO_ANSWER'],
            $type === 'channelParticipantLeft',
            $type === 'channelParticipantBanned' && ! empty($member['left']) => [TelegramDriverGroupAdd::STATUS_NOT_ADDED, GroupInviteService::NOT_IN_GROUP],
            isset($member['date']) && (int) $member['date'] >= $sentAt - self::JOIN_SLACK => [TelegramDriverGroupAdd::STATUS_INVITED, null],
            default => [TelegramDriverGroupAdd::STATUS_ALREADY, null],
        };
    }

    /**
     * The account is in the group before it adds anybody, once a run: by
     * our bot's link first (the bot lets it in), else by the group's own
     * link - the way the group invites get in.
     *
     * @throws Throwable the account's trouble, a refusal of the join
     */
    private function enter(API $api, TelegramAccount $account, TelegramInviteGroup $group): void
    {
        if (isset($this->entered[$account->id])) {
            return;
        }

        $this->doing('joining');

        if (! $this->throughBot($api, $account, $group)) {
            $this->byLink($api, $account, $group);
        }

        $this->entered[$account->id] = true;
        $group->refresh();
    }

    private function throughBot(API $api, TelegramAccount $account, TelegramInviteGroup $group): bool
    {
        if (! $this->joinBot->enabled()) {
            return false;
        }

        $hash = null;
        $seen = null;

        foreach ([false, true] as $fresh) {
            try {
                $hash = $this->joinRequests->requestLink($group, $fresh);
            } catch (BotApiError) {
                return false;
            }

            if ($hash === null) {
                return false;
            }

            try {
                $seen = $this->patiently(fn () => $api->messages->checkChatInvite(hash: $hash, floodWaitLimit: 0));

                break;
            } catch (Throwable $e) {
                if (self::accountKind($e, GroupInviteService::STEP_JOIN)) {
                    throw $e;
                }

                if (! $fresh && in_array(self::code($e), ['INVITE_HASH_EXPIRED', 'INVITE_HASH_INVALID'], true)) {
                    continue;
                }

                return false;
            }
        }

        if (($seen['_'] ?? null) === 'chatInviteAlready') {
            $this->rememberChat($group, $seen);

            return true;
        }

        if (! $this->knowSelf($api, $account)) {
            return false;
        }

        try {
            $this->patiently(fn () => $api->messages->importChatInvite(hash: $hash, floodWaitLimit: 0));
        } catch (Throwable $e) {
            if (self::code($e) !== 'INVITE_REQUEST_SENT') {
                if (GroupInviteService::classify($e, GroupInviteService::STEP_JOIN) !== GroupInviteService::KIND_ALREADY) {
                    throw $e;
                }
            } elseif (($error = $this->joinRequests->letIn($group, $account)) !== null) {
                throw new JoinRefused($error === 'NO_BOT' ? 'JOIN_NOT_APPROVED' : $error);
            }
        }

        for ($look = 1; ; $look++) {
            $seen = $this->patiently(fn () => $api->messages->checkChatInvite(hash: $hash, floodWaitLimit: 0));

            if (($seen['_'] ?? null) === 'chatInviteAlready' || $look >= 2) {
                break;
            }

            $this->snooze(3);
        }

        if (($seen['_'] ?? null) !== 'chatInviteAlready') {
            throw new JoinRefused('JOIN_NOT_APPROVED');
        }

        $this->rememberChat($group, $seen);
        $this->line('joined', ['account' => $account->phone, 'via' => 'bot']);

        return true;
    }

    private function byLink(API $api, TelegramAccount $account, TelegramInviteGroup $group): void
    {
        $join = fn () => $this->patiently(fn () => $group->isInvite()
            ? $api->messages->importChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0)
            : $api->channels->joinChannel(channel: '@' . ltrim($group->link, '@'), floodWaitLimit: 0));

        try {
            try {
                $result = $join();
            } catch (Throwable $e) {
                /* The group takes members by request: our bot lets the account in, then the same join finds it in. */
                if (self::code($e) !== 'INVITE_REQUEST_SENT'
                    || ! $group->telegram_chat_id
                    || ! $this->joinBot->enabled()
                    || ! $this->knowSelf($api, $account)
                    || $this->joinRequests->letIn($group, $account) !== null) {
                    throw $e;
                }

                $result = $join();
            }

            $this->rememberChat($group, $result);
            $this->line('joined', ['account' => $account->phone, 'via' => 'link']);
        } catch (Throwable $e) {
            if (GroupInviteService::classify($e, GroupInviteService::STEP_JOIN) !== GroupInviteService::KIND_ALREADY) {
                throw $e;
            }

            if ($group->isInvite() && ! $group->telegram_chat_id) {
                $this->rememberChat($group, $this->patiently(fn () => $api->messages->checkChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0)));
            }
        }
    }

    private function knowSelf(API $api, TelegramAccount $account): bool
    {
        if ($account->telegram_user_id) {
            return true;
        }

        try {
            $self = $api->getSelf();
        } catch (Throwable) {
            return false;
        }

        if (! is_array($self) || empty($self['id'])) {
            return false;
        }

        $account->update(TelegramAccount::profileFrom($self));

        return true;
    }

    /**
     * The group's id (and title) as the account sees it, kept on the group.
     */
    private function rememberChat(TelegramInviteGroup $group, mixed $result): void
    {
        if (! is_array($result)) {
            return;
        }

        $chat = $result['chats'][0] ?? $result['chat'] ?? null;

        if (! is_array($chat)) {
            return;
        }

        /* A basic group takes people one by one by another call: not done here. */
        if (($chat['_'] ?? null) === 'chat') {
            throw RPCErrorException::make('BASIC_GROUP', 400, 'channels.joinChannel');
        }

        $update = [];

        if (($id = GroupTopic::chatId($chat)) !== null && ! $group->telegram_chat_id) {
            $update['telegram_chat_id'] = $id;
        }

        $title = trim((string) ($chat['title'] ?? ''));

        if ($title !== '' && ($group->title === null || $group->title === '')) {
            $update['title'] = mb_substr($title, 0, 255);
        }

        if ($update !== []) {
            $group->update($update);
        }
    }

    private function peer(TelegramInviteGroup $group): int|string
    {
        if ($group->telegram_chat_id) {
            return GroupTopic::chatId(['id' => $group->telegram_chat_id]) ?? (int) $group->telegram_chat_id;
        }

        return '@' . ltrim($group->link, '@');
    }

    /**
     * A short FLOOD_WAIT is waited out on the spot; a longer one is the
     * account's trouble.
     */
    private function patiently(callable $call): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $call();
            } catch (Throwable $e) {
                if ($try >= self::WAIT_RETRIES || GroupInviteService::classify($e) !== GroupInviteService::KIND_WAIT) {
                    throw $e;
                }

                $wait = self::waitSeconds($e);

                if ($wait > self::WAIT_IN_PLACE) {
                    throw $e;
                }

                $this->doing('waiting', ['until' => now()->addSeconds($wait + 1)->toIso8601String()]);
                $this->snooze($wait + 1);

                if ($this->stopping()) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function missingIds(mixed $result): array
    {
        $failed = [];

        foreach ((array) (is_array($result) ? ($result['missing_invitees'] ?? []) : []) as $row) {
            if (is_array($row) && isset($row['user_id'])) {
                $failed[(int) $row['user_id']] = ! empty($row['premium_would_allow_invite']) ? 'PREMIUM_REQUIRED' : 'MISSING_INVITEE';
            }
        }

        return $failed;
    }

    /**
     * The people Telegram's own "added" service message names.
     *
     * @return array<int, true>
     */
    private static function addedIds(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $box = $result['updates'] ?? [];
        $list = is_array($box) && isset($box['_'])
            ? ($box['updates'] ?? (isset($box['update']) ? [$box['update']] : []))
            : $box;

        $added = [];

        foreach ((array) $list as $update) {
            $action = is_array($update) ? ($update['message']['action'] ?? null) : null;

            if (is_array($action) && ($action['_'] ?? null) === 'messageActionChatAddUser') {
                foreach ((array) ($action['users'] ?? []) as $id) {
                    $added[(int) $id] = true;
                }
            }
        }

        return $added;
    }

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    */

    /**
     * Of these accounts, the ones that cannot add any more: their drivers
     * go to another account. Logged out, a dead session, switched off in
     * the panel for this work, a listener or a Join group worker now (their
     * sessions are open elsewhere), not let into this group lately.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function goneIds(array $ids, TelegramInviteGroup $group): array
    {
        if ($ids === []) {
            return [];
        }

        $working = TelegramAccount::query()
            ->whereKey($ids)
            ->where('is_authorized', true)
            ->whereNotNull('session_path')
            ->where('session_path', '!=', '')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $held = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->whereIn('telegram_account_id', $ids)
            ->where(fn ($query) => $query
                ->where('is_available', false)
                ->orWhere('consecutive_failures', '>=', TelegramAccountProcessService::MAX_CONSECUTIVE_FAILURES))
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $primary = config('services.telegram.driver_check_account_id');

        $elsewhere = [
            ...ListenerAccounts::rowIds(),
            ...GroupJoinSettings::dedicatedIds(),
            ...(is_numeric($primary) ? [(int) $primary] : []),
        ];

        return array_values(array_unique([
            ...array_diff($ids, $working),
            ...array_intersect($ids, [...$held, ...$elsewhere, ...$this->deniedIds($group)]),
        ]));
    }

    /**
     * Accounts not let into the group (or may not add there) lately.
     *
     * @return list<int>
     */
    private function deniedIds(TelegramInviteGroup $group): array
    {
        $since = now()->subDays(self::DENIED_DAYS)->timestamp;

        return TelegramAccountProcess::query()
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->whereNotNull('meta')
            ->get(['telegram_account_id', 'meta'])
            ->filter(function (TelegramAccountProcess $state) use ($group, $since): bool {
                $at = ((array) $state->meta)['denied'][(string) $group->id]['at'] ?? null;

                return is_string($at) && strtotime($at) !== false && strtotime($at) >= $since;
            })
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Accounts resting from this work: a flood, PEER_FLOOD, a session that hung.
     *
     * @return list<int>
     */
    private function restingIds(): array
    {
        return TelegramAccountProcess::query()
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->whereNotNull('meta')
            ->get(['telegram_account_id', 'meta'])
            ->filter(fn (TelegramAccountProcess $state) => self::secondsUntil(((array) $state->meta)['rest_until'] ?? null) > 0)
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Accounts whose session another process holds now: a MadelineProto
     * session tolerates one full process.
     *
     * @return list<int>
     */
    private function busyElsewhere(): array
    {
        return TelegramAccountProcess::query()
            ->where('is_busy', true)
            ->where('process', '!=', ProcessEnum::DriverGroupAdd->value)
            ->where(fn ($query) => $query->whereNull('busy_at')->orWhere('busy_at', '>=', now()->subHours(self::STALE_BUSY_HOURS)))
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Accounts whose day is used up.
     *
     * @param array<string, mixed> $config
     *
     * @return list<int>
     */
    private function cappedIds(array $config): array
    {
        return TelegramDriverGroupAdd::query()
            ->whereNotNull('telegram_account_id')
            ->where('attempted_at', '>=', now()->startOfDay())
            ->groupBy('telegram_account_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $config['per_account_day']])
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * How each account stands for this work now, for the page: ready, its
     * day used up, resting (until when, why), busy in another process, or
     * gone - its drivers go to another account (why).
     *
     * @param list<int> $ids
     *
     * @return array<int, array{state: string, until: string|null, reason: string|null}>
     */
    public function states(array $ids, TelegramInviteGroup $group): array
    {
        if ($ids === []) {
            return [];
        }

        $config = $this->settings->current();
        $gone = array_flip($this->goneIds($ids, $group));
        $busy = array_flip($this->busyElsewhere());
        $capped = array_flip($this->cappedIds($config));
        $denied = array_flip($this->deniedIds($group));
        $elsewhere = array_flip([...ListenerAccounts::rowIds(), ...GroupJoinSettings::dedicatedIds()]);
        $accounts = TelegramAccount::query()->whereKey($ids)->get()->keyBy('id');
        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->whereIn('telegram_account_id', $ids)
            ->get()
            ->keyBy('telegram_account_id');

        $out = [];

        foreach ($ids as $id) {
            $account = $accounts->get($id);
            $state = $states->get($id);
            $meta = (array) ($state?->meta ?? []);
            $restUntil = self::secondsUntil($meta['rest_until'] ?? null) > 0 ? $meta['rest_until'] : null;

            $out[$id] = match (true) {
                isset($gone[$id]) => ['state' => 'gone', 'until' => null, 'reason' => match (true) {
                    $account === null => 'deleted',
                    ! $account->is_authorized || ! $account->session_path => 'logged_out',
                    isset($denied[$id]) => 'denied:' . ($meta['denied'][(string) $group->id]['code'] ?? ''),
                    isset($elsewhere[$id]) => 'elsewhere',
                    default => 'held',
                }],
                $restUntil !== null => ['state' => 'resting', 'until' => $restUntil, 'reason' => $meta['rest_reason'] ?? null],
                isset($busy[$id]) => ['state' => 'busy', 'until' => null, 'reason' => null],
                isset($capped[$id]) => ['state' => 'capped', 'until' => null, 'reason' => null],
                default => ['state' => 'ready', 'until' => null, 'reason' => null],
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function leftToday(int $accountId, array $config): int
    {
        $tried = TelegramDriverGroupAdd::query()
            ->where('telegram_account_id', $accountId)
            ->where('attempted_at', '>=', now()->startOfDay())
            ->count();

        return max(0, (int) $config['per_account_day'] - $tried);
    }

    private function claim(TelegramAccount $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            $state = TelegramAccountProcess::query()
                ->where('telegram_account_id', $account->id)
                ->where('process', ProcessEnum::DriverGroupAdd->value)
                ->lockForUpdate()
                ->first()
                ?? $this->pool->getOrCreate($account, ProcessEnum::DriverGroupAdd);

            if (! $state->is_available || $state->is_busy) {
                return false;
            }

            $state->update(['is_busy' => true, 'busy_at' => now()]);

            return true;
        });
    }

    /**
     * One run at a time (the command's lock): an account still busy for
     * this work was left so by a run that died.
     */
    private function releaseStuck(): void
    {
        TelegramAccountProcess::query()
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->where('is_busy', true)
            ->update(['is_busy' => false, 'busy_at' => null]);
    }

    /**
     * Telegram stopped the account: it rests as long as that takes, its
     * drivers wait for it. A dead session is written down as such - its
     * drivers then go to another account.
     */
    private function accountTrouble(TelegramAccount $account, Throwable $e, string $kind, string $step): void
    {
        $code = self::code($e);
        $this->done[$account->id] = true;

        $seconds = match (true) {
            $kind === GroupInviteService::KIND_WAIT => self::waitSeconds($e) + self::WAIT_EXTRA,
            $kind === GroupInviteService::KIND_SPAM => self::SPAM_REST_HOURS * 3600,
            $kind === GroupInviteService::KIND_HUNG => self::HUNG_REST_HOURS * 3600,
            $kind === GroupInviteService::KIND_DEAD && in_array(self::rpcError($e)?->rpc, self::FROZEN, true) => self::FROZEN_REST_HOURS * 3600,
            $kind === GroupInviteService::KIND_DEAD => 0,
            $step === GroupInviteService::STEP_SESSION => self::SESSION_REST_MINUTES * 60,
            default => self::TRANSIENT_REST_MINUTES * 60,
        };

        if ($kind === GroupInviteService::KIND_DEAD && ($dead = DeadSession::code($e)) !== null) {
            DeadSession::retire($account->id, $dead);
        }

        $until = $seconds > 0 ? now()->addSeconds($seconds) : null;

        if ($until !== null) {
            $this->setMeta($account, ['rest_until' => $until->toIso8601String(), 'rest_kind' => $kind, 'rest_reason' => $code]);
        }

        $this->line('rest', ['account' => $account->phone, 'kind' => $kind, 'code' => $code, 'until' => $until?->toIso8601String(), 'step' => $step]);

        Log::warning('Driver group: the account stops', [
            'account_id' => $account->id,
            'kind' => $kind,
            'code' => $code,
            'step' => $step,
            'until' => $until?->toIso8601String(),
        ]);
    }

    private function joinTrouble(TelegramAccount $account, TelegramInviteGroup $group, Throwable $e): void
    {
        $kind = GroupInviteService::classify($e, GroupInviteService::STEP_JOIN);

        if (self::accountKind($e, GroupInviteService::STEP_JOIN) || $kind === GroupInviteService::KIND_HUNG) {
            $this->accountTrouble($account, $e, $kind, GroupInviteService::STEP_JOIN);

            return;
        }

        $code = self::code($e);

        /* A basic group takes nobody by this call, from any account. */
        if ($code === 'BASIC_GROUP') {
            $kind = GroupInviteService::KIND_GROUP_GONE;
        }

        /* Our accounts have been in the group: it is this account's way in that is gone. */
        if ($kind === GroupInviteService::KIND_GROUP_GONE && $group->telegram_chat_id && ! in_array($code, ['USERS_TOO_MUCH', 'BASIC_GROUP'], true)) {
            $this->deny($account, $group, $code);

            return;
        }

        $this->groupTrouble($account, $group, $kind === GroupInviteService::KIND_GROUP_GONE ? $kind : GroupInviteService::KIND_GROUP_DENIED, $code);
    }

    private function groupTrouble(TelegramAccount $account, TelegramInviteGroup $group, string $kind, string $code): void
    {
        if ($kind === GroupInviteService::KIND_GROUP_GONE && (! $group->telegram_chat_id || $code === 'USERS_TOO_MUCH' || $code === 'BASIC_GROUP')) {
            $this->groupStop = $code;
            $this->now['error'] = $code;
            $this->line('group_gone', ['account' => $account->phone, 'code' => $code]);

            return;
        }

        $this->deny($account, $group, $code);
    }

    /**
     * This account may not add into the group: its drivers go to another
     * account. A few in a row, none let in: the run stops.
     */
    private function deny(TelegramAccount $account, TelegramInviteGroup $group, string $code): void
    {
        $this->done[$account->id] = true;
        $meta = $this->meta($account);
        $denied = (array) ($meta['denied'] ?? []);
        $denied[(string) $group->id] = ['code' => $code, 'at' => now()->toIso8601String()];

        $this->setMeta($account, ['denied' => $denied]);
        $this->line('denied', ['account' => $account->phone, 'code' => $code]);

        if (++$this->denials >= self::DENIED_LIMIT) {
            $this->groupStop = $code;
            $this->now['error'] = $code;
            $this->line('group_denied', ['code' => $code, 'accounts' => $this->denials]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(TelegramAccount $account): array
    {
        return (array) ($this->pool->getOrCreate($account, ProcessEnum::DriverGroupAdd)->meta ?? []);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function setMeta(TelegramAccount $account, array $changes): void
    {
        $state = $this->pool->getOrCreate($account, ProcessEnum::DriverGroupAdd);

        $state->update(['meta' => [...(array) ($state->meta ?? []), ...$changes]]);
    }

    /*
    |--------------------------------------------------------------------------
    | What the page sees
    |--------------------------------------------------------------------------
    */

    private function begin(string $trigger, ?TelegramInviteGroup $group): void
    {
        $this->now = [
            'run_id' => $this->runId,
            'pid' => getmypid(),
            'trigger' => $trigger,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'group' => $group ? ($group->title ?: $group->link) : null,
            'doing' => 'starting',
            'step' => [],
            'account_id' => null,
            'account' => null,
            'driver' => null,
            'fallback' => false,
            'stop_asked' => false,
            'error' => null,
            'totals' => self::ZERO,
            'lines' => [],
        ];

        $this->line('started', ['trigger' => $trigger, 'group' => $this->now['group']]);
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function finish(array $totals): void
    {
        $this->now = [
            ...$this->now,
            'doing' => 'finished',
            'step' => [],
            'driver' => null,
            'finished_at' => now()->toIso8601String(),
            'totals' => $totals,
        ];

        $this->line('finished', [...$totals, 'stopped' => $this->stopping, 'error' => $this->groupStop]);

        TelegramSetting::set(self::STOP_KEY, null);
    }

    /**
     * @param array<string, mixed> $step
     */
    private function doing(string $what, array $step = []): void
    {
        $this->now['doing'] = $what;
        $this->now['step'] = $step;
        $this->now['said_at'] = now()->toIso8601String();

        $this->save();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function line(string $event, array $context = []): void
    {
        $lines = (array) ($this->now['lines'] ?? []);
        array_unshift($lines, ['at' => now()->toIso8601String(), 'event' => $event, ...$context]);

        $this->now['lines'] = array_slice($lines, 0, self::LINES);
        $this->now['said_at'] = now()->toIso8601String();

        $this->save();
    }

    private function save(): void
    {
        if ($this->runId !== '' && ($this->now['run_id'] ?? null) === $this->runId) {
            TelegramSetting::set(self::NOW_KEY, $this->now);
        }
    }

    private function stopping(): bool
    {
        if ($this->stopping) {
            return true;
        }

        if (time() - $this->stopLookedAt < self::STOP_POLL) {
            return false;
        }

        $this->stopLookedAt = time();
        $asked = TelegramSetting::get(self::STOP_KEY);

        /* Stopped from the panel while no run went on, and the scheduler started one in the same minute. */
        if ($asked === '*' || ($asked !== null && $asked === $this->runId) || $this->settings->paused()) {
            $this->stopping = true;
            $this->now['stop_asked'] = true;
            $this->doing('stopping');
        }

        return $this->stopping;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pause(array $config): void
    {
        $min = max(0, (int) $config['delay_min']);
        $max = max($min, (int) $config['delay_max']);

        if ($max > 0) {
            $seconds = random_int($min, $max);

            $this->doing('pause', ['until' => now()->addSeconds($seconds)->toIso8601String()]);
            $this->snooze($seconds);
        }
    }

    private function snooze(int $seconds): void
    {
        $until = time() + $seconds;

        while (time() < $until && ! $this->stopping()) {
            sleep(min(self::STOP_POLL, max(1, $until - time())));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram's errors
    |--------------------------------------------------------------------------
    */

    private static function accountKind(Throwable $e, string $step): bool
    {
        return in_array(GroupInviteService::classify($e, $step), [
            GroupInviteService::KIND_WAIT,
            GroupInviteService::KIND_SPAM,
            GroupInviteService::KIND_DEAD,
            GroupInviteService::KIND_TRANSIENT,
        ], true);
    }

    private static function rpcError(Throwable $e): ?RPCErrorException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RPCErrorException) {
                return $current;
            }
        }

        return null;
    }

    private static function peerMissing(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PeerNotInDbException) {
                return true;
            }
        }

        return false;
    }

    private static function code(Throwable $e): string
    {
        if ($e instanceof JoinRefused || $e instanceof SessionHung) {
            return mb_substr($e->getMessage(), 0, 120);
        }

        return mb_substr(self::rpcError($e)?->rpc ?? $e->getMessage(), 0, 120);
    }

    private static function waitSeconds(Throwable $e): int
    {
        $error = self::rpcError($e);

        if ($error instanceof RateLimitError) {
            return max(1, $error->getWaitTime());
        }

        return preg_match('/(\d+)/', $error?->rpc ?? '', $m) === 1 ? max(1, (int) $m[1]) : 60;
    }

    private static function secondsUntil(mixed $at): int
    {
        if (! is_string($at) || $at === '') {
            return 0;
        }

        $time = strtotime($at);

        return $time === false ? 0 : max(0, $time - now()->timestamp);
    }
}
