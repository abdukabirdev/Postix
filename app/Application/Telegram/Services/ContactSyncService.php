<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\SessionSleep;
use App\Console\Commands\Telegram\TelegramAccountContactsCommand;
use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads the members of the watched groups and saves each of them into the
 * contacts of exactly one account.
 *
 * Why the account that reads is the account that saves: an access hash is
 * only valid for the session that saw the user, so a member found by one
 * account cannot be added by another.
 *
 * Why a person lands in one account only: the claim is a row in
 * telegram_account_contacts, unique by scraped_user_id, taken before the
 * contact is added. Two processes racing for the same person cannot both win.
 *
 * Who is in a group gets into the database even when every account has
 * used its contacts for the day: a group not read lately is read by one
 * account, a couple each, whatever its limit. The people found wait in the
 * database for an account, and the count of them tells how many numbers
 * more it would take to save them all.
 *
 * Each account's turn runs in a process of its own (work(), the run's
 * worker): a session that hangs - a MadelineProto start that never ends -
 * hung the whole run for hours. Now the run kills a worker silent for too
 * long, puts its account on hold and goes on with the next one.
 *
 * Several accounts take their turns at once (the "parallel" setting): each
 * keeps its own pace - the pause after every contact - so it is the same
 * for Telegram, the run is only not one account after another for hours.
 *
 * An account adds the people it has just read in the group's list, page
 * after page, the newest members first, and stops reading once its day is
 * full: its session knows them, so nobody fails as "not known to this
 * session" (a person who left, or seen by another account only), and the
 * whole group is read into the database once a run, not once an account.
 *
 * A hold is a rest that ends by itself (a flood wait, a streak of refusals,
 * a session that hung once); a dead session is the one hold for good.
 */
final class ContactSyncService
{
    /**
     * Errors that say the account itself is unusable, not the one member.
     */
    private const ACCOUNT_ERRORS = [
        'AUTH_KEY_UNREGISTERED',
        'AUTH_KEY_INVALID',
        'AUTH_KEY_DUPLICATED',
        'SESSION_REVOKED',
        'SESSION_EXPIRED',
        'USER_DEACTIVATED',
        'USER_DEACTIVATED_BAN',
        'PHONE_NUMBER_BANNED',
    ];

    /**
     * This many members in a row refused is not bad luck: the account is
     * put on hold instead of working through the rest of the list.
     */
    private const MAX_FAILURE_STREAK = 5;

    /** People in one page of the members list, Telegram's own maximum. */
    private const MEMBERS_PAGE = 200;

    /** Groups one account reads only into the database in a run: the reading is spread over the accounts. */
    private const COLLECT_PER_ACCOUNT = 2;

    /** A group not read for this long is read into the database again: the daily run reads each one. */
    private const COLLECT_EVERY_HOURS = 20;

    /** The run id the panel asked to stop ("*": whichever runs). A TelegramSetting: the run and the page are different processes. */
    public const STOP_KEY = 'contact_sync.stop';

    /** The run going now as the Stop button sees it: its id, its process, the account it holds. */
    public const NOW_KEY = 'contact_sync.now';

    /** A run still going this long after the Stop button is stuck, and is killed (StopContactSyncJob). Seconds. */
    public const STOP_GRACE = 120;

    /** A stop asked from the panel is looked for at most this often, seconds. */
    private const STOP_POLL = 2;

    /**
     * A worker that says nothing this long, the longest pause aside, hangs
     * on its session. A worker says a line a contact; reading a big group
     * is a few minutes of silence at most.
     */
    public const WORKER_SILENCE_MINUTES = 15;

    /** The worker's last line: what its account did, as JSON. */
    public const RESULT_MARK = '@@result ';

    /** Why an account whose worker hung is on hold (the accounts page shows it). */
    public const HUNG = 'SESSION_HUNG';

    /** Why an account that refused MAX_FAILURE_STREAK people in a row is on hold. */
    public const FAILURES = 'too_many_failures';

    /**
     * The rest of an account Telegram slowed down without a wait of its
     * own (PEER_FLOOD), that refused a streak of people, or whose session
     * hung: till the next daily run, not for good. Hours.
     */
    public const REST_HOURS = 20;

    /** Waited on top of the flood wait Telegram asks for. Seconds. */
    private const FLOOD_MARGIN = 300;

    /** A session that hangs again this soon after the last time is put on hold for good. Days. */
    private const HUNG_AGAIN_DAYS = 3;

    /**
     * A "busy" this old was left by a process that died: the longest
     * other holder (the accounts page's contacts read) gives its own up
     * after as long. Minutes.
     */
    private const STALE_BUSY_MINUTES = 180;

    /** How often the run looks at its workers. Microseconds. */
    private const POLL_EVERY = 250_000;

    /**
     * The other groups (Join group's, kept for the database) one account
     * reads for its contacts in a run, once its own groups have nobody left
     * for it - and the ones it may join to read them.
     */
    private const OTHER_GROUPS_PER_ACCOUNT = 3;

    private const OTHER_JOINS_PER_ACCOUNT = 1;

    /** Of the other groups, those with the most people waiting, this many. */
    private const OTHER_GROUPS_TOP = 30;

    /** An other group read whole with nobody in it to save is let be for this long. Hours. */
    private const OTHER_EMPTY_HOURS = 20;

    /** The joins left to the account in hand; null - no limit (its own groups). */
    private ?int $joinsLeft = null;

    private string $runId = '';

    /**
     * The accounts' turns going now, by account id: the worker, its account,
     * what it said so far and the start of a line it has not ended yet.
     *
     * @var array<int, array{process: Process, account: TelegramAccount, output: string, buffer: string}>
     */
    private array $workers = [];

    /** Only the daily sync stops by the button; a group's "fetch people" goes on. */
    private bool $stoppable = false;

    private bool $stopping = false;

    private int $stopLookedAt = 0;

    /** @var list<int> accounts put on hold in this run: they do nothing more in it */
    private array $held = [];

    /** @var array<int, true> groups read in this run, by any account */
    private array $readGroups = [];

    /** @var list<int>|null */
    private ?array $ownIds = null;

    public function __construct(
        private readonly MadelineService $madeline,
        private readonly TelegramAccountProcessService $pool,
        private readonly ContactSyncSettings $settings,
    ) {}

    /**
     * @param callable(string): void|null $output console echo, optional
     *
     * @param string $trigger who started it, kept on the run: "manual" (the
     *                        button, the console) or "schedule" (the daily one)
     *
     * @return array{accounts: int, added: int, failed: int, members: int, new: int}
     */
    public function run(?int $groupId = null, ?callable $output = null, string $trigger = 'manual'): array
    {
        $this->runId = (string) Str::uuid();
        $this->held = [];
        $this->readGroups = [];
        $this->ownIds = null;
        $this->stoppable = true;
        $this->stopping = false;
        $this->stopLookedAt = 0;
        $this->workers = [];

        /* A stop asked of an earlier run is not this one's. */
        TelegramSetting::set(self::STOP_KEY, null);
        $this->now();

        $say = $output ?? static function (string $line): void {};

        /* The panel's settings, read once: a run is not changed half way. */
        $config = $this->settings->current();
        $dailyLimit = max(0, (int) $config['daily_limit']);

        $totals = ['accounts' => 0, 'added' => 0, 'failed' => 0, 'members' => 0, 'new' => 0];

        /* Its own groups only: what Join group keeps for the database is not saved into contacts. */
        $groups = TelegramScrapeGroup::query()
            ->forContacts()
            ->where('is_active', true)
            ->when($groupId, fn ($q) => $q->whereKey($groupId))
            ->get();

        /*
         * The groups to read into the database whatever the limits: the new
         * ones and those not read lately, the longest unread first. The one
         * group of a run started for it is read in any case.
         */
        $collect = $groups
            ->filter(fn (TelegramScrapeGroup $group) => $groupId !== null
                || $group->last_synced_at === null
                || $group->last_synced_at->lte(now()->subHours(self::COLLECT_EVERY_HOURS)))
            ->sortBy(fn (TelegramScrapeGroup $group) => $group->last_synced_at?->getTimestamp() ?? 0)
            ->values();

        $this->log('started', 'Sync started', null, null, [
            'trigger' => $trigger,
            'groups' => $groups->pluck('link')->all(),
            'daily_limit' => $dailyLimit,
            'skip_usernames' => (bool) $config['skip_usernames'],
            'nick_base' => $config['nick_base'] !== '' ? $config['nick_base'] : null,
            'excluded_accounts' => $config['excluded_accounts'],
            'parallel' => (int) $config['parallel'],
        ]);

        if ($groups->isEmpty()) {
            $this->log('finished', 'No active groups, nothing to do');
            TelegramSetting::set(self::NOW_KEY, null);

            return $totals;
        }

        /* Left so by a run that died, and rests that are over: before any account is claimed. */
        $this->releaseStuck();
        $this->endRests();

        /* Accounts left out in the panel are taken for used from the start. */
        $usedAccountIds = array_map('intval', (array) $config['excluded_accounts']);

        $slots = max(1, (int) $config['parallel']);

        /* Whether a free slot is worth a look: at the start, and each time a worker ends. */
        $look = true;
        $noAccounts = false;

        /* Every available account takes its turn, however many there are - until the Stop button. */
        while (true) {
            while ($look && ! $noAccounts && count($this->workers) < $slots && ! $this->stopping()) {
                /*
                 * The groups have to be read to learn who is in them; after
                 * that, the contacts are done while nobody is left without an
                 * owner - a group read into the database later may bring more.
                 */
                $contactsDone = $groups->every(fn (TelegramScrapeGroup $group) => $group->last_synced_at !== null)
                    && ! $this->hasUnclaimed($groups, (bool) $config['skip_usernames'])
                    /* Its own groups done, the other groups' people are next. */
                    && ! $this->othersWait((bool) $config['skip_usernames']);

                $collect = $collect->reject(fn (TelegramScrapeGroup $group) => isset($this->readGroups[$group->id]))->values();

                if ($contactsDone && $collect->isEmpty()) {
                    $look = false;

                    break;
                }

                $account = $this->pool->claimRandomAvailableAccount(
                    ProcessEnum::ContactSync,
                    /* Nor one another process has open: a session takes one process at a time. */
                    [...$usedAccountIds, ...$this->busyElsewhere()],
                );

                if (! $account) {
                    $noAccounts = true;

                    break;
                }

                $usedAccountIds[] = $account->id;

                /* Its contacts for today are added already: it only reads groups into the database. */
                $adds = ! $contactsDone && $this->addedToday($account) < $dailyLimit;

                /* Its share of the groups to read, taken off the queue: no other worker reads them meanwhile. */
                $read = $collect->take(self::COLLECT_PER_ACCOUNT)->values();

                /* Nothing for it to do: not even started. */
                if (! $adds && $read->isEmpty()) {
                    $this->pool->release($account, ProcessEnum::ContactSync);

                    continue;
                }

                $collect = $collect->slice($read->count())->values();

                if (! $this->start($account, $groupId, $adds, $read->pluck('id')->map(fn ($id) => (int) $id)->all(), $config)) {
                    continue;
                }

                $totals['accounts']++;

                $say("Account #{$account->id} {$account->phone}");
            }

            if ($this->workers === []) {
                break;
            }

            usleep(self::POLL_EVERY);

            $ended = $this->poll($say);

            foreach ($ended as $result) {
                /* Hung or died on them: its groups wait for the next run, not for every account in this one. */
                if ($result === null) {
                    continue;
                }

                foreach (['added', 'failed', 'members', 'new'] as $key) {
                    $totals[$key] += $result[$key];
                }

                $this->readGroups += array_fill_keys($result['read'], true);

                /* What it was given and did not read - pushed back by a flood, cut by the Stop button - goes back, last. */
                $collect = $collect
                    ->concat(collect($result['queue'])->map(fn (int $id) => $groups->firstWhere('id', $id))->filter())
                    ->values();
            }

            if ($ended !== []) {
                $look = true;

                /* The workers read groups in their own processes: what they say now is in the database. */
                $groups = $groups->fresh();
            }
        }

        if ($noAccounts) {
            $this->log('skipped', 'No more accounts available');
        }

        /* Whether the button was pressed, for the end below: nothing may have looked lately. */
        $this->stopping();

        $this->log(
            'finished',
            $this->stopping ? 'Stopped from the panel' : 'Sync finished',
            null,
            null,
            $this->stopping ? [...$totals, 'stopped' => true] : $totals,
        );

        TelegramSetting::set(self::NOW_KEY, null);

        return $totals;
    }

    /**
     * Asked from the panel: the run finishes the contact in hand, gives the
     * account back and ends. One that does not answer is killed by
     * StopContactSyncJob after STOP_GRACE.
     */
    public static function requestStop(?string $runId): void
    {
        TelegramSetting::set(self::STOP_KEY, $runId ?? '*');
    }

    /**
     * The run going now as it wrote itself down: run_id, pid, account_id.
     * Empty for a run that does not write it (one started before the Stop
     * button existed).
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return (array) TelegramSetting::get(self::NOW_KEY, []);
    }

    /**
     * The panel asked this run to stop. Looked up every few seconds at most.
     */
    private function stopping(): bool
    {
        if ($this->stopping || ! $this->stoppable) {
            return $this->stopping;
        }

        if (time() - $this->stopLookedAt < self::STOP_POLL) {
            return false;
        }

        $this->stopLookedAt = time();

        $asked = TelegramSetting::get(self::STOP_KEY);

        if ($asked !== null && ($asked === '*' || $asked === $this->runId)) {
            $this->stopping = true;
        }

        return $this->stopping;
    }

    /**
     * Who the run is, the accounts it holds and the workers doing their
     * turns, for the Stop button: a stuck run is killed, and its accounts
     * given back, by what is written here. account_id / worker_pid are the
     * first of them, as a run of one account at a time wrote it.
     */
    private function now(): void
    {
        $workers = collect($this->workers)
            ->map(fn (array $worker, int $accountId) => ['account_id' => $accountId, 'worker_pid' => $worker['process']->getPid()])
            ->values()
            ->all();

        try {
            TelegramSetting::set(self::NOW_KEY, [
                'run_id' => $this->runId,
                'pid' => getmypid(),
                'account_id' => $workers[0]['account_id'] ?? null,
                'worker_pid' => $workers[0]['worker_pid'] ?? null,
                'workers' => $workers,
            ]);
        } catch (Throwable) {
            // the Stop button falls back on the lock's pid; the sync goes on
        }
    }

    /**
     * One account's turn of a run, in a process of its own (the run's
     * worker, telegram:sync-contacts --worker): its contacts, then the
     * groups it was given to read. The run claimed the account and gives it
     * back; a worker that hangs on its session is killed by the run, not
     * waited for.
     *
     * @param list<int> $collectIds the run's groups still to read into the database, in its order
     *
     * @return array{added: int, failed: int, members: int, new: int, read: list<int>, queue: list<int>}
     */
    public function work(int $accountId, string $runId, ?int $groupId, bool $adds, array $collectIds, ?callable $output = null): array
    {
        $this->runId = $runId;
        $this->held = [];
        $this->readGroups = [];
        $this->ownIds = null;
        $this->stoppable = true;
        $this->stopping = false;
        $this->stopLookedAt = 0;

        $say = $output ?? static function (string $line): void {};
        $config = $this->settings->current();

        $result = ['added' => 0, 'failed' => 0, 'members' => 0, 'new' => 0, 'read' => [], 'queue' => $collectIds];

        $account = TelegramAccount::query()->find($accountId);

        if (! $account) {
            return $result;
        }

        /* The run's groups, as run() takes them. */
        $groups = TelegramScrapeGroup::query()
            ->forContacts()
            ->where('is_active', true)
            ->when($groupId, fn ($q) => $q->whereKey($groupId))
            ->get();

        $byId = TelegramScrapeGroup::query()->whereKey($collectIds)->get()->keyBy('id');
        $queue = collect($collectIds)->map(fn (int $id) => $byId->get($id))->filter()->values();

        $api = null;

        try {
            $api = $this->madeline->for($account);

            if (! $api) {
                throw $this->madeline->lastError
                    ?? new \RuntimeException('Failed to start MadelineProto.');
            }

            if ($adds) {
                $done = $this->runAccount($api, $account, $groups, $config, $say);

                foreach (['added', 'failed', 'members', 'new'] as $key) {
                    $result[$key] += $done[$key];
                }
            }

            /* A flood wait on the contacts put it on hold: it reads nothing more. */
            if ($queue->isNotEmpty() && ! in_array($account->id, $this->held, true)) {
                $done = $this->collect($api, $account, $queue, $config, $say);

                $result['members'] += $done['members'];
                $result['new'] += $done['new'];
            }

            $this->pool->registerSuccess($account, ProcessEnum::ContactSync);
        } catch (Throwable $e) {
            if ($this->isAccountError($e) || $this->isFlood($e)) {
                $this->hold($account, $e);
            } else {
                $this->pool->registerFailure(
                    $account,
                    ProcessEnum::ContactSync,
                    mb_substr($e->getMessage(), 0, 200),
                );
            }

            $this->log('failed', mb_substr($e->getMessage(), 0, 500), $account->id, null, [
                'exception' => $e::class,
            ]);

            $say("  failed: {$e->getMessage()}");
        } finally {
            SessionSleep::put($api, ['account_id' => $account->id]);
        }

        $result['read'] = array_keys($this->readGroups);
        $result['queue'] = $queue->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return $result;
    }

    /**
     * The server's SIGTERM to the run: it stops the way the Stop button
     * stops it, and the workers going are told too.
     */
    public function terminate(): void
    {
        self::requestStop($this->runId !== '' ? $this->runId : null);

        foreach ($this->workers as $worker) {
            try {
                $worker['process']->signal(15);
            } catch (Throwable) {
                // gone already
            }
        }
    }

    /**
     * Starts one account's turn in a worker process; poll() follows it. The
     * run claimed the account; a worker that cannot even start gives it back
     * at once.
     *
     * @param list<int>            $collectIds the groups it reads into the database
     * @param array<string, mixed> $config
     */
    private function start(TelegramAccount $account, ?int $groupId, bool $adds, array $collectIds, array $config): bool
    {
        $command = [PHP_BINARY, base_path('artisan'), 'telegram:sync-contacts', '--worker=' . $account->id, '--run=' . $this->runId];

        if ($groupId !== null) {
            $command[] = '--group=' . $groupId;
        }

        if ($adds) {
            $command[] = '--adds';
        }

        if ($collectIds !== []) {
            $command[] = '--read=' . implode(',', $collectIds);
        }

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->setIdleTimeout(self::WORKER_SILENCE_MINUTES * 60 + max(0, (int) $config['delay_max']));

        try {
            $process->start();
        } catch (Throwable $e) {
            $this->log('failed', 'The worker could not run: ' . mb_substr($e->getMessage(), 0, 300), $account->id, null, ['exception' => $e::class]);
            $this->giveBack($account);
            $this->pool->release($account, ProcessEnum::ContactSync);

            return false;
        }

        $this->workers[$account->id] = [
            'process' => $process,
            'account' => $account,
            'output' => '',
            'buffer' => '',
        ];

        $this->now();

        return true;
    }

    /**
     * One look at the workers: what they said since the last one goes to
     * the console, the ones that ended give their accounts back. A worker
     * that said nothing for WORKER_SILENCE_MINUTES (and the longest pause on
     * top) hangs on its session: it is killed, the account put on hold with
     * the reason, and the run goes on with the others.
     *
     * @return list<array{added: int, failed: int, members: int, new: int, read: list<int>, queue: list<int>}|null> what each ended one did; null when it did not come back
     */
    private function poll(callable $say): array
    {
        $ended = [];

        foreach (array_keys($this->workers) as $accountId) {
            $process = $this->workers[$accountId]['process'];
            $hung = false;

            try {
                /* Kills a worker silent too long, and says so. */
                $process->checkTimeout();
            } catch (ProcessTimedOutException) {
                $hung = true;
            }

            try {
                $this->listen($accountId, $process->getIncrementalOutput(), $say);
            } catch (Throwable) {
                // nothing more to read from it
            }

            if (! $hung && $process->isRunning()) {
                continue;
            }

            /* What it said while it ended, and a last line left without its end. */
            try {
                $this->listen($accountId, $process->getIncrementalOutput() . "\n", $say);
            } catch (Throwable) {
                // nothing more to read from it
            }

            $worker = $this->workers[$accountId];
            unset($this->workers[$accountId]);

            $account = $worker['account'];
            $result = null;

            if ($hung) {
                $this->stuck($account, $say);
            } else {
                $result = self::workerResult($worker['output']);

                if ($result === null) {
                    /* Killed by the Stop button, or a fatal error: it never wrote what it did. */
                    if (! $this->stopping()) {
                        $this->log('failed', 'The worker stopped without a result (exit ' . $process->getExitCode() . ')', $account->id);
                    }

                    $this->giveBack($account);
                }
            }

            $this->pool->release($account, ProcessEnum::ContactSync);

            $ended[] = $result;
        }

        if ($ended !== []) {
            $this->now();
        }

        return $ended;
    }

    /**
     * A worker's output: kept whole for its result line, and said line by
     * line, each with its account - the workers of a run speak at once.
     */
    private function listen(int $accountId, string $chunk, callable $say): void
    {
        if ($chunk === '' || ! isset($this->workers[$accountId])) {
            return;
        }

        $worker = &$this->workers[$accountId];

        $worker['output'] .= $chunk;

        $lines = explode("\n", $worker['buffer'] . $chunk);
        $worker['buffer'] = (string) array_pop($lines);

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line !== '' && ! str_starts_with($line, self::RESULT_MARK)) {
                $say("#{$accountId} {$line}");
            }
        }
    }

    /**
     * What a worker did: its last RESULT_MARK line.
     *
     * @return array{added: int, failed: int, members: int, new: int, read: list<int>, queue: list<int>}|null
     */
    public static function workerResult(string $output): ?array
    {
        foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = rtrim($line, "\r");

            if (! str_starts_with($line, self::RESULT_MARK)) {
                continue;
            }

            $result = json_decode(substr($line, strlen(self::RESULT_MARK)), true);

            if (! is_array($result)) {
                return null;
            }

            return [
                'added' => (int) ($result['added'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'members' => (int) ($result['members'] ?? 0),
                'new' => (int) ($result['new'] ?? 0),
                'read' => array_values(array_map('intval', (array) ($result['read'] ?? []))),
                'queue' => array_values(array_map('intval', (array) ($result['queue'] ?? []))),
            ];
        }

        return null;
    }

    /**
     * The worker said nothing for too long: its session hangs (the
     * MadelineProto start that never ends, "The endpoint does not exist!").
     * Its MadelineProto worker is killed - that is what hangs - and the
     * account rests till the next daily run; one that hangs again within
     * HUNG_AGAIN_DAYS is put on hold until somebody enables it in the panel.
     */
    private function stuck(TelegramAccount $account, callable $say): void
    {
        $minutes = self::WORKER_SILENCE_MINUTES;

        $this->hold($account, new \RuntimeException("No answer from the session for {$minutes} minutes"), null, self::HUNG);

        $this->giveBack($account);

        SessionSleep::kill((string) $account->session_path, ['account_id' => $account->id]);

        $say("#{$account->id}   no answer for {$minutes} minutes: the account is put on hold");
    }

    /**
     * Accounts still marked busy for the sync by a process that died - a
     * run killed hard, a reboot: nothing else frees them, and the panel
     * shows them as fine. The run holds the sync's lock, so the only live
     * holders besides it are a group's "fetch people" and the accounts
     * page's contacts read; theirs is freed only when it is that old.
     */
    private function releaseStuck(): void
    {
        $others = TelegramScrapeGroup::query()
            ->pluck('id')
            ->contains(fn ($id) => TelegramProcessLock::isHeld(TelegramSyncContactsCommand::membersLock((int) $id)))
            || TelegramProcessLock::isHeld(TelegramAccountContactsCommand::LOCK_NAME);

        $stuck = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->where('is_busy', true)
            ->when($others, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('busy_at')
                ->orWhere('busy_at', '<', now()->subMinutes(self::STALE_BUSY_MINUTES))))
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($stuck === []) {
            return;
        }

        TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->whereIn('telegram_account_id', $stuck)
            ->update(['is_busy' => false, 'busy_at' => null]);

        /* Whoever they were taking is nobody's again. */
        TelegramAccountContact::query()
            ->whereIn('telegram_account_id', $stuck)
            ->where('status', TelegramAccountContact::STATUS_PENDING)
            ->delete();

        $this->log('released', 'Accounts a process that died had left busy: #' . implode(', #', $stuck), null, null, [
            'accounts' => $stuck,
        ]);
    }

    /**
     * Accounts whose rest is over are in the sync again: a flood wait
     * waited out, a day after a streak of refusals or a hang. A dead
     * session (no end to its hold) waits for the panel's button. Holds
     * from before the rests had an end are given the one they would get now.
     */
    private function endRests(): void
    {
        $back = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->where('is_available', false)
            ->get()
            ->filter(fn (TelegramAccountProcess $state) => self::restEnd($state)?->isPast() ?? false);

        foreach ($back as $state) {
            $state->enable();
        }

        if ($back->isEmpty()) {
            return;
        }

        $ids = $back->pluck('telegram_account_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->log('back', 'Rested and back in the sync: #' . implode(', #', $ids), null, null, [
            'accounts' => $ids,
            'reasons' => $back->mapWithKeys(fn (TelegramAccountProcess $state) => [(int) $state->telegram_account_id => $state->disabled_reason])->all(),
        ]);
    }

    /**
     * When an account's hold ends; null when it does not end by itself.
     */
    public static function restEnd(TelegramAccountProcess $state): ?Carbon
    {
        if ($state->is_available) {
            return null;
        }

        $meta = (array) $state->meta;

        /* The rest this very hold was given (not one of an earlier hold). */
        if (array_key_exists('rest_until', $meta) && ($meta['rest_reason'] ?? null) === $state->disabled_reason) {
            return $meta['rest_until'] !== null ? Carbon::parse($meta['rest_until']) : null;
        }

        /* Held before the rests had an end, or by another process's rules (seven failures in a row): by its reason, from when it was held. */
        $seconds = self::restSeconds((string) $state->disabled_reason);

        return $seconds !== null && $state->disabled_at !== null
            ? $state->disabled_at->copy()->addSeconds($seconds)
            : null;
    }

    /**
     * How long a hold for this reason lasts; null for good.
     */
    private static function restSeconds(string $reason): ?int
    {
        if (preg_match('/FLOOD_WAIT_(\d+)/', $reason, $m) === 1) {
            return (int) $m[1] + self::FLOOD_MARGIN;
        }

        foreach (['FLOOD', self::FAILURES, self::HUNG] as $kind) {
            if (str_contains($reason, $kind)) {
                return self::REST_HOURS * 3600;
            }
        }

        return null;
    }

    /**
     * Accounts another process has open now (the group invites, the group
     * join ...): a MadelineProto session takes one process at a time - two
     * on it, and both hang.
     *
     * @return list<int>
     */
    private function busyElsewhere(): array
    {
        return TelegramAccountProcess::query()
            ->where('is_busy', true)
            ->where('process', '!=', ProcessEnum::ContactSync->value)
            ->pluck('telegram_account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A worker gone half way: the person it was saving is nobody's again,
     * another account may take them.
     */
    private function giveBack(TelegramAccount $account): void
    {
        TelegramAccountContact::query()
            ->where('telegram_account_id', $account->id)
            ->where('status', TelegramAccountContact::STATUS_PENDING)
            ->delete();
    }

    /**
     * The "fetch people" button of a group: who is in it goes into the
     * database, nobody into contacts. One account reads it; a flood wait or
     * a dead session hands it to the next one. A group that cannot be read
     * (a dead link, a private group) is not tried by every account in turn.
     *
     * @param callable(string): void|null $output console echo, optional
     *
     * @return array{accounts: int, added: int, failed: int, members: int, new: int}
     */
    public function fetchMembers(int $groupId, ?callable $output = null, string $trigger = 'manual'): array
    {
        $this->runId = (string) Str::uuid();
        $this->held = [];
        $this->readGroups = [];
        $this->ownIds = null;
        $this->stoppable = false;
        $this->stopping = false;

        $say = $output ?? static function (string $line): void {};

        $config = $this->settings->current();

        $totals = ['accounts' => 0, 'added' => 0, 'failed' => 0, 'members' => 0, 'new' => 0];

        /* A paused group too: the button is pressed for it. */
        $group = TelegramScrapeGroup::query()->forContacts()->find($groupId);

        $this->log('started', 'Members fetch started', null, $group?->id, [
            'trigger' => $trigger,
            'mode' => 'members',
            'groups' => $group ? [$group->link] : [],
        ]);

        if (! $group) {
            $this->log('finished', 'No such group, nothing to do');

            return $totals;
        }

        $usedAccountIds = array_map('intval', (array) $config['excluded_accounts']);

        while (! isset($this->readGroups[$group->id])) {
            $account = $this->pool->claimRandomAvailableAccount(
                ProcessEnum::ContactSync,
                [...$usedAccountIds, ...$this->busyElsewhere()],
            );

            if (! $account) {
                $this->log('skipped', 'No more accounts available', null, $group->id);

                break;
            }

            $usedAccountIds[] = $account->id;

            $totals['accounts']++;

            $say("Account #{$account->id} {$account->phone}");

            $api = null;
            $accountFailed = false;

            try {
                $api = $this->madeline->for($account);

                if (! $api) {
                    throw $this->madeline->lastError
                        ?? new \RuntimeException('Failed to start MadelineProto.');
                }

                $result = $this->collect($api, $account, collect([$group]), $config, $say);

                $totals['members'] += $result['members'];
                $totals['new'] += $result['new'];

                $this->pool->registerSuccess($account, ProcessEnum::ContactSync);
            } catch (Throwable $e) {
                $accountFailed = true;

                if ($this->isAccountError($e) || $this->isFlood($e)) {
                    $this->hold($account, $e);
                } else {
                    $this->pool->registerFailure(
                        $account,
                        ProcessEnum::ContactSync,
                        mb_substr($e->getMessage(), 0, 200),
                    );
                }

                $this->log('failed', mb_substr($e->getMessage(), 0, 500), $account->id, $group->id, [
                    'exception' => $e::class,
                ]);

                $say("  failed: {$e->getMessage()}");
            } finally {
                SessionSleep::put($api, ['account_id' => $account->id]);

                $this->pool->release($account, ProcessEnum::ContactSync);
            }

            /* Not read, and not for the account's fault: the next one would fail the same way. */
            if (! isset($this->readGroups[$group->id]) && ! $accountFailed && ! in_array($account->id, $this->held, true)) {
                break;
            }
        }

        $this->log('finished', 'Members fetch finished', null, $group->id, $totals);

        return $totals;
    }

    /**
     * The account's contacts for the day: group after group, the members
     * list page after page, and on each page the people nobody holds yet -
     * newest members first. It reads no further than it needs: once its
     * day is full the rest of the list is left to collect(), which reads a
     * group whole into the database once a run.
     *
     * Only people of the pages it has just read: its session knows them. A
     * person who has left the group, or was only ever seen by another
     * account, is "not known" to it - they failed one after another and
     * put the account on hold for a streak of failures.
     *
     * @param \Illuminate\Support\Collection<int, TelegramScrapeGroup> $groups
     * @param array<string, mixed>                                      $config
     * @param callable(string): void                                    $say
     *
     * @return array{added: int, failed: int, members: int, new: int}
     */
    private function runAccount(
        API $api,
        TelegramAccount $account,
        $groups,
        array $config,
        callable $say,
    ): array {
        $limit = max(0, (int) ($config['daily_limit'] ?? 100));
        $remaining = $limit - $this->addedToday($account);

        $result = ['added' => 0, 'failed' => 0, 'members' => 0, 'new' => 0];

        $own = $this->ownIds();

        $existing = null;
        $streak = 0;

        /*
         * With a base nick set, people are saved as "base", "base-1",
         * "base-2" ... counted per account and carried on from run to run.
         */
        $nickBase = (string) ($config['nick_base'] ?? '');
        $nickNext = $nickBase !== '' ? $this->nickCount($account, $nickBase) : 0;

        $skipUsernames = (bool) ($config['skip_usernames'] ?? false);

        /*
         * Its own groups first; once they have nobody left for it, the other
         * groups' people (2026-11-04: "когда 77 закончится мы перейдём к
         * другим") - asked for only when it gets that far.
         */
        $queue = (function () use ($groups, $account, $config): Generator {
            yield from $groups;
            yield from $this->otherGroups($account, $config);
        })();

        $others = 0;
        $joins = self::OTHER_JOINS_PER_ACCOUNT;

        foreach ($queue as $group) {
            /* Its contacts for today are added: the groups left are read into the database by collect(). */
            if ($remaining <= 0 || $this->stopping()) {
                break;
            }

            $other = $group->type === TelegramScrapeGroup::TYPE_DATABASE;

            if ($other) {
                if ($others >= self::OTHER_GROUPS_PER_ACCOUNT) {
                    break;
                }

                /* Nobody left in it, or read whole today with nobody to save. */
                if (Cache::has(self::otherEmptyKey($group->id)) || ! $this->hasUnclaimed(collect([$group]), $skipUsernames)) {
                    continue;
                }

                $others++;
            } elseif ($group->last_synced_at !== null && ! $this->hasUnclaimed(collect([$group]), $skipUsernames)) {
                /*
                 * Read before and everybody in it has an owner: nothing in it for
                 * this account. Who joined since comes with the next collect().
                 */
                continue;
            }

            /* Into an other group it joins once a run at most; its own groups, as it needs. */
            $this->joinsLeft = $other ? $joins : null;

            $pages = $this->memberPages($api, $group, $account, $config);
            $read = 0;
            $new = 0;
            $took = 0;
            $held = false;
            $page = null;

            while (true) {
                try {
                    $page === null ? $pages->current() : $pages->next();

                    if (! $pages->valid()) {
                        break;
                    }

                    $page = $pages->current();
                } catch (Throwable $e) {
                    /* Join group's group: its state is that process's own; this account just cannot read it now. */
                    if (! $other) {
                        $group->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    }

                    $this->log('failed', 'Cannot read group: ' . mb_substr($e->getMessage(), 0, 300), $account->id, $group->id);

                    $say("  {$group->link}: {$e->getMessage()}");

                    if ($this->isFlood($e)) {
                        $this->hold($account, $e, $group->id);

                        $held = true;
                    } elseif ($this->isAccountError($e)) {
                        throw $e;
                    }

                    break;
                }

                $read += count($page['users']);

                $stored = $this->storeMembers($group, $page['users'], $own);
                $new += $stored;

                $result['members'] += count($page['users']);
                $result['new'] += $stored;

                /*
                 * People this account already has in its contacts are not added
                 * again; they are marked and left alone.
                 */
                $existing ??= $this->existingContactIds($api);

                foreach ($this->candidates($page['users'], $config) as $user) {
                    /* Stopped: before the claim, so nobody is left taken. */
                    if ($remaining <= 0 || $this->stopping()) {
                        break;
                    }

                    if (! $this->claim($account, $user, isset($existing[$user->telegram_user_id]))) {
                        continue;
                    }

                    $took++;

                    if (isset($existing[$user->telegram_user_id])) {
                        $this->markOnAccount($account, $user);

                        $this->log('skipped', 'Already in contacts', $account->id, $group->id, [
                            'telegram_user_id' => $user->telegram_user_id,
                        ]);

                        continue;
                    }

                    try {
                        $nick = $nickBase !== '' ? ContactSyncSettings::nick($nickBase, $nickNext) : null;

                        $this->addContact($api, $user, $nick);

                        $this->finishClaim($account, $user, TelegramAccountContact::STATUS_ADDED, null, $nick);
                        $this->markOnAccount($account, $user);

                        $result['added']++;
                        $remaining--;
                        $streak = 0;

                        if ($nick !== null) {
                            $nickNext++;
                        }

                        $this->log('added', 'Contact added', $account->id, $group->id, [
                            'telegram_user_id' => $user->telegram_user_id,
                            'username' => $user->username,
                            'contact_name' => $nick,
                        ]);

                        /* A line a contact: the run knows the worker is alive by it. */
                        $say("  added {$user->telegram_user_id}");

                        $this->pause($config);
                    } catch (RPCErrorException $e) {
                        if ($this->isFlood($e)) {
                            /*
                             * Not this person's fault: give the claim back so
                             * another account can take them, and let this
                             * account rest out the wait.
                             */
                            $this->unclaim($account, $user);

                            $this->hold($account, $e, $group->id);

                            $say("  flood: {$e->rpc}, account put on hold");

                            $held = true;

                            break;
                        }

                        if ($this->isAccountError($e)) {
                            $this->unclaim($account, $user);

                            throw $e;
                        }

                        $this->finishClaim($account, $user, TelegramAccountContact::STATUS_FAILED, $e->rpc);

                        $say("  not added {$user->telegram_user_id}: {$e->rpc}");

                        $result['failed']++;
                        $streak++;

                        $this->log('failed', $e->rpc, $account->id, $group->id, [
                            'telegram_user_id' => $user->telegram_user_id,
                        ]);

                        if ($streak >= self::MAX_FAILURE_STREAK) {
                            $this->hold($account, $e, $group->id, self::FAILURES);

                            $held = true;

                            break;
                        }
                    } catch (Throwable $e) {
                        $this->finishClaim(
                            $account,
                            $user,
                            TelegramAccountContact::STATUS_FAILED,
                            mb_substr($e->getMessage(), 0, 300),
                        );

                        $result['failed']++;
                        $streak++;

                        $this->log('failed', mb_substr($e->getMessage(), 0, 300), $account->id, $group->id, [
                            'telegram_user_id' => $user->telegram_user_id,
                        ]);

                        if ($streak >= self::MAX_FAILURE_STREAK) {
                            $this->hold($account, $e, $group->id, self::FAILURES);

                            $held = true;

                            break;
                        }
                    }
                }

                if ($held || $this->stopping()) {
                    break;
                }

                /* Its day is full: the list is read on only to its end, if this was its last page. */
                if ($remaining <= 0 && ! $page['last']) {
                    break;
                }
            }

            if ($other) {
                $joins = $this->joinsLeft ?? $joins;
                $this->joinsLeft = null;
            }

            if ($page !== null) {
                $whole = isset($this->readGroups[$group->id]);

                /* What Telegram said is in it beside what it listed: a short list shows here. */
                $this->log('members', $read . ' members read', $account->id, $group->id, [
                    'new' => $new,
                    'telegram_count' => $group->members_count,
                    'whole' => $whole,
                    'other' => $other,
                ]);

                $say("  {$group->link}: {$read} members" . ($whole ? '' : ' (part of the list)'));

                /* The whole list and nobody to take: who waits in it has left, or is somebody's already. */
                if ($other && $whole && $took === 0) {
                    Cache::put(self::otherEmptyKey($group->id), true, now()->addHours(self::OTHER_EMPTY_HOURS));
                }
            }

            if ($held) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * The people of a page of the members list this account may take:
     * nobody's contact yet, not held by any account, no bot - in the
     * order of the page.
     *
     * @param list<array<string, mixed>> $users the page's users
     * @param array<string, mixed>       $config
     *
     * @return Collection<int, TelegramScrapedUser>
     */
    private function candidates(array $users, array $config): Collection
    {
        $order = [];

        foreach ($users as $user) {
            $order[(int) $user['id']] ??= count($order);
        }

        if ($order === []) {
            return collect();
        }

        return TelegramScrapedUser::query()
            ->whereIn('telegram_user_id', array_keys($order))
            ->whereDoesntHave('contact')
            /* Somebody's contact already (read from an account): not added to a second one. */
            ->whereDoesntHave('accountLinks')
            ->where('is_bot', false)
            /* A public @username is reached by its link: kept in the database only. */
            ->when($config['skip_usernames'] ?? false, fn ($q) => $q->withoutUsername())
            ->get()
            ->sortBy(fn (TelegramScrapedUser $user) => $order[(int) $user->telegram_user_id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * The other groups (Join group's, kept for the database) whose people
     * still wait for an account, the most waiting first - the top of them,
     * counted once in a while for every worker. Each account starts from
     * its own place in the list: they do not all read the same group.
     *
     * @param array<string, mixed> $config
     *
     * @return list<TelegramScrapeGroup>
     */
    private function otherGroups(TelegramAccount $account, array $config): array
    {
        $ids = self::otherGroupIds((bool) ($config['skip_usernames'] ?? false));

        if ($ids === []) {
            return [];
        }

        $shift = $account->id % count($ids);
        $ids = [...array_slice($ids, $shift), ...array_slice($ids, 0, $shift)];

        $groups = TelegramScrapeGroup::query()->whereKey($ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $groups->get($id))->filter()->values()->all();
    }

    /**
     * @return list<int>
     */
    private static function otherGroupIds(bool $skipUsernames): array
    {
        try {
            return Cache::remember('contact-sync:other-groups:' . (int) $skipUsernames, 1800, fn (): array => DB::table('telegram_scrape_group_user as gu')
                ->join('telegram_scrape_groups as g', 'g.id', '=', 'gu.group_id')
                ->join('telegram_scraped_users as u', 'u.id', '=', 'gu.scraped_user_id')
                ->where('g.type', TelegramScrapeGroup::TYPE_DATABASE)
                ->where('u.is_bot', false)
                ->when($skipUsernames, fn ($q) => $q->where(fn ($q) => $q->whereNull('u.username')->orWhere('u.username', '')))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('telegram_account_contacts as c')->whereColumn('c.scraped_user_id', 'u.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('telegram_account_contact_links as l')->whereColumn('l.scraped_user_id', 'u.id'))
                ->groupBy('gu.group_id')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(self::OTHER_GROUPS_TOP)
                ->pluck('gu.group_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        } catch (Throwable) {
            // an add-on: without it the sync works its own groups only
            return [];
        }
    }

    /**
     * Somebody in the other groups still waits for an account - not counting
     * the groups read whole today with nobody to take.
     */
    private function othersWait(bool $skipUsernames): bool
    {
        return collect(self::otherGroupIds($skipUsernames))
            ->reject(fn (int $id) => Cache::has(self::otherEmptyKey($id)))
            ->isNotEmpty();
    }

    private static function otherEmptyKey(int $groupId): string
    {
        return 'contact-sync:other-empty:' . $groupId;
    }

    /**
     * The person this account had taken is nobody's again: the add did not
     * happen for a reason of the account's own.
     */
    private function unclaim(TelegramAccount $account, TelegramScrapedUser $user): void
    {
        TelegramAccountContact::query()
            ->where('scraped_user_id', $user->id)
            ->where('telegram_account_id', $account->id)
            ->where('status', TelegramAccountContact::STATUS_PENDING)
            ->delete();
    }

    /**
     * A couple of the groups not read lately, into the database only, by an
     * account whatever its daily limit: who is in them is known before there
     * is an account free to save them. A group that cannot be read waits
     * for the next run; one a flood wait or a dead session stopped goes back
     * for another account.
     *
     * @param Collection<int, TelegramScrapeGroup> $queue  the run's groups still to read, taken from the front
     * @param array<string, mixed>                 $config
     * @param callable(string): void               $say
     *
     * @return array{members: int, new: int}
     */
    private function collect(
        API $api,
        TelegramAccount $account,
        Collection $queue,
        array $config,
        callable $say,
    ): array {
        $result = ['members' => 0, 'new' => 0];
        $taken = 0;

        while ($taken < self::COLLECT_PER_ACCOUNT && ! $this->stopping() && ($group = $queue->shift()) !== null) {
            /* Read by this account for its contacts a moment ago. */
            if (isset($this->readGroups[$group->id])) {
                continue;
            }

            $taken++;

            try {
                $members = $this->readMembers($api, $group, $account, $config);
            } catch (Throwable $e) {
                $group->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

                $this->log('failed', 'Cannot read group: ' . mb_substr($e->getMessage(), 0, 300), $account->id, $group->id);

                $say("  {$group->link}: {$e->getMessage()}");

                if ($this->isFlood($e) || $this->isAccountError($e)) {
                    /* Not the group's fault: the next account reads it. */
                    $queue->push($group);
                }

                if ($this->isFlood($e)) {
                    $this->hold($account, $e, $group->id);

                    return $result;
                }

                if ($this->isAccountError($e)) {
                    throw $e;
                }

                continue;
            }

            $new = $this->storeMembers($group, $members, $this->ownIds());

            $result['members'] += count($members);
            $result['new'] += $new;

            /* What Telegram said is in it beside what it listed: a short list shows here. */
            $this->log('members', count($members) . ' members read', $account->id, $group->id, ['new' => $new, 'telegram_count' => $group->members_count]);

            $say("  {$group->link}: " . count($members) . " members, {$new} new in the database");

            if ($queue->isNotEmpty() && $taken < self::COLLECT_PER_ACCOUNT) {
                $this->pause($config);
            }
        }

        return $result;
    }

    /**
     * Who is in the group: the members list page after page, as Telegram
     * gives it (up to about 10 000 people).
     *
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>> the users of the members list
     */
    private function readMembers(
        API $api,
        TelegramScrapeGroup $group,
        TelegramAccount $account,
        array $config,
    ): array {
        $members = [];

        foreach ($this->memberPages($api, $group, $account, $config) as $page) {
            foreach ($page['users'] as $user) {
                $members[(int) $user['id']] = $user;
            }
        }

        return array_values($members);
    }

    /**
     * The members list a page at a time, as it is read: the newest members
     * first (channelParticipantsRecent). Read to its end, the group is
     * marked read - its count, title and id written; left half way (enough
     * people found, a stop) it is not.
     *
     * Not getPwrChat: it searches the members a, b, c ... aa, ab ... in
     * hundreds of requests and waits out any flood wait up to a day, while
     * MadelineProto drops every request after 60 seconds. A wait over a
     * minute came back as "The operation was cancelled", and the account
     * could not read the group again until the wait was over.
     *
     * @param array<string, mixed> $config
     *
     * @return Generator<int, array{users: list<array<string, mixed>>, last: bool}>
     */
    private function memberPages(
        API $api,
        TelegramScrapeGroup $group,
        TelegramAccount $account,
        array $config,
    ): Generator {
        /* A link works for every account; a chat id only for one that has met the group. */
        $peer = 'https://t.me/' . $group->link;

        $seen = [];
        $total = 0;
        $offset = 0;
        $joined = false;
        $resolved = false;

        while (true) {
            try {
                /* A flood wait longer than half a minute is thrown, not waited out. */
                $page = $api->channels->getParticipants(
                    channel: $peer,
                    filter: ['_' => 'channelParticipantsRecent'],
                    offset: $offset,
                    limit: self::MEMBERS_PAGE,
                );
            } catch (Throwable $e) {
                /*
                 * Not in the group yet (an invite link, members shown to
                 * members only): it joins once and reads from the start.
                 * Flood and dead-account errors are not "not a member yet".
                 */
                if ($offset === 0 && ! $joined && ! $this->isFlood($e) && ! $this->isAccountError($e)
                    && $this->join($api, $group, $account, $config)) {
                    $joined = true;

                    continue;
                }

                throw $e;
            }

            $participants = (array) ($page['participants'] ?? []);

            /* A public group opens for a non-member without listing anybody. */
            if ($participants === [] && $offset === 0 && ! $joined) {
                $joined = true;

                if ($this->join($api, $group, $account, $config)) {
                    continue;
                }
            }

            if ($participants === []) {
                break;
            }

            /*
             * The link is looked up once a read: on every page an invite link
             * costs a checkChatInvite of its own. By now the account has met
             * the group, so its id is known to the session.
             */
            if (! $resolved) {
                $resolved = true;
                $peer = $this->peerOf($api, $peer);
            }

            $total = max($total, (int) ($page['count'] ?? 0));

            $users = [];

            foreach ((array) ($page['users'] ?? []) as $user) {
                if (isset($user['id'])) {
                    $users[(int) $user['id']] = $user;
                }
            }

            $members = [];

            foreach ($participants as $participant) {
                $user = $users[(int) ($participant['user_id'] ?? 0)] ?? null;

                /* A deleted account cannot be saved into anybody's contacts. */
                if ($user !== null && empty($user['deleted'])) {
                    $members[(int) $user['id']] = $user;
                    $seen[(int) $user['id']] = true;
                }
            }

            $offset += count($participants);

            yield ['users' => array_values($members), 'last' => $offset >= $total];

            if ($offset >= $total) {
                break;
            }

            /* Stopped half way: whoever was read is in the database, the group is not taken for read. */
            if ($this->stopping()) {
                return;
            }

            sleep(random_int(1, 3));
        }

        try {
            /* Known from the read already: no request of its own. */
            $info = (array) $api->getInfo($peer);
        } catch (Throwable) {
            $info = [];
        }

        $group->update([
            'title' => $info['Chat']['title'] ?? $group->title,
            'telegram_chat_id' => $info['bot_api_id'] ?? $group->telegram_chat_id,
            'members_count' => $total ?: count($seen),
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        $this->readGroups[$group->id] = true;
    }

    /**
     * The group's id for the rest of the read, the link when it cannot be
     * told.
     */
    private function peerOf(API $api, string $link): int|string
    {
        try {
            $id = ((array) $api->getInfo($link))['bot_api_id'] ?? null;
        } catch (Throwable) {
            $id = null;
        }

        return is_int($id) || (is_string($id) && is_numeric($id)) ? (int) $id : $link;
    }

    /**
     * The account joins the group when it is not in it yet.
     *
     * @param array<string, mixed> $config
     *
     * @return bool whether a join was actually made
     */
    private function join(
        API $api,
        TelegramScrapeGroup $group,
        TelegramAccount $account,
        array $config,
    ): bool {
        $link = $group->link;

        /* The joins this account may still make for the other kind of group in this run: none left, it reads only where it already is. */
        if ($this->joinsLeft !== null && $this->joinsLeft <= 0) {
            return false;
        }

        try {
            if (str_starts_with($link, '+') || str_starts_with($link, 'joinchat/')) {
                $api->messages->importChatInvite(
                    hash: ltrim((string) preg_replace('#^joinchat/#', '', $link), '+'),
                );
            } else {
                $api->channels->joinChannel(channel: '@' . $link);
            }
        } catch (RPCErrorException $e) {
            if (str_contains($e->rpc, 'USER_ALREADY_PARTICIPANT')) {
                return false;
            }

            throw $e;
        }

        if ($this->joinsLeft !== null) {
            $this->joinsLeft--;
        }

        $this->log('joined', 'Joined the group', $account->id, $group->id);

        $this->pause($config);

        return true;
    }

    /**
     * One row per Telegram user, whatever the number of groups; the pivot
     * records where they were seen, and when last: who is not on the latest
     * read of a group has left it. A few queries a page, not a few a person.
     * A phone is never wiped by a later read that does not show it.
     *
     * @param list<array<string, mixed>> $members
     * @param list<int>                  $ownIds
     *
     * @return int the people new to the database
     */
    private function storeMembers(TelegramScrapeGroup $group, array $members, array $ownIds): int
    {
        $own = array_flip($ownIds);
        $now = now();
        $rows = [];

        foreach ($members as $member) {
            $id = (int) ($member['id'] ?? 0);

            if ($id === 0 || isset($own[$id])) {
                continue;
            }

            $rows[$id] = [
                'telegram_user_id' => $id,
                'username' => self::cut($member['username'] ?? null),
                'first_name' => self::cut($member['first_name'] ?? null),
                'last_name' => self::cut($member['last_name'] ?? null),
                'phone' => self::cut($member['phone'] ?? null),
                'is_bot' => ($member['type'] ?? 'user') === 'bot' || ! empty($member['bot']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $new = 0;

        foreach (array_chunk($rows, 200, true) as $chunk) {
            $known = DB::table('telegram_scraped_users')->whereIn('telegram_user_id', array_keys($chunk))->count();
            $new += count($chunk) - $known;

            $withPhone = array_values(array_filter($chunk, fn (array $row) => $row['phone'] !== null));
            $without = array_values(array_filter($chunk, fn (array $row) => $row['phone'] === null));

            if ($withPhone !== []) {
                DB::table('telegram_scraped_users')->upsert($withPhone, ['telegram_user_id'], ['username', 'first_name', 'last_name', 'phone', 'is_bot', 'updated_at']);
            }

            if ($without !== []) {
                DB::table('telegram_scraped_users')->upsert($without, ['telegram_user_id'], ['username', 'first_name', 'last_name', 'is_bot', 'updated_at']);
            }

            $ids = DB::table('telegram_scraped_users')->whereIn('telegram_user_id', array_keys($chunk))->pluck('id');

            DB::table('telegram_scrape_group_user')->upsert(
                $ids->map(fn ($id) => ['group_id' => $group->id, 'scraped_user_id' => (int) $id, 'seen_at' => $now])->all(),
                ['group_id', 'scraped_user_id'],
                ['seen_at'],
            );
        }

        return $new;
    }

    private static function cut(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }

    /**
     * Our own accounts are never somebody's contact to collect.
     *
     * @return list<int>
     */
    private function ownIds(): array
    {
        return $this->ownIds ??= TelegramAccount::query()
            ->whereNotNull('telegram_user_id')
            ->pluck('telegram_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, true> telegram user ids already in the contacts
     */
    private function existingContactIds(API $api): array
    {
        try {
            $contacts = $api->contacts->getContacts();
        } catch (Throwable) {
            return [];
        }

        $ids = [];

        foreach ($contacts['contacts'] ?? [] as $contact) {
            if (isset($contact['user_id'])) {
                $ids[(int) $contact['user_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * Take the person for this account, or fail because somebody else did.
     */
    private function claim(TelegramAccount $account, TelegramScrapedUser $user, bool $alreadyContact): bool
    {
        $inserted = DB::table('telegram_account_contacts')->insertOrIgnore([
            'telegram_account_id' => $account->id,
            'scraped_user_id' => $user->id,
            'status' => $alreadyContact
                ? TelegramAccountContact::STATUS_SKIPPED
                : TelegramAccountContact::STATUS_PENDING,
            'added_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1;
    }

    private function finishClaim(
        TelegramAccount $account,
        TelegramScrapedUser $user,
        string $status,
        ?string $error = null,
        ?string $contactName = null,
    ): void {
        TelegramAccountContact::query()
            ->where('scraped_user_id', $user->id)
            ->where('telegram_account_id', $account->id)
            ->update([
                'status' => $status,
                'error' => $error,
                'contact_name' => $contactName,
                'added_at' => $status === TelegramAccountContact::STATUS_ADDED ? now() : null,
                'updated_at' => now(),
            ]);
    }

    /**
     * How many people this account has saved under the base nick so far -
     * the number the next one gets.
     */
    private function nickCount(TelegramAccount $account, string $base): int
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base);

        return TelegramAccountContact::query()
            ->where('telegram_account_id', $account->id)
            ->where(fn ($q) => $q->where('contact_name', $base)->orWhere('contact_name', 'like', $escaped . '-%'))
            ->count();
    }

    /**
     * @param string|null $nick the name to save them under; null keeps their own
     */
    private function addContact(API $api, TelegramScrapedUser $user, ?string $nick = null): void
    {
        if ($nick !== null) {
            $api->contacts->addContact(
                id: $user->telegram_user_id,
                first_name: $nick,
                last_name: '',
                add_phone_privacy_exception: false,
            );

            return;
        }

        $first = trim((string) $user->first_name);

        if ($first === '') {
            $first = $user->username ?: 'User ' . $user->telegram_user_id;
        }

        $api->contacts->addContact(
            id: $user->telegram_user_id,
            first_name: $first,
            last_name: (string) $user->last_name,
            add_phone_privacy_exception: false,
        );
    }

    /**
     * The account has this person in its Telegram contacts (the accounts
     * page tells where a contact is and where it is not). An add-on to the
     * sync: whatever goes wrong here never stops it.
     */
    private function markOnAccount(TelegramAccount $account, TelegramScrapedUser $user): void
    {
        try {
            DB::table('telegram_account_contact_links')->upsert(
                [[
                    'telegram_account_id' => $account->id,
                    'scraped_user_id' => $user->id,
                    'sync_token' => null,
                    'synced_at' => now(),
                ]],
                ['telegram_account_id', 'scraped_user_id'],
                ['synced_at'],
            );
        } catch (Throwable) {
            // not migrated yet, or a hiccup: the sync goes on
        }
    }

    private function addedToday(TelegramAccount $account): int
    {
        return TelegramAccountContact::query()
            ->where('telegram_account_id', $account->id)
            ->where('status', TelegramAccountContact::STATUS_ADDED)
            ->where('added_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Somebody in these groups still waits for an account: nobody's
     * contact, and in the group as its latest whole read found it - a
     * person who has left is no account's to save, and kept the run going
     * account after account for nobody.
     *
     * @param \Illuminate\Support\Collection<int, TelegramScrapeGroup> $groups
     */
    private function hasUnclaimed($groups, bool $skipUsernames): bool
    {
        foreach ($groups as $group) {
            /*
             * Seen on the latest read: the read stores people page by page
             * before it ends. Join group's groups are read by that process,
             * which keeps when a person was first seen, not last: all of
             * theirs count, and the account takes only who is on the list.
             */
            $since = $group->type !== TelegramScrapeGroup::TYPE_DATABASE ? $group->last_synced_at?->copy()->subHour() : null;

            $waits = TelegramScrapedUser::query()
                ->where('is_bot', false)
                ->whereDoesntHave('contact')
                ->whereDoesntHave('accountLinks')
                ->when($skipUsernames, fn ($q) => $q->withoutUsername())
                ->whereHas('groups', fn ($q) => $q
                    ->where('telegram_scrape_groups.id', $group->id)
                    ->when($since, fn ($q) => $q->where('telegram_scrape_group_user.seen_at', '>=', $since)))
                ->exists();

            if ($waits) {
                return true;
            }
        }

        return false;
    }

    private function isFlood(Throwable $e): bool
    {
        return $e instanceof RPCErrorException
            && (str_contains($e->rpc, 'FLOOD_WAIT') || str_contains($e->rpc, 'PEER_FLOOD'));
    }

    /**
     * Take the account out of the contact sync so no later run starts it
     * before its time. Only this process: the account keeps working for the
     * rest. It comes back by itself when its rest is over (endRests(): the
     * flood wait Telegram asked for, a day after PEER_FLOOD, a streak of
     * refusals or a hang); a dead session, or a second hang in a few days,
     * when somebody enables it again in the panel.
     */
    private function hold(
        TelegramAccount $account,
        Throwable $e,
        ?int $groupId = null,
        ?string $reason = null,
    ): void {
        $reason ??= $e instanceof RPCErrorException ? $e->rpc : $e::class;

        $this->held[] = $account->id;

        $this->pool->disable($account, ProcessEnum::ContactSync, $reason);

        $state = TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::ContactSync->value)
            ->first();

        $meta = (array) ($state?->meta ?? []);
        $seconds = $this->restFor($e, $reason, $meta);
        $until = $seconds !== null ? now()->addSeconds($seconds) : null;

        if ($reason === self::HUNG) {
            $meta['hung_at'] = now()->toIso8601String();
        }

        $state?->update(['meta' => [...$meta, 'rest_until' => $until?->toIso8601String(), 'rest_reason' => $state->disabled_reason]]);

        /*
         * A dead session is dead for every process, not just this one: the
         * same state telegram:account-check writes, so no pool picks it up
         * and the sessions page shows it as revoked.
         */
        if ($this->isAccountError($e)) {
            $account->update([
                'is_authorized' => false,
                'status' => TelegramAccount::STATUS_REVOKED,
                'last_checked_at' => now(),
                'last_error' => mb_substr($reason, 0, 500),
            ]);
        }

        $this->log(
            $this->isFlood($e) ? 'flood' : 'disabled',
            mb_substr($reason, 0, 300),
            $account->id,
            $groupId,
            ['message' => mb_substr($e->getMessage(), 0, 300), 'until' => $until?->toIso8601String()],
        );
    }

    /**
     * How long the account rests for this hold; null for good.
     *
     * @param array<string, mixed> $meta the account's state: when its session hung last
     */
    private function restFor(Throwable $e, string $reason, array $meta): ?int
    {
        if ($this->isAccountError($e)) {
            return null;
        }

        if ($reason === self::HUNG) {
            $last = isset($meta['hung_at']) ? Carbon::parse($meta['hung_at']) : null;

            return $last !== null && $last->gt(now()->subDays(self::HUNG_AGAIN_DAYS))
                ? null
                : self::REST_HOURS * 3600;
        }

        /* The wait Telegram named, whatever the error's name (FLOOD_WAIT_X, FLOOD_PREMIUM_WAIT_X). */
        if ($e instanceof RateLimitError && $reason === $e->rpc) {
            return max(1, $e->getWaitTime()) + self::FLOOD_MARGIN;
        }

        return self::restSeconds($reason);
    }

    private function isAccountError(Throwable $e): bool
    {
        /*
         * MadelineProto often wraps the RPC error (a failed start, an Amp
         * cancellation), so the whole chain is searched, not just the class.
         */
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $text = $current instanceof RPCErrorException
                ? $current->rpc
                : $current->getMessage();

            foreach (self::ACCOUNT_ERRORS as $code) {
                if (str_contains($text, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pause(array $config): void
    {
        $min = max(0, (int) ($config['delay_min'] ?? 3));
        $max = max($min, (int) ($config['delay_max'] ?? 5));

        if ($max <= 0) {
            return;
        }

        /* In one-second steps: a stop from the panel is not slept through. */
        $until = time() + random_int($min, $max);

        while (time() < $until && ! $this->stopping()) {
            sleep(1);
        }
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
        TelegramContactSyncLog::query()->create([
            'run_id' => $this->runId,
            'telegram_account_id' => $accountId,
            'group_id' => $groupId,
            'event' => $event,
            'message' => mb_substr($message, 0, 500),
            'context' => $context,
        ]);
    }
}
