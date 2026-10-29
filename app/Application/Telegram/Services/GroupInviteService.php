<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\DeadSession;
use App\Application\Telegram\Support\SessionSleep;
use App\Application\Telegram\Support\UsernameLookup;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use danog\MadelineProto\API;
use danog\MadelineProto\PeerNotInDbException;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Invites people into the groups added on the page.
 *
 * First each account's own contacts: the access hash belongs to that
 * session, nothing has to be looked up. Once an account has tried all of
 * them it goes on with people of the common base who have a public
 * @username: each one is looked up (contacts.resolveUsername, a call
 * Telegram holds tight, hence its own daily cap), then invited the same way.
 *
 * One call adds a batch (20 by default); an account makes a limited number
 * of calls a day (80 by default). A person is claimed before the call,
 * unique per group, so two accounts never invite the same person and a
 * person is tried once per group.
 *
 * A call that comes back without an error has not added anybody yet:
 * Telegram leaves people out without a word (someone who left the group
 * before, everybody while it holds the account quietly). Each person ends
 * in one of:
 *  - invited: Telegram's "added" message names them, or the check
 *    (channels.getParticipant) finds them joined by the call;
 *  - not_added: could be added, is not in the group;
 *  - already: was in the group before the call;
 *  - failed: cannot be added - Telegram refused because of the person, or
 *    listed them as missing (a link to their DMs or Premium only);
 *  - sent: the check could not be made; the next run checks them first.
 * The group's member count is read when an account comes to it and when
 * it is done with it.
 *
 * What Telegram answers decides whose trouble it is:
 *  - the account's. A short FLOOD_WAIT is waited out on the spot; a longer
 *    one rests the account as long as Telegram said, and it comes back by
 *    itself (still in this run when that is soon). PEER_FLOOD means the
 *    account is spam-limited for invites: it rests a day, two the next time,
 *    up to a week, and its own daily cap drops to 70% of the calls it made
 *    that day; every clean day raises the cap by a quarter again. A dead or
 *    frozen session is taken out.
 *  - the group's. Gone for everybody (a dead link, a full group): it is
 *    switched off with the reason. Not for this account (not an admin,
 *    banned): the other accounts go on with it.
 *  - a person's. A call refused because of one person is split in halves
 *    until that person is found, so the others are not lost. Someone who
 *    refuses everybody (privacy, a deleted account, too many groups) is
 *    marked can_add_to_group = false and no group tries them again.
 */
final class GroupInviteService
{
    public const STEP_SESSION = 'session';

    public const STEP_JOIN = 'join';

    public const STEP_RESOLVE = 'resolve';

    public const STEP_INVITE = 'invite';

    /** Is the person in the group now. */
    public const STEP_CHECK = 'check';

    /** The person was in the group, not in it now. */
    public const NOT_IN_GROUP = 'NOT_IN_GROUP';

    /** Telegram said how long to wait. */
    public const KIND_WAIT = 'wait';

    /** The account is spam-limited: PEER_FLOOD. */
    public const KIND_SPAM = 'spam';

    /** The session is gone or frozen. */
    public const KIND_DEAD = 'dead';

    /** Network, Telegram's own trouble, anything unknown that is not a refusal. */
    public const KIND_TRANSIENT = 'transient';

    /** Nobody can invite into the group any more. */
    public const KIND_GROUP_GONE = 'group_gone';

    /** This account may not invite into the group; another may. */
    public const KIND_GROUP_DENIED = 'group_denied';

    /** The person refuses everybody: no group tries them again. */
    public const KIND_PERSON = 'person';

    /** This person, for this group or this account only. */
    public const KIND_PERSON_HERE = 'person_here';

    public const KIND_ALREADY = 'already';

    /** The person could be added, not by this call: left the group, a mutual contact may bring them back. */
    public const KIND_NOT_ADDED = 'not_added';

    /** The kinds that stop the account, not the group or the person. */
    private const ACCOUNT_KINDS = [self::KIND_WAIT, self::KIND_SPAM, self::KIND_DEAD, self::KIND_TRANSIENT];

    private const SPAM_LIMITED = ['PEER_FLOOD', 'USER_RESTRICTED'];

    /** A frozen account still logs in but can do nothing. */
    private const FROZEN = ['FROZEN_METHOD_INVALID', 'FROZEN_PARTICIPANT_MISSING'];

    private const PERSON_REFUSES = [
        'USER_PRIVACY_RESTRICTED',
        'USER_CHANNELS_TOO_MUCH',
        'INPUT_USER_DEACTIVATED',
        'USER_BOT',
        'USER_IS_BOT',
        'BOT_GROUPS_BLOCKED',
        'PRIVACY_PREMIUM_REQUIRED',
    ];

    private const NOT_ADDED = ['USER_NOT_MUTUAL_CONTACT'];

    private const PERSON_HERE = [
        'USER_KICKED',
        'USER_BLOCKED',
        'USER_ID_INVALID',
        'PEER_ID_INVALID',
        'CHAT_MEMBER_ADD_FAILED',
        'USERNAME_NOT_OCCUPIED',
        'USERNAME_INVALID',
    ];

    private const GROUP_GONE = [
        'CHANNEL_INVALID',
        'CHAT_INVALID',
        'CHANNEL_PUBLIC_GROUP_NA',
        'INVITE_HASH_EXPIRED',
        'INVITE_HASH_INVALID',
        'USERS_TOO_MUCH',
    ];

    private const GROUP_DENIED = [
        'CHAT_ADMIN_REQUIRED',
        'CHAT_ADMIN_INVITE_REQUIRED',
        'CHAT_WRITE_FORBIDDEN',
        'CHAT_FORBIDDEN',
        'CHANNEL_PRIVATE',
        'USER_BANNED_IN_CHANNEL',
        'USER_NOT_PARTICIPANT',
        'INVITE_REQUEST_SENT',
        'CHANNELS_TOO_MUCH',
    ];

    /** A FLOOD_WAIT up to this is waited out on the spot, at most this many times in a row. */
    private const WAIT_IN_PLACE = 60;

    private const WAIT_RETRIES = 3;

    /** A longer one, up to this, is waited for at the end of the run. Seconds. */
    private const WAIT_COME_BACK = 900;

    /** Over what Telegram said, seconds. */
    private const WAIT_EXTRA = [15, 45];

    /** PEER_FLOOD: a day's rest the first time, doubled every time after, a week at most. */
    private const SPAM_REST_HOURS = 24;

    private const SPAM_REST_MAX_HOURS = 168;

    /** After a PEER_FLOOD the account's own daily cap: this share of the calls it made that day. */
    private const SPAM_CAP_SHARE = 0.7;

    /** A clean day raises a lowered cap by this, one call at least. */
    private const CAP_GROWTH = 1.25;

    /** Calls in a row that added nobody: the account rests until tomorrow. */
    private const MAX_EMPTY_CALLS = 5;

    /** This many accounts that may not invite into a group, none that may: the run leaves it. */
    private const DENIED_ACCOUNTS_LIMIT = 3;

    /** Seconds between two @username lookups. */
    private const RESOLVE_PAUSE = [2, 5];

    /** A pending claim older than this is a crashed run, not a live one. */
    private const STALE_PENDING_MINUTES = 180;

    /** How far back a stop's counters look for the previous stop, days. */
    private const SNAPSHOT_DAYS = 30;

    /** A member who joined this long before the call, or later, joined by it: two clocks never agree. Seconds. */
    private const JOIN_SLACK = 300;

    /** Between two people looked up in the group, milliseconds. */
    private const CHECK_PAUSE_MS = [700, 1500];

    /** What the people of a call, or of a check, came to; the keys are the statuses. */
    private const ZERO = ['invited' => 0, 'not_added' => 0, 'already' => 0, 'sent' => 0, 'failed' => 0];

    private string $runId = '';

    /** @var array<int, true> accounts that ran into trouble in this run: no success is written for them */
    private array $troubled = [];

    /** @var array<string, true> accountId:groupId already joined in this run */
    private array $joined = [];

    /** @var array<int, true> groups this run leaves alone */
    private array $blocked = [];

    /** @var array<string, true> accountId:groupId - this account may not invite there */
    private array $denied = [];

    /** @var array<int, true> groups somebody was added to in this run */
    private array $invitedInto = [];

    /** @var list<int>|null our own accounts' Telegram ids: never invited */
    private ?array $ownIds = null;

    /** @var array<int, string> groups whose members this run cannot look up, with why */
    private array $blind = [];

    /**
     * @var array<string, array{group: TelegramInviteGroup, before: int|null, invited: int, not_added: int, already: int, sent: int, failed: int}>
     *      accountId:groupId - the member count when the account came to the group, what its calls did there
     */
    private array $touched = [];

    /** @var array<int, true> groups whose "before" this run has written */
    private array $counted = [];

    public function __construct(
        private readonly MadelineService $madeline,
        private readonly TelegramAccountProcessService $pool,
        private readonly GroupInviteSettings $settings,
        private readonly UsernameLookup $usernames,
    ) {}

    /**
     * Every account first checks the people it sent earlier and has not
     * checked yet, then invites. $checkOnly: the check alone, nobody invited.
     *
     * @param callable(string): void|null $output
     *
     * @return array{accounts: int, batches: int, invited: int, not_added: int, already: int, sent: int, failed: int}
     */
    public function run(?callable $output = null, string $trigger = 'manual', bool $checkOnly = false): array
    {
        $this->runId = (string) Str::uuid();
        $this->troubled = [];
        $this->joined = [];
        $this->blocked = [];
        $this->denied = [];
        $this->invitedInto = [];
        $this->ownIds = null;
        $this->blind = [];
        $this->touched = [];
        $this->counted = [];

        $say = $output ?? static function (string $line): void {};

        $config = $this->settings->current();
        $totals = ['accounts' => 0, 'batches' => 0, ...self::ZERO];

        $groups = TelegramInviteGroup::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $this->log('started', $checkOnly ? 'Check started' : 'Invite started', null, null, [
            'trigger' => $trigger,
            'check_only' => $checkOnly,
            'groups' => $groups->pluck('link')->all(),
            'batch_size' => (int) $config['batch_size'],
            'batches_per_day' => (int) $config['batches_per_day'],
            'use_usernames' => (bool) $config['use_usernames'],
            'usernames_per_day' => (int) $config['usernames_per_day'],
            'excluded_accounts' => $config['excluded_accounts'],
        ]);

        if ($groups->isEmpty() && ! $checkOnly) {
            $this->log('finished', 'No active groups, nothing to do');

            return $totals;
        }

        /* Left out in the panel, and anyone this run has already finished with. */
        $usedAccountIds = array_map('intval', (array) $config['excluded_accounts']);

        /** @var array<int, int> account id => when its short flood wait is over */
        $comeBack = [];

        while (true) {
            /* A check only reads: an account resting from invites may make it. */
            $account = $this->pool->claimRandomAvailableAccount(
                ProcessEnum::GroupInvite,
                array_values(array_unique(array_merge(
                    $usedAccountIds,
                    $this->busyElsewhere(),
                    $checkOnly ? [] : $this->restingIds(),
                ))),
            );

            if (! $account) {
                if ($comeBack === []) {
                    $this->log('skipped', 'No more accounts available');

                    break;
                }

                /* Everybody else is done: wait for the one whose flood wait ends first. */
                asort($comeBack);
                $id = (int) array_key_first($comeBack);
                $left = $comeBack[$id] - time();
                unset($comeBack[$id]);

                if ($left > 0) {
                    $say("Waiting {$left}s for account #{$id}");
                    sleep($left);
                }

                $usedAccountIds = array_values(array_diff($usedAccountIds, [$id]));

                continue;
            }

            $usedAccountIds[] = $account->id;

            if (! $this->hasSent($account) && ($checkOnly || ! $this->hasWork($account, $groups, $config))) {
                $this->pool->release($account, ProcessEnum::GroupInvite);

                continue;
            }

            $totals['accounts']++;
            $say("Account #{$account->id} {$account->phone}");

            $api = null;

            try {
                $api = $this->madeline->for($account);

                if (! $api) {
                    throw $this->madeline->lastError
                        ?? new RuntimeException('Failed to start MadelineProto.');
                }

                $result = $this->runAccount($api, $account, $groups, $config, $say, $checkOnly);

                $totals['batches'] += $result['calls'];
                self::add($totals, $result);

                if (! isset($this->troubled[$account->id])) {
                    $this->pool->registerSuccess($account, ProcessEnum::GroupInvite);
                }
            } catch (Throwable $e) {
                $this->accountTrouble($account, $e, self::classify($e, self::STEP_SESSION), $config, null, [
                    'step' => self::STEP_SESSION,
                ]);

                $say("  failed: {$e->getMessage()}");
            } finally {
                SessionSleep::put($api, ['account_id' => $account->id]);

                $this->pool->release($account, ProcessEnum::GroupInvite);
            }

            $meta = $this->meta($account);
            $left = self::secondsUntil($meta['rest_until'] ?? null);

            if (($meta['rest_kind'] ?? null) === self::KIND_WAIT && $left > 0 && $left <= self::WAIT_COME_BACK) {
                $comeBack[$account->id] = time() + $left + 1;
            }
        }

        $this->log('finished', 'Invite finished', null, null, $totals);

        return $totals;
    }

    /**
     * Whose trouble an error is, by the step it came from. Every code is
     * matched whole: INPUT_USER_DEACTIVATED is the person, USER_DEACTIVATED
     * is our own account.
     */
    public static function classify(Throwable $e, string $step = self::STEP_INVITE): string
    {
        $error = self::rpcError($e);

        if ($error instanceof RateLimitError) {
            return self::KIND_WAIT;
        }

        $rpc = $error?->rpc ?? '';

        if (str_starts_with($rpc, 'FLOOD_WAIT') || str_starts_with($rpc, 'FLOOD_PREMIUM_WAIT')) {
            return self::KIND_WAIT;
        }

        if (in_array($rpc, self::SPAM_LIMITED, true)) {
            return self::KIND_SPAM;
        }

        if (in_array($rpc, self::FROZEN, true)) {
            return self::KIND_DEAD;
        }

        if ($rpc === 'USER_ALREADY_PARTICIPANT') {
            return self::KIND_ALREADY;
        }

        /* The group's own @name, not a person's. */
        if ($step === self::STEP_JOIN && in_array($rpc, ['USERNAME_NOT_OCCUPIED', 'USERNAME_INVALID'], true)) {
            return self::KIND_GROUP_GONE;
        }

        foreach ([
            self::KIND_PERSON => self::PERSON_REFUSES,
            self::KIND_NOT_ADDED => self::NOT_ADDED,
            self::KIND_PERSON_HERE => self::PERSON_HERE,
            self::KIND_GROUP_GONE => self::GROUP_GONE,
            self::KIND_GROUP_DENIED => self::GROUP_DENIED,
        ] as $kind => $codes) {
            if (in_array($rpc, $codes, true)) {
                return $kind;
            }
        }

        if (DeadSession::code($e) !== null) {
            return self::KIND_DEAD;
        }

        if (self::peerMissing($e)) {
            return self::KIND_PERSON_HERE;
        }

        /* Any other refusal of the request itself: somebody in it, most likely. */
        if ($error !== null && in_array($error->getCode(), [400, 403], true)) {
            return $step === self::STEP_JOIN ? self::KIND_GROUP_DENIED : self::KIND_PERSON_HERE;
        }

        return self::KIND_TRANSIENT;
    }

    /**
     * People this account can still invite into this group: in its contacts,
     * not a bot, not refusing everybody, not already tried for the group.
     *
     * @return Collection<int, TelegramScrapedUser>
     */
    public function candidates(TelegramAccount $account, TelegramInviteGroup $group, int $limit): Collection
    {
        $this->releaseStale($group);

        $ids = DB::table('telegram_account_contacts')
            ->where('telegram_account_id', $account->id)
            ->whereIn('status', ['added', 'skipped'])
            ->select('scraped_user_id')
            ->union(
                DB::table('telegram_account_contact_links')
                    ->where('telegram_account_id', $account->id)
                    ->select('scraped_user_id'),
            );

        return $this->untried($group)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * People of the common base with a public @username, for any account
     * whose own contacts have run out. The newest first: their names are
     * the likeliest to still be theirs.
     *
     * @return Collection<int, TelegramScrapedUser>
     */
    public function usernameCandidates(TelegramInviteGroup $group, int $limit): Collection
    {
        $this->releaseStale($group);

        return $this->untried($group)
            ->withUsername()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Take these people for this batch, or skip whoever another account
     * already took. Returns only the people this call actually claimed.
     *
     * @param Collection<int, TelegramScrapedUser> $users
     *
     * @return Collection<int, TelegramScrapedUser>
     */
    public function claim(
        TelegramAccount $account,
        TelegramInviteGroup $group,
        Collection $users,
        string $batchKey,
        string $via = TelegramGroupInvite::VIA_CONTACT,
    ): Collection {
        if ($users->isEmpty()) {
            return collect();
        }

        $now = now();

        DB::table('telegram_group_invites')->insertOrIgnore($users->map(fn (TelegramScrapedUser $user) => [
            'invite_group_id' => $group->id,
            'scraped_user_id' => $user->id,
            'telegram_account_id' => $account->id,
            'status' => TelegramGroupInvite::STATUS_PENDING,
            'via' => $via,
            'error' => null,
            'batch_key' => $batchKey,
            'attempted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return TelegramScrapedUser::query()
            ->whereIn(
                'id',
                TelegramGroupInvite::query()->where('batch_key', $batchKey)->select('scraped_user_id'),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * The call came back. Anyone Telegram lists as missing cannot be added -
     * their privacy keeps us out, for every group. The rest stand where the
     * check found them; sent when it found nothing out.
     *
     * @param array<int, string>                                    $refused  telegram user id => error
     * @param array<int, array{status: string, error: string|null}> $verdicts telegram user id => where the person stands
     *
     * @return array{invited: int, not_added: int, already: int, sent: int, failed: int}
     */
    public function settle(string $batchKey, array $refused, array $verdicts = []): array
    {
        $pending = TelegramGroupInvite::query()
            ->where('batch_key', $batchKey)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->with('user:id,telegram_user_id')
            ->get();

        return $this->record($pending, $refused, $verdicts, now());
    }

    /**
     * The batch failed for a reason that belongs to these people. When it
     * is a refusal of everybody, no group tries them again.
     */
    public function failBatch(string $batchKey, string $error, bool $refusesEverybody = false): int
    {
        $ids = TelegramGroupInvite::query()
            ->where('batch_key', $batchKey)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->pluck('scraped_user_id')
            ->all();

        $failed = TelegramGroupInvite::query()
            ->where('batch_key', $batchKey)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->update([
                'status' => TelegramGroupInvite::STATUS_FAILED,
                'error' => mb_substr($error, 0, 120),
                'attempted_at' => now(),
                'checked_at' => now(),
                'updated_at' => now(),
            ]);

        if ($refusesEverybody) {
            $this->mark($ids, false, $error);
        }

        return $failed;
    }

    /**
     * Give the batch back. A flood or a group the account cannot invite
     * into is not these people's fault.
     */
    public function releaseBatch(string $batchKey): void
    {
        TelegramGroupInvite::query()
            ->where('batch_key', $batchKey)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->delete();
    }

    /**
     * Invite calls this account made today. A call refused for a flood is
     * not one: Telegram did not take it.
     */
    public function callsToday(int $accountId): int
    {
        return TelegramGroupInviteLog::callsToday([$accountId])[$accountId] ?? 0;
    }

    /**
     * People this account looked up by their username today.
     */
    public function resolvesToday(int $accountId): int
    {
        return TelegramGroupInvite::query()
            ->where('telegram_account_id', $accountId)
            ->where('via', TelegramGroupInvite::VIA_USERNAME)
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('attempted_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * The calls this account may make today: the panel's number, or less
     * while it comes back from a PEER_FLOOD.
     *
     * @param array<string, mixed> $config
     */
    public function dailyCap(TelegramAccount $account, array $config): int
    {
        $limit = max(1, (int) $config['batches_per_day']);
        $meta = $this->meta($account);

        /*
         * The account reached its cap on an earlier day without a
         * PEER_FLOOD: a quarter more now, and one flood fewer to remember.
         */
        if (isset($meta['clean_day']) && $meta['clean_day'] < now()->toDateString()) {
            $cap = isset($meta['cap']) ? max((int) $meta['cap'] + 1, (int) ceil((int) $meta['cap'] * self::CAP_GROWTH)) : null;
            $floods = max(0, (int) ($meta['peer_floods'] ?? 0) - 1);

            $meta = $this->setMeta($account, [
                'cap' => $cap !== null && $cap < $limit ? $cap : null,
                'peer_floods' => $floods > 0 ? $floods : null,
                'clean_day' => null,
            ]);
        }

        return isset($meta['cap']) ? max(1, min($limit, (int) $meta['cap'])) : $limit;
    }

    /**
     * PEER_FLOOD: Telegram has stopped this account from inviting for a
     * while. It rests a day, two the next time, up to a week; its daily cap
     * drops to 70% of what it made today, half its cap if it made nothing.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context what was refused: the step, the way, the size
     */
    public function spamLimited(TelegramAccount $account, array $config, string $code, ?int $groupId = null, array $context = []): void
    {
        $times = (int) ($this->meta($account)['peer_floods'] ?? 0) + 1;
        $hours = min(self::SPAM_REST_MAX_HOURS, self::SPAM_REST_HOURS * 2 ** ($times - 1));

        $cap = $this->dailyCap($account, $config);
        $calls = min($this->callsToday($account->id), $cap);

        $this->rest($account, $hours * 3600 + random_int(0, 1800), self::KIND_SPAM, $code, $groupId, [
            'peer_floods' => $times,
            'cap' => $calls > 0 ? max(1, (int) floor($calls * self::SPAM_CAP_SHARE)) : max(1, intdiv($cap, 2)),
            'clean_day' => null,
        ], $context);
    }

    /**
     * What the account had done when it was stopped: since its last stop (a
     * month back at most) and today. Kept on the stop's log line, so the
     * analysis can tell after how many calls and lookups, and of what kind,
     * Telegram held the account.
     *
     * @param array<string, mixed> $context what was refused: the step, the way, the size
     *
     * @return array<string, mixed>
     */
    public function snapshot(TelegramAccount $account, array $context = []): array
    {
        $since = TelegramGroupInviteLog::query()
            ->where('telegram_account_id', $account->id)
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->where('created_at', '>=', now()->subDays(self::SNAPSHOT_DAYS))
            ->max('created_at');

        $from = $since !== null ? Carbon::parse($since) : now()->subDays(self::SNAPSHOT_DAYS);

        $run = $this->workSince($account->id, $from);
        $day = $this->workSince($account->id, now()->startOfDay());

        /* Left out without a word (NOT_IN_GROUP) counts too: that is how a quiet hold shows. */
        $errors = TelegramGroupInvite::query()
            ->where('telegram_account_id', $account->id)
            ->whereIn('status', [TelegramGroupInvite::STATUS_FAILED, TelegramGroupInvite::STATUS_NOT_ADDED])
            ->whereNotNull('error')
            ->where('attempted_at', '>', $from)
            ->selectRaw('error, COUNT(*) as total')
            ->groupBy('error')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'error')
            ->map(fn ($total) => (int) $total)
            ->all();

        $meta = $this->meta($account);

        return [
            ...$context,
            'since' => $since !== null ? $from->toIso8601String() : null,
            'minutes' => $run['first'] !== null ? intdiv(now()->timestamp - $run['first']->timestamp, 60) : 0,
            'calls' => $run['calls'],
            'invited' => $run['invited'],
            'not_added' => $run['not_added'],
            'already' => $run['already'],
            'failed' => $run['failed'],
            'username_calls' => $run['username_calls'],
            'lookups' => $run['lookups'],
            'waits' => $run['waits'],
            'wait_seconds' => $run['wait_seconds'],
            'calls_today' => $day['calls'],
            'invited_today' => $day['invited'],
            'not_added_today' => $day['not_added'],
            'failed_today' => $day['failed'],
            'lookups_today' => $day['lookups'],
            'errors' => $errors,
            /* The cap it worked under, null for the panel's own; the PEER_FLOODs it had behind it. */
            'cap' => isset($meta['cap']) ? (int) $meta['cap'] : null,
            'peer_floods_before' => (int) ($meta['peer_floods'] ?? 0),
            'authorized_days' => $account->authorized_at !== null
                ? (int) $account->authorized_at->diffInDays(now(), true)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(TelegramAccount $account): array
    {
        $meta = TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::GroupInvite->value)
            ->value('meta');

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * The people this account sent earlier and has not checked yet first,
     * then the invites. The member counts of the groups it worked in are
     * read once more at the end.
     *
     * @return array{calls: int, invited: int, not_added: int, already: int, sent: int, failed: int}
     */
    private function runAccount(
        API $api,
        TelegramAccount $account,
        Collection $groups,
        array $config,
        callable $say,
        bool $checkOnly = false,
    ): array {
        $result = ['calls' => 0, ...self::ZERO];
        $empty = 0;

        $checked = $this->checkSent($api, $account, $config, $say);
        self::add($result, $checked);

        if ($checked['stop'] || $checkOnly) {
            return $result;
        }

        while (true) {
            if ($this->callsToday($account->id) >= $this->dailyCap($account, $config)) {
                $this->cleanDay($account);
                $say('  daily cap reached');

                break;
            }

            $chunk = $this->nextChunk($account, $groups, $config);

            if ($chunk === null) {
                break;
            }

            [$group, $via, $people] = $chunk;

            $done = $this->work($api, $account, $group, $via, $people, $config, $say);

            $result['calls'] += $done['calls'];
            self::add($result, $done);

            if ($done['stop']) {
                break;
            }

            $empty = $done['invited'] > 0 ? 0 : $empty + $done['calls'];

            /*
             * Call after call and nobody added: Telegram may be holding the
             * account quietly. It stops for today instead of burning more.
             */
            if ($empty >= self::MAX_EMPTY_CALLS) {
                $this->rest(
                    $account,
                    now()->addDay()->startOfDay()->timestamp - now()->timestamp + random_int(300, 1800),
                    'empty',
                    'NOBODY_ADDED',
                    context: ['step' => self::STEP_INVITE, 'empty_calls' => $empty],
                );
                $say("  {$empty} calls without anybody added, the account rests until tomorrow");

                break;
            }

            if ($this->callsToday($account->id) < $this->dailyCap($account, $config)) {
                $this->pause($config);
            }
        }

        $this->countAfter($api, $account, $say);

        return $result;
    }

    /**
     * The next batch this account takes: its own contacts first, then people
     * with a public @username, as many as its lookups left today allow.
     *
     * @param Collection<int, TelegramInviteGroup> $groups
     * @param array<string, mixed>                 $config
     *
     * @return array{0: TelegramInviteGroup, 1: string, 2: Collection<int, TelegramScrapedUser>}|null
     */
    private function nextChunk(TelegramAccount $account, Collection $groups, array $config): ?array
    {
        $size = max(1, (int) $config['batch_size']);
        $open = $this->openGroups($account, $groups);

        foreach ($open as $group) {
            $claimed = $this->claim($account, $group, $this->candidates($account, $group, $size), (string) Str::uuid());

            if ($claimed->isNotEmpty()) {
                return [$group, TelegramGroupInvite::VIA_CONTACT, $claimed];
            }
        }

        $left = $this->usernamesLeft($account, $config);

        if ($left <= 0) {
            return null;
        }

        foreach ($open as $group) {
            $claimed = $this->claim(
                $account,
                $group,
                $this->usernameCandidates($group, min($size, $left)),
                (string) Str::uuid(),
                TelegramGroupInvite::VIA_USERNAME,
            );

            if ($claimed->isNotEmpty()) {
                return [$group, TelegramGroupInvite::VIA_USERNAME, $claimed];
            }
        }

        return null;
    }

    /**
     * One claimed batch: join, look the @usernames up, invite, check who
     * is in. A call refused because of somebody in it is split in halves
     * until that one is found.
     *
     * @param Collection<int, TelegramScrapedUser> $people
     * @param array<string, mixed>                 $config
     *
     * @return array{calls: int, invited: int, not_added: int, already: int, sent: int, failed: int, stop: bool}
     */
    private function work(
        API $api,
        TelegramAccount $account,
        TelegramInviteGroup $group,
        string $via,
        Collection $people,
        array $config,
        callable $say,
    ): array {
        $done = ['calls' => 0, ...self::ZERO, 'stop' => false];

        try {
            $this->ensureJoined($api, $group, $account, $say);
        } catch (Throwable $e) {
            $this->releasePeople($group, $people);
            $done['stop'] = $this->joinTrouble($account, $group, $e, $config, $say);

            return $done;
        }

        if (isset($this->blocked[$group->id])) {
            $this->releasePeople($group, $people);

            return $done;
        }

        if ($group->isInvite() && ! $group->telegram_chat_id) {
            $this->releasePeople($group, $people);
            $this->deny($account, $group, 'NO_CHAT_ID');

            return $done;
        }

        $this->countBefore($api, $account, $group);

        if ($via === TelegramGroupInvite::VIA_USERNAME) {
            [$people, $done['stop']] = $this->resolve($api, $account, $group, $people, $config, $say, $done);
        }

        /** @var list<Collection<int, TelegramScrapedUser>> $parts */
        $parts = $people->isEmpty() ? [] : [$people->values()];

        while ($parts !== []) {
            $part = array_shift($parts);

            if ($done['calls'] > 0) {
                if ($this->callsToday($account->id) >= $this->dailyCap($account, $config)) {
                    $this->releasePeople($group, collect([$part, ...$parts])->flatten(1));

                    break;
                }

                $this->pause($config);
            }

            $key = $this->rekey($group, $part);
            $sentAt = now()->timestamp;

            try {
                $context = ['step' => self::STEP_INVITE, 'via' => $via, 'size' => $part->count()];

                $response = $this->patiently(fn () => $api->channels->inviteToChannel(
                    channel: $this->peer($group),
                    users: $part->map(fn (TelegramScrapedUser $user) => (int) $user->telegram_user_id)->values()->all(),
                    floodWaitLimit: 0,
                ), $say, $account, $group, $context);
            } catch (Throwable $e) {
                $kind = self::classify($e, self::STEP_INVITE);
                $code = self::code($e);

                if (in_array($kind, self::ACCOUNT_KINDS, true)) {
                    $this->releasePeople($group, collect([$part, ...$parts])->flatten(1));
                    $this->accountTrouble($account, $e, $kind, $config, $group->id, $context);
                    $say("  {$code}: the account stops");
                    $done['stop'] = true;

                    return $done;
                }

                if ($kind === self::KIND_GROUP_GONE || $kind === self::KIND_GROUP_DENIED) {
                    $this->releasePeople($group, collect([$part, ...$parts])->flatten(1));
                    $kind === self::KIND_GROUP_GONE
                        ? $this->groupGone($group, $code, $account->id)
                        : $this->deny($account, $group, $code);
                    $say("  {$group->link}: {$code}");

                    return $done;
                }

                /* Telegram took the call and refused it because of somebody in it. */
                $done['calls']++;

                if ($part->count() > 1) {
                    $half = (int) ceil($part->count() / 2);
                    array_unshift($parts, $part->slice(0, $half)->values(), $part->slice($half)->values());

                    $this->logCall($account, $group, $key, $via, $part->count(), self::ZERO, "{$code}, split");

                    continue;
                }

                $settled = match ($kind) {
                    self::KIND_ALREADY => $this->settle($key, [], self::verdicts($part, TelegramGroupInvite::STATUS_ALREADY)),
                    self::KIND_NOT_ADDED => $this->settle($key, [], self::verdicts($part, TelegramGroupInvite::STATUS_NOT_ADDED, $code)),
                    default => [...self::ZERO, 'failed' => $this->failBatch($key, $code, $kind === self::KIND_PERSON)],
                };

                $this->logCall($account, $group, $key, $via, 1, $settled, $code);
                $this->tally($done, $account, $group, $settled);

                continue;
            }

            $done['calls']++;

            $refused = $this->missingIds($response);
            $added = $this->addedIds($response);

            [$verdicts, $trouble] = $this->verify(
                $api,
                $account,
                $group,
                $part->reject(fn (TelegramScrapedUser $user) => isset($refused[(int) $user->telegram_user_id])),
                $added,
                $sentAt,
                $config,
                $say,
            );

            $settled = $this->settle($key, $refused, $verdicts);

            $this->logCall($account, $group, $key, $via, $part->count(), $settled, null, [
                /* How many Telegram's own "added" message named; the rest were looked up. */
                'named' => count(array_intersect_key($added, array_flip($part->map(fn (TelegramScrapedUser $user) => (int) $user->telegram_user_id)->all()))),
            ]);
            $this->tally($done, $account, $group, $settled);

            $say("  {$group->link}: " . self::summary($settled) . ($via === TelegramGroupInvite::VIA_USERNAME ? ' (@username)' : ''));

            if ($trouble !== null) {
                $kind = self::classify($trouble, self::STEP_CHECK);

                $this->releasePeople($group, collect($parts)->flatten(1));
                $this->accountTrouble($account, $trouble, $kind, $config, $group->id, ['step' => self::STEP_CHECK, 'via' => $via, 'size' => $part->count()]);
                $say('  check: ' . self::code($trouble) . ', the account stops');
                $done['stop'] = true;

                return $done;
            }
        }

        return $done;
    }

    /**
     * Where each person stands after a call, or after a call made earlier:
     * in the group by it, in it before, or not in it. Whoever Telegram's own
     * "added" message names is in; everybody else is looked up in the group
     * one by one. Someone who cannot be looked up gets no verdict and stays
     * sent, for a later check.
     *
     * @param Collection<int, TelegramScrapedUser> $people
     * @param array<int, true>                     $added telegram ids Telegram's message names
     * @param int|array<int, int>                  $since when the call was made: one time for all, or by telegram id
     * @param array<string, mixed>                 $config
     *
     * @return array{0: array<int, array{status: string, error: string|null}>, 1: Throwable|null} by telegram id; the account's own trouble that broke the check off
     */
    private function verify(
        API $api,
        TelegramAccount $account,
        TelegramInviteGroup $group,
        Collection $people,
        array $added,
        int|array $since,
        array $config,
        callable $say,
    ): array {
        $verdicts = [];
        $looked = 0;
        $context = ['step' => self::STEP_CHECK, 'size' => $people->count()];

        foreach ($people as $person) {
            $id = (int) $person->telegram_user_id;

            if (isset($added[$id])) {
                $verdicts[$id] = self::verdict(TelegramGroupInvite::STATUS_INVITED);

                continue;
            }

            if (isset($this->blind[$group->id])) {
                $verdicts[$id] = self::verdict(TelegramGroupInvite::STATUS_SENT, $this->blind[$group->id]);

                continue;
            }

            if ($looked++ > 0) {
                $this->checkPause($config);
            }

            try {
                $verdicts[$id] = $this->presence($api, $account, $group, $id, is_array($since) ? ($since[$id] ?? 0) : $since, $say, $context);
            } catch (Throwable $e) {
                $kind = self::classify($e, self::STEP_CHECK);

                if (in_array($kind, self::ACCOUNT_KINDS, true)) {
                    return [$verdicts, $e];
                }

                $code = 'CHECK_' . (self::peerMissing($e) ? 'PEER_ID_INVALID' : self::code($e));

                /* The group shows its members to its admins only: nobody in it can be looked up. */
                if ($kind === self::KIND_GROUP_DENIED || $kind === self::KIND_GROUP_GONE) {
                    $this->blind[$group->id] = $code;
                }

                $verdicts[$id] = self::verdict(TelegramGroupInvite::STATUS_SENT, $code);
            }
        }

        return [$verdicts, null];
    }

    /**
     * Is the person in the group, and since when.
     *
     * @param array<string, mixed> $context
     *
     * @return array{status: string, error: string|null}
     */
    private function presence(
        API $api,
        TelegramAccount $account,
        TelegramInviteGroup $group,
        int $userId,
        int $since,
        callable $say,
        array $context,
    ): array {
        try {
            $answer = $this->patiently(fn () => $api->channels->getParticipant(
                channel: $this->peer($group),
                participant: $userId,
                floodWaitLimit: 0,
            ), $say, $account, $group, $context);
        } catch (Throwable $e) {
            if (self::rpcError($e)?->rpc === 'USER_NOT_PARTICIPANT') {
                return self::verdict(TelegramGroupInvite::STATUS_NOT_ADDED, self::NOT_IN_GROUP);
            }

            throw $e;
        }

        $member = is_array($answer) && is_array($answer['participant'] ?? null) ? $answer['participant'] : [];
        $type = $member['_'] ?? null;

        return match (true) {
            $type === null => self::verdict(TelegramGroupInvite::STATUS_SENT, 'CHECK_NO_ANSWER'),
            $type === 'channelParticipantLeft',
            $type === 'channelParticipantBanned' && ! empty($member['left']) => self::verdict(TelegramGroupInvite::STATUS_NOT_ADDED, self::NOT_IN_GROUP),
            isset($member['date']) && (int) $member['date'] >= $since - self::JOIN_SLACK => self::verdict(TelegramGroupInvite::STATUS_INVITED),
            default => self::verdict(TelegramGroupInvite::STATUS_ALREADY),
        };
    }

    /**
     * People this account sent earlier whose check broke off, or who were
     * sent before checks were made: each is looked up in the group now.
     *
     * @param array<string, mixed> $config
     *
     * @return array{invited: int, not_added: int, already: int, sent: int, failed: int, stop: bool}
     */
    private function checkSent(API $api, TelegramAccount $account, array $config, callable $say): array
    {
        $done = [...self::ZERO, 'stop' => false];

        $byGroup = TelegramGroupInvite::query()
            ->where('telegram_account_id', $account->id)
            ->where('status', TelegramGroupInvite::STATUS_SENT)
            ->with(['user', 'group'])
            ->orderBy('id')
            ->get()
            ->filter(fn (TelegramGroupInvite $row) => $row->user !== null && $row->group !== null)
            ->groupBy('invite_group_id');

        foreach ($byGroup as $rows) {
            /** @var TelegramInviteGroup $group */
            $group = $rows->first()->group;

            if (isset($this->blocked[$group->id]) || isset($this->denied[$account->id . ':' . $group->id])) {
                continue;
            }

            try {
                $this->ensureJoined($api, $group, $account, $say);
            } catch (Throwable $e) {
                if ($this->joinTrouble($account, $group, $e, $config, $say)) {
                    $done['stop'] = true;

                    return $done;
                }

                continue;
            }

            [$verdicts, $trouble] = $this->verify(
                $api,
                $account,
                $group,
                $rows->pluck('user')->values(),
                [],
                $rows->mapWithKeys(fn (TelegramGroupInvite $row) => [
                    (int) $row->user->telegram_user_id => (int) $row->attempted_at?->timestamp,
                ])->all(),
                $config,
                $say,
            );

            $settled = $this->record($rows, [], $verdicts, null);
            self::add($done, $settled);

            $this->log(TelegramGroupInviteLog::EVENT_CHECK, self::summary($settled), $account->id, $group->id, [
                'size' => $rows->count(),
                ...$settled,
            ]);
            $say("  {$group->link}: checked {$rows->count()}: " . self::summary($settled));

            if ($trouble !== null) {
                $this->accountTrouble($account, $trouble, self::classify($trouble, self::STEP_CHECK), $config, $group->id, [
                    'step' => self::STEP_CHECK,
                    'size' => $rows->count(),
                ]);
                $say('  check: ' . self::code($trouble) . ', the account stops');
                $done['stop'] = true;

                return $done;
            }
        }

        return $done;
    }

    private function hasSent(TelegramAccount $account): bool
    {
        return TelegramGroupInvite::query()
            ->where('telegram_account_id', $account->id)
            ->where('status', TelegramGroupInvite::STATUS_SENT)
            ->exists();
    }

    /**
     * The group's member count when this account first comes to it in the
     * run. The run's first one is the group's "before".
     */
    private function countBefore(API $api, TelegramAccount $account, TelegramInviteGroup $group): void
    {
        $key = $account->id . ':' . $group->id;

        if (isset($this->touched[$key])) {
            return;
        }

        $members = $this->members($api, $group);

        $this->touched[$key] = ['group' => $group, 'before' => $members, ...self::ZERO];

        if (! isset($this->counted[$group->id])) {
            $this->counted[$group->id] = true;

            $group->update([
                'members_before' => $members,
                'members_after' => null,
                'members_checked_at' => $members !== null ? now() : $group->members_checked_at,
            ]);
        }
    }

    /**
     * The account is done: the member count of every group it worked in,
     * now, next to what it was when it came and what its calls did there.
     */
    private function countAfter(API $api, TelegramAccount $account, callable $say): void
    {
        foreach ($this->touched as $key => $state) {
            if (! str_starts_with($key, $account->id . ':')) {
                continue;
            }

            unset($this->touched[$key]);

            $group = $state['group'];
            $before = $state['before'];
            $after = $this->members($api, $group);

            $this->log(
                TelegramGroupInviteLog::EVENT_MEMBERS,
                'Members ' . ($before ?? '?') . ' -> ' . ($after ?? '?') . ': ' . self::summary($state),
                $account->id,
                $group->id,
                [
                    'before' => $before,
                    'after' => $after,
                    'grew' => $before !== null && $after !== null ? $after - $before : null,
                    ...array_intersect_key($state, self::ZERO),
                ],
            );

            $say("  {$group->link}: members " . ($before ?? '?') . ' -> ' . ($after ?? '?'));

            if ($after !== null) {
                $group->update(['members_after' => $after, 'members_checked_at' => now()]);
            }
        }
    }

    /**
     * How many people are in the group now; null when the account may not
     * see it. Not getFullInfo: MadelineProto keeps that for an hour.
     */
    private function members(API $api, TelegramInviteGroup $group): ?int
    {
        try {
            $page = $api->channels->getParticipants(
                channel: $this->peer($group),
                filter: ['_' => 'channelParticipantsRecent'],
                offset: 0,
                limit: 1,
                floodWaitLimit: 0,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($page) && isset($page['count']) ? (int) $page['count'] : null;
    }

    /**
     * Look every @username up before the invite: the session has to learn
     * the person's access hash, and the name may be somebody else's by now.
     *
     * @param Collection<int, TelegramScrapedUser>                $people
     * @param array<string, mixed>                                $config
     * @param array<string, int|bool>                             $done
     *
     * @return array{0: Collection<int, TelegramScrapedUser>, 1: bool} the ones found, and whether the account stops after inviting them
     */
    private function resolve(
        API $api,
        TelegramAccount $account,
        TelegramInviteGroup $group,
        Collection $people,
        array $config,
        callable $say,
        array &$done,
    ): array {
        $found = collect();
        $people = $people->values();
        $context = ['step' => self::STEP_RESOLVE, 'via' => TelegramGroupInvite::VIA_USERNAME, 'size' => $people->count()];

        foreach ($people as $i => $person) {
            if ($i > 0) {
                sleep(random_int(...self::RESOLVE_PAUSE));
            }

            try {
                $user = $this->patiently(
                    fn () => $this->usernames->user($api, (string) $person->username),
                    $say,
                    $account,
                    $group,
                    $context,
                );
            } catch (Throwable $e) {
                $kind = self::classify($e, self::STEP_RESOLVE);

                if (in_array($kind, self::ACCOUNT_KINDS, true)) {
                    /*
                     * The ones not looked up go back. A flood wait on the
                     * lookups is theirs alone: those found are still invited.
                     * Any other trouble stops the invite too.
                     */
                    if ($kind !== self::KIND_WAIT) {
                        $this->releasePeople($group, $found);
                        $found = collect();
                    }

                    $this->releasePeople($group, $people->slice($i));
                    $this->accountTrouble($account, $e, $kind, $config, $group->id, [...$context, 'found' => $found->count()]);
                    $say('  ' . self::code($e) . ': no more username lookups for this account');

                    return [$found, true];
                }

                /* Nobody has the name now: MadelineProto says it as a peer it does not know. */
                $this->failPerson($group, $person, self::peerMissing($e) ? 'USERNAME_NOT_OCCUPIED' : self::code($e), $kind === self::KIND_PERSON);
                $done['failed']++;

                continue;
            }

            $reason = match (true) {
                $user === null || (int) ($user['id'] ?? 0) !== (int) $person->telegram_user_id => 'USERNAME_OWNER_CHANGED',
                ! empty($user['deleted']) => 'INPUT_USER_DEACTIVATED',
                ! empty($user['bot']) => 'USER_BOT',
                default => null,
            };

            if ($reason !== null) {
                $this->failPerson($group, $person, $reason, $reason !== 'USERNAME_OWNER_CHANGED');
                $done['failed']++;

                continue;
            }

            $found->push($person);
        }

        return [$found, false];
    }

    /**
     * Calls a method and waits out a short FLOOD_WAIT on the spot, a few
     * times at most; a longer one is thrown for the account to rest. Every
     * wait is written down: they come before a longer stop.
     *
     * @param array<string, mixed> $context the step, the way, the size
     */
    private function patiently(
        callable $call,
        callable $say,
        TelegramAccount $account,
        TelegramInviteGroup $group,
        array $context,
    ): mixed {
        for ($try = 1; ; $try++) {
            try {
                return $call();
            } catch (Throwable $e) {
                $seconds = self::waitSeconds($e);

                if ($try > self::WAIT_RETRIES || self::classify($e) !== self::KIND_WAIT || $seconds > self::WAIT_IN_PLACE) {
                    throw $e;
                }

                $this->log(TelegramGroupInviteLog::EVENT_WAIT, self::code($e) . ": waits {$seconds}s on the spot", $account->id, $group->id, [
                    ...$context,
                    'rpc' => self::code($e),
                    'seconds' => $seconds,
                ]);

                $seconds += random_int(2, 5);
                $say('  ' . self::code($e) . ": waits {$seconds}s");
                sleep($seconds);
            }
        }
    }

    /**
     * @param Collection<int, TelegramInviteGroup> $groups
     * @param array<string, mixed>                 $config
     */
    private function hasWork(TelegramAccount $account, Collection $groups, array $config): bool
    {
        if ($this->callsToday($account->id) >= $this->dailyCap($account, $config)) {
            return false;
        }

        $open = $this->openGroups($account, $groups);

        if ($open->contains(fn (TelegramInviteGroup $group) => $this->candidates($account, $group, 1)->isNotEmpty())) {
            return true;
        }

        return $this->usernamesLeft($account, $config) > 0
            && $open->contains(fn (TelegramInviteGroup $group) => $this->usernameCandidates($group, 1)->isNotEmpty());
    }

    /**
     * @param Collection<int, TelegramInviteGroup> $groups
     *
     * @return Collection<int, TelegramInviteGroup>
     */
    private function openGroups(TelegramAccount $account, Collection $groups): Collection
    {
        return $groups
            ->reject(fn (TelegramInviteGroup $group) => isset($this->blocked[$group->id])
                || isset($this->denied[$account->id . ':' . $group->id]))
            ->values();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function usernamesLeft(TelegramAccount $account, array $config): int
    {
        if (! $config['use_usernames']) {
            return 0;
        }

        return max(0, (int) $config['usernames_per_day'] - $this->resolvesToday($account->id));
    }

    /**
     * Not a bot, not one of ours, not refusing everybody, not tried for the group.
     *
     * @return Builder<TelegramScrapedUser>
     */
    private function untried(TelegramInviteGroup $group): Builder
    {
        $own = $this->ownIds ??= TelegramAccount::query()
            ->whereNotNull('telegram_user_id')
            ->pluck('telegram_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return TelegramScrapedUser::query()
            ->where('is_bot', false)
            ->addableToGroup()
            ->when($own !== [], fn ($q) => $q->whereNotIn('telegram_user_id', $own))
            ->whereNotIn(
                'id',
                TelegramGroupInvite::query()
                    ->where('invite_group_id', $group->id)
                    ->select('scraped_user_id'),
            );
    }

    /**
     * Accounts whose session another process already holds. A MadelineProto
     * session tolerates one full process.
     *
     * @return list<int>
     */
    private function busyElsewhere(): array
    {
        return TelegramAccountProcess::query()
            ->where('is_busy', true)
            ->where('process', '!=', ProcessEnum::GroupInvite->value)
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Accounts still resting after a flood.
     *
     * @return list<int>
     */
    private function restingIds(): array
    {
        return TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->whereNotNull('meta')
            ->get(['telegram_account_id', 'meta'])
            ->filter(fn (TelegramAccountProcess $state) => self::secondsUntil(((array) $state->meta)['rest_until'] ?? null) > 0)
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function ensureJoined(API $api, TelegramInviteGroup $group, TelegramAccount $account, callable $say): void
    {
        $key = $account->id . ':' . $group->id;

        if (isset($this->joined[$key])) {
            return;
        }

        $context = ['step' => self::STEP_JOIN];

        try {
            $result = $this->patiently(fn () => $group->isInvite()
                ? $api->messages->importChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0)
                : $api->channels->joinChannel(channel: '@' . ltrim($group->link, '@'), floodWaitLimit: 0), $say, $account, $group, $context);

            $this->rememberChat($group, $result, $account->id);
        } catch (Throwable $e) {
            if (self::classify($e, self::STEP_JOIN) !== self::KIND_ALREADY) {
                throw $e;
            }

            if ($group->isInvite() && ! $group->telegram_chat_id) {
                $this->rememberChat($group, $this->patiently(
                    fn () => $api->messages->checkChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0),
                    $say,
                    $account,
                    $group,
                    $context,
                ), $account->id);
            }
        }

        $this->joined[$key] = true;

        $group->refresh();
    }

    private function rememberChat(TelegramInviteGroup $group, mixed $result, int $accountId): void
    {
        if (! is_array($result)) {
            return;
        }

        $chat = $result['chats'][0] ?? $result['chat'] ?? null;

        if (! is_array($chat)) {
            return;
        }

        /* A basic group takes people by messages.addChatUser, one at a time: not done here. */
        if (($chat['_'] ?? null) === 'chat') {
            $this->groupGone($group, 'BASIC_GROUP', $accountId);

            return;
        }

        $update = [];

        if (($id = GroupTopic::chatId($chat)) !== null) {
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

    /**
     * @param array<string, mixed> $config
     *
     * @return bool whether the account stops
     */
    private function joinTrouble(TelegramAccount $account, TelegramInviteGroup $group, Throwable $e, array $config, callable $say): bool
    {
        $kind = self::classify($e, self::STEP_JOIN);
        $code = self::code($e);

        if (in_array($kind, self::ACCOUNT_KINDS, true)) {
            $this->accountTrouble($account, $e, $kind, $config, $group->id, ['step' => self::STEP_JOIN]);
            $say("  join {$group->link}: {$code}, the account stops");

            return true;
        }

        $kind === self::KIND_GROUP_GONE
            ? $this->groupGone($group, $code, $account->id)
            : $this->deny($account, $group, $code);

        $say("  join {$group->link}: {$code}");

        return false;
    }

    /**
     * Nobody can invite into it: switched off on the page with the reason.
     */
    private function groupGone(TelegramInviteGroup $group, string $code, ?int $accountId): void
    {
        $this->blocked[$group->id] = true;

        $group->update(['is_active' => false, 'last_error' => mb_substr($code, 0, 500)]);

        $this->log('skipped', "{$code}: the group is switched off", $accountId, $group->id);
    }

    /**
     * This account may not invite into the group. When a few in a row may
     * not and none may, the run leaves the group.
     */
    private function deny(TelegramAccount $account, TelegramInviteGroup $group, string $code): void
    {
        $this->denied[$account->id . ':' . $group->id] = true;

        $this->log('skipped', "{$code}: this account cannot invite here", $account->id, $group->id);

        $accounts = count(array_filter(
            array_keys($this->denied),
            fn (string $key) => str_ends_with($key, ':' . $group->id),
        ));

        if ($accounts >= self::DENIED_ACCOUNTS_LIMIT && ! isset($this->invitedInto[$group->id])) {
            $this->blocked[$group->id] = true;

            $group->update(['last_error' => mb_substr($code, 0, 500)]);

            $this->log('skipped', "{$code}: {$accounts} accounts cannot invite here, left for this run", null, $group->id);
        }
    }

    /**
     * What becomes of the account after an error that is its own. Every
     * stop is written down with what the account had done before it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context what was refused: the step, the way, the size
     */
    private function accountTrouble(
        TelegramAccount $account,
        Throwable $e,
        string $kind,
        array $config,
        ?int $groupId = null,
        array $context = [],
    ): void {
        $this->troubled[$account->id] = true;

        $code = self::code($e);

        if ($kind === self::KIND_WAIT) {
            $this->rest($account, self::waitSeconds($e) + random_int(...self::WAIT_EXTRA), self::KIND_WAIT, $code, $groupId, [], $context);

            return;
        }

        if ($kind === self::KIND_SPAM) {
            $this->spamLimited($account, $config, $code, $groupId, $context);

            return;
        }

        if ($kind === self::KIND_DEAD) {
            $counters = $this->snapshot($account, $context);

            if (($dead = DeadSession::code($e)) !== null) {
                DeadSession::retire($account->id, $dead);
            }

            $this->pool->disable($account, ProcessEnum::GroupInvite, mb_substr($code, 0, 500));

            $this->log('disabled', $code, $account->id, $groupId, [
                'kind' => self::KIND_DEAD,
                'rpc' => $code,
                'counters' => $counters,
            ]);

            return;
        }

        $this->pool->registerFailure($account, ProcessEnum::GroupInvite, mb_substr($code, 0, 200));

        $this->log('failed', mb_substr($e->getMessage(), 0, 500), $account->id, $groupId, [
            ...$context,
            'exception' => $e::class,
        ]);
    }

    /**
     * @param array<string, mixed> $extra   more of the account's meta
     * @param array<string, mixed> $context what was refused: the step, the way, the size
     */
    private function rest(
        TelegramAccount $account,
        int $seconds,
        string $kind,
        string $reason,
        ?int $groupId = null,
        array $extra = [],
        array $context = [],
    ): void {
        /* Before the meta changes: the cap it worked under, the floods behind it. */
        $counters = $this->snapshot($account, $context);

        $until = now()->addSeconds(max(1, $seconds));

        $this->setMeta($account, [
            ...$extra,
            'rest_until' => $until->toIso8601String(),
            'rest_kind' => $kind,
            'rest_reason' => mb_substr($reason, 0, 120),
        ]);

        $this->log($kind === 'empty' ? 'rest' : 'flood', "{$reason}: rests until {$until->format('d.m H:i')}", $account->id, $groupId, [
            'kind' => $kind,
            'rpc' => $reason,
            'seconds' => $seconds,
            'until' => $until->toIso8601String(),
            ...$extra,
            'counters' => $counters,
        ]);
    }

    /**
     * The invite calls, the lookups and the short flood waits of an account
     * since a moment.
     *
     * @return array{calls: int, invited: int, not_added: int, already: int, failed: int, username_calls: int, lookups: int, waits: int, wait_seconds: int, first: Carbon|null}
     */
    private function workSince(int $accountId, Carbon $from): array
    {
        $work = [
            'calls' => 0,
            'invited' => 0,
            'not_added' => 0,
            'already' => 0,
            'failed' => 0,
            'username_calls' => 0,
            'lookups' => 0,
            'waits' => 0,
            'wait_seconds' => 0,
            'first' => null,
        ];

        TelegramGroupInviteLog::query()
            ->where('telegram_account_id', $accountId)
            ->whereIn('event', [TelegramGroupInviteLog::EVENT_CALL, TelegramGroupInviteLog::EVENT_WAIT])
            ->where('created_at', '>', $from)
            ->orderBy('id')
            ->get(['event', 'context', 'created_at'])
            ->each(function (TelegramGroupInviteLog $log) use (&$work): void {
                $context = (array) $log->context;

                if ($log->event === TelegramGroupInviteLog::EVENT_WAIT) {
                    $work['waits']++;
                    $work['wait_seconds'] += (int) ($context['seconds'] ?? 0);

                    return;
                }

                $work['calls']++;
                $work['invited'] += (int) ($context['invited'] ?? 0);
                $work['not_added'] += (int) ($context['not_added'] ?? 0);
                $work['already'] += (int) ($context['already'] ?? 0);
                $work['failed'] += (int) ($context['failed'] ?? 0);
                $work['first'] ??= $log->created_at;

                if (($context['via'] ?? null) === TelegramGroupInvite::VIA_USERNAME) {
                    $work['username_calls']++;
                }
            });

        $work['lookups'] = TelegramGroupInvite::query()
            ->where('telegram_account_id', $accountId)
            ->where('via', TelegramGroupInvite::VIA_USERNAME)
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('attempted_at', '>', $from)
            ->count();

        return $work;
    }

    /**
     * The account made all its calls today without a PEER_FLOOD. Tomorrow a
     * lowered cap grows back (dailyCap()).
     */
    private function cleanDay(TelegramAccount $account): void
    {
        $meta = $this->meta($account);

        if (isset($meta['cap']) || isset($meta['peer_floods'])) {
            $this->setMeta($account, ['clean_day' => now()->toDateString()]);
        }
    }

    /**
     * Merge into the account's group_invite meta; a null removes the key.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function setMeta(TelegramAccount $account, array $changes): array
    {
        $state = $this->pool->getOrCreate($account, ProcessEnum::GroupInvite);

        $meta = array_filter(
            array_replace((array) $state->meta, $changes),
            static fn ($value) => $value !== null,
        );

        $state->update(['meta' => $meta]);

        return $meta;
    }

    /**
     * @param list<int> $ids
     */
    private function mark(array $ids, bool $canAdd, ?string $error = null): void
    {
        if ($ids === []) {
            return;
        }

        TelegramScrapedUser::query()->whereIn('id', $ids)->update([
            'can_add_to_group' => $canAdd,
            'add_to_group_error' => $error === null ? null : mb_substr($error, 0, 64),
            'add_to_group_checked_at' => now(),
        ]);
    }

    private function failPerson(TelegramInviteGroup $group, TelegramScrapedUser $person, string $error, bool $refusesEverybody): void
    {
        TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->where('scraped_user_id', $person->id)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->update([
                'status' => TelegramGroupInvite::STATUS_FAILED,
                'error' => mb_substr($error, 0, 120),
                'attempted_at' => now(),
                'checked_at' => now(),
                'updated_at' => now(),
            ]);

        if ($refusesEverybody) {
            $this->mark([$person->id], false, $error);
        }
    }

    /**
     * A part of a batch gets its own key: one key, one call.
     *
     * @param Collection<int, TelegramScrapedUser> $people
     */
    private function rekey(TelegramInviteGroup $group, Collection $people): string
    {
        $key = (string) Str::uuid();

        TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->whereIn('scraped_user_id', $people->pluck('id'))
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->update(['batch_key' => $key]);

        return $key;
    }

    /**
     * @param Collection<int, TelegramScrapedUser> $people
     */
    private function releasePeople(TelegramInviteGroup $group, Collection $people): void
    {
        if ($people->isEmpty()) {
            return;
        }

        TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->whereIn('scraped_user_id', $people->pluck('id'))
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->delete();
    }

    /**
     * @param array<string, int|bool> $done
     * @param array<string, int>      $settled
     */
    private function tally(array &$done, TelegramAccount $account, TelegramInviteGroup $group, array $settled): void
    {
        self::add($done, $settled);

        if (isset($this->touched[$key = $account->id . ':' . $group->id])) {
            self::add($this->touched[$key], $settled);
        }

        if ($settled['invited'] > 0) {
            $this->invitedInto[$group->id] = true;

            if ($group->last_error !== null) {
                $group->update(['last_error' => null]);
            }
        }
    }

    /**
     * @param array<string, int>   $settled
     * @param array<string, mixed> $extra
     */
    private function logCall(
        TelegramAccount $account,
        TelegramInviteGroup $group,
        string $batchKey,
        string $via,
        int $size,
        array $settled,
        ?string $error = null,
        array $extra = [],
    ): void {
        $message = self::summary($settled) . ($error !== null ? " ({$error})" : '');

        $this->log(TelegramGroupInviteLog::EVENT_CALL, $message, $account->id, $group->id, [
            'batch_key' => $batchKey,
            'via' => $via,
            'size' => $size,
            ...array_intersect_key($settled, self::ZERO),
            'error' => $error,
            ...$extra,
        ]);
    }

    /**
     * Writes where each person of the rows stands. $attempt is the time of
     * the call; null when an earlier call is only being checked.
     *
     * @param Collection<int, TelegramGroupInvite>                  $rows
     * @param array<int, string>                                    $refused  telegram user id => error
     * @param array<int, array{status: string, error: string|null}> $verdicts telegram user id => where the person stands
     *
     * @return array{invited: int, not_added: int, already: int, sent: int, failed: int}
     */
    private function record(Collection $rows, array $refused, array $verdicts, ?Carbon $attempt): array
    {
        $now = now();
        $counts = self::ZERO;
        $invited = [];
        $refusedBy = [];

        foreach ($rows as $row) {
            $id = (int) $row->user?->telegram_user_id;

            $verdict = isset($refused[$id])
                ? self::verdict(TelegramGroupInvite::STATUS_FAILED, $refused[$id])
                : ($verdicts[$id] ?? self::verdict(TelegramGroupInvite::STATUS_SENT));

            $row->update([
                'status' => $verdict['status'],
                'error' => $verdict['error'] !== null ? mb_substr($verdict['error'], 0, 120) : null,
                'checked_at' => $verdict['status'] !== TelegramGroupInvite::STATUS_SENT ? $now : null,
                ...($attempt !== null ? ['attempted_at' => $attempt] : []),
            ]);

            $counts[$verdict['status']]++;

            if ($verdict['status'] === TelegramGroupInvite::STATUS_INVITED) {
                $invited[] = $row->scraped_user_id;
            }

            if (isset($refused[$id])) {
                $refusedBy[$refused[$id]][] = $row->scraped_user_id;
            }
        }

        $this->mark($invited, true);

        foreach ($refusedBy as $error => $ids) {
            $this->mark($ids, false, (string) $error);
        }

        return $counts;
    }

    /**
     * @return array{status: string, error: string|null}
     */
    private static function verdict(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'error' => $error];
    }

    /**
     * The same verdict for everybody of a call.
     *
     * @param Collection<int, TelegramScrapedUser> $people
     *
     * @return array<int, array{status: string, error: string|null}>
     */
    private static function verdicts(Collection $people, string $status, ?string $error = null): array
    {
        return $people
            ->mapWithKeys(fn (TelegramScrapedUser $user) => [(int) $user->telegram_user_id => self::verdict($status, $error)])
            ->all();
    }

    /**
     * Adds what people came to into a running count.
     *
     * @param array<string, mixed> $into
     * @param array<string, mixed> $from
     */
    private static function add(array &$into, array $from): void
    {
        foreach (array_keys(self::ZERO) as $key) {
            $into[$key] = (int) ($into[$key] ?? 0) + (int) ($from[$key] ?? 0);
        }
    }

    /**
     * @param array<string, mixed> $counts
     */
    private static function summary(array $counts): string
    {
        $line = sprintf(
            '%d added, %d not added, %d cannot be added',
            $counts['invited'] ?? 0,
            $counts['not_added'] ?? 0,
            $counts['failed'] ?? 0,
        );

        if (($counts['already'] ?? 0) > 0) {
            $line .= ", {$counts['already']} already in";
        }

        if (($counts['sent'] ?? 0) > 0) {
            $line .= ", {$counts['sent']} unchecked";
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function checkPause(array $config): void
    {
        /* No pause between calls set on the panel: none between the checks either. */
        if ((int) ($config['delay_max'] ?? 0) <= 0) {
            return;
        }

        usleep(random_int(...self::CHECK_PAUSE_MS) * 1000);
    }

    private function peer(TelegramInviteGroup $group): int|string
    {
        if ($group->telegram_chat_id) {
            return GroupTopic::chatId(['id' => $group->telegram_chat_id]) ?? (int) $group->telegram_chat_id;
        }

        return '@' . ltrim($group->link, '@');
    }

    /**
     * @return array<int, string>
     */
    private function missingIds(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $failed = [];

        foreach ((array) ($result['missing_invitees'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['user_id'])) {
                continue;
            }

            $failed[(int) $row['user_id']] = ! empty($row['premium_would_allow_invite'])
                ? 'premium_required'
                : 'missing_invitee';
        }

        return $failed;
    }

    /**
     * The people Telegram's own "added" service message names: they are in
     * the group by this call, nothing has to be looked up.
     *
     * @return array<int, true>
     */
    private function addedIds(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        /* messages.invitedUsers wraps the Updates; an older layer answers with the Updates itself. */
        $box = $result['updates'] ?? [];
        $list = is_array($box) && isset($box['_'])
            ? ($box['updates'] ?? (isset($box['update']) ? [$box['update']] : []))
            : $box;

        $added = [];

        foreach ((array) $list as $update) {
            $action = is_array($update) ? ($update['message']['action'] ?? null) : null;

            if (! is_array($action) || ($action['_'] ?? null) !== 'messageActionChatAddUser') {
                continue;
            }

            foreach ((array) ($action['users'] ?? []) as $id) {
                $added[(int) $id] = true;
            }
        }

        return $added;
    }

    /**
     * MadelineProto does not know the peer: a name nobody has, a person the
     * session never met.
     */
    private static function peerMissing(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PeerNotInDbException) {
                return true;
            }
        }

        return false;
    }

    private function releaseStale(TelegramInviteGroup $group): void
    {
        TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->where('status', TelegramGroupInvite::STATUS_PENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PENDING_MINUTES))
            ->delete();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pause(array $config): void
    {
        $min = max(0, (int) ($config['delay_min'] ?? 10));
        $max = max($min, (int) ($config['delay_max'] ?? 20));

        if ($max > 0) {
            sleep(random_int($min, $max));
        }
    }

    /**
     * The RPC error in the chain: MadelineProto often wraps it.
     */
    private static function rpcError(Throwable $e): ?RPCErrorException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RPCErrorException) {
                return $current;
            }
        }

        return null;
    }

    private static function code(Throwable $e): string
    {
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

    /**
     * @param array<string, mixed>|null $context
     */
    private function log(
        string $event,
        string $message,
        ?int $accountId = null,
        ?int $groupId = null,
        ?array $context = null,
    ): void {
        TelegramGroupInviteLog::query()->create([
            'run_id' => $this->runId,
            'telegram_account_id' => $accountId,
            'invite_group_id' => $groupId,
            'event' => $event,
            'message' => mb_substr($message, 0, 500),
            'context' => $context,
        ]);
    }
}
