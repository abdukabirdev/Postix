<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\DeadSession;
use App\Application\Telegram\Support\SessionSleep;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramJoinKeyword;
use App\Models\Telegram\TelegramJoinLog;
use App\Models\Telegram\TelegramJoinMembership;
use App\Models\Telegram\TelegramJoinSearch;
use App\Models\Telegram\TelegramScrapeGroup;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One marked account's Join group worker (telegram:group-join <account>,
 * kept running by telegram:group-join-watchdog). Nobody hands it groups: it
 * finds the groups of our topic itself, joins them the way a person would -
 * one at a time, a long pause after each, never past what Telegram allows -
 * and reads who is in them.
 *
 * How groups are found (GroupTopic):
 *   1. search   - Telegram's global search is asked every keyword, and the
 *                 keywords marked "with cities" once more with every city;
 *   2. links    - every group checked is read from outside (its last 100
 *                 messages): the t.me links, the @names that speak of the
 *                 topic and the groups its messages were forwarded from
 *                 become new groups to check - logistics chats advertise
 *                 each other all day, so this one snowballs;
 *   3. learning - two-word phrases that keep coming back in the titles of
 *                 the good groups become keywords of their own.
 * A group is joined only when it is alive, busy, of our topic and written
 * in Cyrillic or Latin letters (GroupQuality).
 *
 * The groups are the system's one set (telegram_scrape_groups): what it
 * finds is of type "database" - who is in it lands in telegram_scraped_users
 * through telegram_scrape_group_user, the same tables the contact sync
 * fills. Switched to "contacts" in the panel, a group is the contact sync's
 * to save into contacts; this worker never adds contacts.
 *
 * Round after round it opens its session for a short burst and closes it:
 * a join (and the people of that group right after), one of its groups
 * whose people were read long ago, a couple of searches, a few checks. The
 * workers share the searches and the checks: a row is taken (locked_until)
 * before it is worked on, so two accounts never do the same.
 *
 * A FLOOD_WAIT never ends the worker: the account waits what Telegram said
 * plus 20-30 seconds (the panel's flood_extra_*) and goes on by itself.
 */
final class GroupJoinService
{
    /** The group is not there for anybody: nothing more to try. */
    private const GROUP_GONE = [
        'CHANNEL_PRIVATE',
        'CHANNEL_INVALID',
        'CHANNEL_PUBLIC_GROUP_NA',
        'CHAT_INVALID',
        'USERNAME_INVALID',
        'USERNAME_NOT_OCCUPIED',
        'INVITE_HASH_EXPIRED',
        'INVITE_HASH_INVALID',
    ];

    /** The group hides its members from those who are not admins. */
    private const MEMBERS_HIDDEN = [
        'CHAT_ADMIN_REQUIRED',
        'CHANNEL_PRIVATE',
    ];

    /** A frozen account may still log in but can do nothing. */
    private const FROZEN = [
        'FROZEN_METHOD_INVALID',
        'FROZEN_PARTICIPANT_MISSING',
    ];

    /** What one burst takes on besides its join. */
    private const SEARCHES_PER_BURST = 2;
    private const CHECKS_PER_BURST = 10;

    /** A group this many accounts could not join is given up. */
    private const FAILED_ACCOUNTS_LIMIT = 3;

    /** PEER_FLOOD names no time: the account rests a day. */
    private const PEER_FLOOD_SECONDS = 86400;

    /** A taken search or group comes free after this, should its worker die. */
    private const LOCK_MINUTES = 15;

    /** People read a page at a time (Telegram's maximum). */
    private const MEMBERS_PAGE = 200;

    /** How often a worker brings the queries in line with the keywords and the topic, seconds. */
    private const REFRESH_EVERY = 600;

    /** How often a worker looks for phrases to learn, seconds; and how many learned keywords at most. */
    private const LEARN_EVERY = 1800;
    private const MAX_LEARNED = 300;

    private string $runId = '';

    private bool $stopping = false;

    private int $refreshedAt = 0;

    private int $learnedAt = 0;

    /** @var list<string> */
    private array $roots = [];

    /** @var list<int>|null our own accounts' Telegram ids: never stored as people */
    private ?array $ownIds = null;

    /** The account's session opened by the last burst: put to sleep when the worker ends. */
    private ?API $awake = null;

    public function __construct(
        private readonly MadelineService $madeline,
        private readonly TelegramAccountProcessService $pool,
        private readonly GroupJoinSettings $settings,
    ) {}

    /**
     * Asked by the command's signal handler: the burst in hand is finished,
     * then the worker ends.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Rounds until the account is unmarked, the join switched off, a stop
     * signal comes or $maxSeconds pass (0: no limit); the watchdog starts it
     * again when it should still run.
     *
     * @param callable(string): void|null $output
     *
     * @return array{searched: int, found: int, checked: int, joined: int, members: int}
     */
    public function work(TelegramAccount $account, ?callable $output = null, int $maxSeconds = 0, bool $once = false): array
    {
        $this->runId = (string) Str::uuid();

        $say = $output ?? static function (string $line): void {};

        $totals = ['searched' => 0, 'found' => 0, 'checked' => 0, 'joined' => 0, 'members' => 0];

        $this->log('started', 'Worker started, pid ' . getmypid(), $account->id);

        $started = time();

        while (! $this->stopping) {
            $config = $this->settings->current();

            if (! $this->mayWork($account, $config)) {
                $say('Not marked, switched off or not authorized any more.');

                break;
            }

            $state = $this->pool->getOrCreate($account, ProcessEnum::GroupJoin);
            $meta = (array) ($state->meta ?? []);

            if (! $state->is_available) {
                /* Put on hold by an error: waits until the panel switches it back on. */
                $this->doing($account, 'disabled');
                $this->wait(60);

                continue;
            }

            if (self::inFuture($meta['flood_until'] ?? null)) {
                $this->doing($account, 'flood');
                $this->wait(min(60, max(1, self::secondsUntil($meta['flood_until']))));

                continue;
            }

            $this->refresh();
            $this->learnKeywords($account);

            $plan = $this->plan($account, $config, $meta);

            if ($plan['join'] === null && $plan['members'] === null && $plan['searches'] === [] && $plan['checks'] === []) {
                $this->doing($account, 'idle');
                $this->wait($this->idleSeconds($meta));
            } else {
                try {
                    $this->session($account, $config, function (API $api) use ($account, $plan, $config, $say, &$totals): void {
                        $this->burst($api, $account, $plan, $config, $say, $totals);
                    });
                } finally {
                    $this->release($plan);
                }
            }

            if ($once || ($maxSeconds > 0 && time() - $started >= $maxSeconds)) {
                break;
            }

            $this->wait(random_int(3, 8));
        }

        SessionSleep::put($this->awake, ['account_id' => $account->id]);
        $this->awake = null;

        $this->doing($account, 'stopped');

        $this->log('finished', 'Worker finished', $account->id, null, $totals);

        return $totals;
    }

    /**
     * Whether this account is still one of Join group's.
     *
     * @param array<string, mixed> $config
     */
    public function mayWork(TelegramAccount $account, array $config): bool
    {
        $account->refresh();

        return $config['enabled']
            && in_array($account->id, $config['accounts'], true)
            && $account->is_authorized
            && filled($account->session_path);
    }

    /*
    |--------------------------------------------------------------------------
    | The burst
    |--------------------------------------------------------------------------
    */

    /**
     * What this round takes on. Every row in it is taken already.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     *
     * @return array{join: TelegramScrapeGroup|null, members: TelegramScrapeGroup|null, searches: list<TelegramJoinSearch>, checks: list<TelegramScrapeGroup>}
     */
    private function plan(TelegramAccount $account, array $config, array $meta): array
    {
        $plan = ['join' => null, 'members' => null, 'searches' => [], 'checks' => []];

        if ($this->canJoin($account, $config, $meta)) {
            $group = $this->nextGroupFor($account->id, $config);

            if ($group && $this->take(TelegramScrapeGroup::class, $group->id)) {
                $plan['join'] = $group;
            }
        }

        if ($config['read_members']) {
            $group = $this->staleMembersOf($account->id, $config);

            if ($group && $this->take(TelegramScrapeGroup::class, $group->id)) {
                $plan['members'] = $group;
            }
        }

        foreach ($this->dueSearches($config) as $search) {
            if (count($plan['searches']) >= self::SEARCHES_PER_BURST) {
                break;
            }

            if ($this->take(TelegramJoinSearch::class, $search->id)) {
                $plan['searches'][] = $search;
            }
        }

        foreach ($this->dueChecks($config) as $group) {
            if (count($plan['checks']) >= self::CHECKS_PER_BURST) {
                break;
            }

            if ($this->take(TelegramScrapeGroup::class, $group->id)) {
                $plan['checks'][] = $group;
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $config
     * @param array<string, int>   $totals
     */
    private function burst(API $api, TelegramAccount $account, array $plan, array $config, callable $say, array &$totals): void
    {
        $steps = [];

        if ($plan['join']) {
            $steps[] = function () use ($api, $account, $plan, $config, $say, &$totals): void {
                $this->doing($account, 'join');

                if ($this->joinOne($api, $account, $plan['join'], $config)) {
                    $totals['joined']++;

                    $say("joined {$plan['join']->link}");

                    $group = $plan['join']->fresh();

                    if ($config['read_members'] && $group->type === TelegramScrapeGroup::TYPE_DATABASE) {
                        $this->wait(random_int(5, 15));

                        $this->doing($account, 'members');
                        $totals['members'] += $this->readMembers($api, $account, $group, $config);
                    }
                }
            };
        }

        if ($plan['members']) {
            $steps[] = function () use ($api, $account, $plan, $config, &$totals): void {
                $this->doing($account, 'members');
                $totals['members'] += $this->readMembers($api, $account, $plan['members'], $config);
            };
        }

        foreach ($plan['searches'] as $search) {
            $steps[] = function () use ($api, $account, $search, $say, &$totals): void {
                $this->doing($account, 'search');

                $new = $this->searchOne($api, $account, $search);

                $totals['searched']++;
                $totals['found'] += $new;

                $say("search \"{$search->query}\": {$new} new");
            };
        }

        foreach ($plan['checks'] as $group) {
            $steps[] = function () use ($api, $account, $group, $config, $say, &$totals): void {
                $this->doing($account, 'check');

                $totals['found'] += $this->checkOne($api, $account, $group, $config);
                $totals['checked']++;

                $say("check {$group->link}: " . ($group->reject_reason ?? 'ok'));
            };
        }

        foreach ($steps as $i => $step) {
            if ($this->stopping) {
                return;
            }

            if ($i > 0) {
                $this->wait(random_int(2, 5));
            }

            try {
                $step();
            } catch (Throwable $e) {
                /* The account's trouble (a flood, a dead session) ends the burst; a group's does not. */
                if ($this->trouble($account, $e, $config)) {
                    return;
                }

                $this->log('failed', $this->reason($e), $account->id, null, ['exception' => $e::class]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    /**
     * Every few minutes: the topic's words again (a keyword added in the
     * panel widens it), and the queries in line with the keywords - a word
     * alone and, when it is marked so, with every city. Queries no keyword
     * makes any more go.
     */
    private function refresh(): void
    {
        if (time() - $this->refreshedAt < self::REFRESH_EVERY) {
            return;
        }

        $this->refreshedAt = time();
        $this->roots = GroupTopic::roots();

        $wanted = [];

        foreach (TelegramJoinKeyword::query()->where('is_active', true)->get() as $keyword) {
            foreach ($keyword->queries() as $query) {
                $wanted[mb_strtolower($query)] ??= ['keyword_id' => $keyword->id, 'query' => $query];
            }
        }

        $stale = [];

        foreach (TelegramJoinSearch::query()->get(['id', 'query']) as $search) {
            $key = mb_strtolower($search->query);

            if (isset($wanted[$key])) {
                unset($wanted[$key]);
            } else {
                $stale[] = $search->id;
            }
        }

        foreach (array_chunk($stale, 500) as $ids) {
            TelegramJoinSearch::query()->whereIn('id', $ids)->delete();
        }

        $now = now();

        foreach (array_chunk(array_values($wanted), 500) as $rows) {
            DB::table('telegram_join_searches')->insertOrIgnore(array_map(
                fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now],
                $rows,
            ));
        }
    }

    /**
     * Phrases that keep coming back in the titles of the good groups become
     * keywords (source learned), up to MAX_LEARNED of them.
     */
    private function learnKeywords(TelegramAccount $account): void
    {
        if (time() - $this->learnedAt < self::LEARN_EVERY) {
            return;
        }

        $this->learnedAt = time();

        try {
            $learned = TelegramJoinKeyword::query()->where('source', 'learned')->count();

            if ($learned >= self::MAX_LEARNED) {
                return;
            }

            $titles = TelegramScrapeGroup::query()
                ->joinable()
                ->whereIn('join_status', [TelegramScrapeGroup::JOIN_APPROVED, TelegramScrapeGroup::JOIN_JOINED])
                ->whereNotNull('title')
                ->latest('id')
                ->limit(5000)
                ->pluck('title')
                ->all();

            $known = TelegramJoinKeyword::query()->pluck('keyword')->map(fn ($k) => mb_strtolower((string) $k))->all();

            $phrases = GroupTopic::learn($titles, $this->roots ?: GroupTopic::roots(), $known, min(20, self::MAX_LEARNED - $learned));

            if ($phrases === []) {
                return;
            }

            $now = now();

            DB::table('telegram_join_keywords')->insertOrIgnore(array_map(fn (string $phrase) => [
                'keyword' => mb_substr($phrase, 0, 100),
                'topic' => null,
                'source' => 'learned',
                'expand' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $phrases));

            /* Their queries come with the next refresh. */
            $this->refreshedAt = 0;

            $this->log('learned', count($phrases) . ' new keywords: ' . implode(', ', $phrases), $account->id);
        } catch (Throwable $e) {
            $this->log('failed', 'Learning: ' . $e->getMessage(), $account->id);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return \Illuminate\Support\Collection<int, TelegramJoinSearch>
     */
    private function dueSearches(array $config)
    {
        return TelegramJoinSearch::query()
            ->with('keyword')
            ->where(fn ($q) => $q
                ->whereNull('last_searched_at')
                ->orWhere('last_searched_at', '<=', now()->subHours((int) $config['search_every_hours'])))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderByRaw('last_searched_at is null desc')
            ->orderBy('last_searched_at')
            ->orderBy('id')
            ->limit(self::SEARCHES_PER_BURST * 3)
            ->get();
    }

    /**
     * @return int the groups new to us
     */
    private function searchOne(API $api, TelegramAccount $account, TelegramJoinSearch $search): int
    {
        try {
            $found = $api->contacts->search(q: $search->query, limit: 100, floodWaitLimit: 0);
        } catch (Throwable $e) {
            if (self::isAccountTrouble($e)) {
                throw $e;
            }

            /* A query Telegram refuses is tried again at its next turn, not at once. */
            $search->update(['last_searched_at' => now(), 'found_count' => 0, 'locked_until' => null]);

            $this->log('failed', "Search \"{$search->query}\": " . $this->reason($e), $account->id);

            return 0;
        }

        [$total, $new] = $this->storeFound($search, (array) ($found['chats'] ?? []));

        $search->update(['last_searched_at' => now(), 'found_count' => $total, 'locked_until' => null]);

        $this->log('searched', "\"{$search->query}\": {$total} groups, {$new} new", $account->id, null, [
            'search_id' => $search->id,
            'total' => $total,
            'new' => $new,
        ]);

        return $new;
    }

    /**
     * The public groups among what the search returned. Channels are left
     * out (nobody but the admins writes there), and so are chats without a
     * username: those open only by an invite link.
     *
     * @param list<array<string, mixed>> $chats
     *
     * @return array{0: int, 1: int} groups seen, groups new
     */
    private function storeFound(TelegramJoinSearch $search, array $chats): array
    {
        $total = 0;
        $new = 0;

        foreach ($chats as $chat) {
            if (($chat['_'] ?? null) !== 'channel' || empty($chat['megagroup'])) {
                continue;
            }

            $username = GroupTopic::username($chat);

            if ($username === null) {
                continue;
            }

            $total++;

            $new += $this->discover(
                mb_strtolower($username),
                TelegramScrapeGroup::SOURCE_SEARCH,
                $search->query,
                $search->keyword_id,
                $chat['title'] ?? null,
                (int) ($chat['participants_count'] ?? 0),
                GroupTopic::chatId($chat),
            ) ? 1 : 0;
        }

        return [$total, $new];
    }

    /**
     * A group found: a new row of type database for Join group to check.
     * A group the system has already is only told its title and id: the
     * contact sync's own groups stay the contact sync's.
     *
     * @return bool whether it is new
     */
    private function discover(
        string $link,
        string $source,
        ?string $via,
        ?int $keywordId,
        ?string $title,
        int $members,
        ?int $chatId,
    ): bool {
        $group = TelegramScrapeGroup::query()->firstOrNew(['link' => $link]);

        $group->fill([
            'title' => $title ?? $group->title,
            'telegram_chat_id' => $chatId ?? $group->telegram_chat_id,
        ]);

        $new = ! $group->exists;

        if ($new) {
            $group->fill([
                'type' => TelegramScrapeGroup::TYPE_DATABASE,
                'is_active' => false,
                'source' => $source,
                'found_via' => $via !== null ? mb_substr($via, 0, 191) : null,
                'keyword_id' => $keywordId,
                'members_count' => $members,
                'join_status' => TelegramScrapeGroup::JOIN_NEW,
                'decided_by' => 'auto',
            ]);
        }

        $group->save();

        return $new;
    }

    /*
    |--------------------------------------------------------------------------
    | Check
    |--------------------------------------------------------------------------
    */

    /**
     * New ones first; the rest again every few days: a live group may go
     * quiet, a quiet one wake up.
     *
     * @param array<string, mixed> $config
     *
     * @return \Illuminate\Support\Collection<int, TelegramScrapeGroup>
     */
    private function dueChecks(array $config)
    {
        $stale = now()->subDays((int) $config['recheck_days']);

        return TelegramScrapeGroup::query()
            ->joinable()
            ->where(fn ($q) => $q
                /* One that failed to read waits an hour before the next try. */
                ->where(fn ($q) => $q
                    ->where('join_status', TelegramScrapeGroup::JOIN_NEW)
                    ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subHour())))
                ->orWhere(fn ($q) => $q
                    ->whereIn('join_status', [
                        TelegramScrapeGroup::JOIN_APPROVED,
                        TelegramScrapeGroup::JOIN_REJECTED,
                        TelegramScrapeGroup::JOIN_JOINED,
                    ])
                    ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', $stale))))
            /* Turned down by a person: not read again. */
            ->where(fn ($q) => $q
                ->where('decided_by', 'auto')
                ->orWhere('join_status', '!=', TelegramScrapeGroup::JOIN_REJECTED))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderByRaw("join_status = 'new' desc")
            ->orderBy('checked_at')
            ->orderBy('id')
            ->limit(self::CHECKS_PER_BURST * 2)
            ->get();
    }

    /**
     * Read the group from outside, without joining it, and keep the groups
     * its messages lead to.
     *
     * @param array<string, mixed> $config
     *
     * @return int the groups new to us its messages led to
     */
    private function checkOne(API $api, TelegramAccount $account, TelegramScrapeGroup $group, array $config): int
    {
        if ($group->isInvite()) {
            $this->checkInvite($api, $account, $group, $config);

            return 0;
        }

        $peer = '@' . $group->link;

        try {
            /* MadelineProto refuses channels.getFullChannel called directly. */
            $full = $api->getFullInfo($peer);

            $chat = (array) ($full['Chat'] ?? []);

            /* The name is a person's or a bot's, not a group's. */
            if (($chat['_'] ?? null) !== 'channel') {
                $this->markDead($group, 'NOT_A_GROUP', $account->id);

                return 0;
            }

            if (! empty($chat['broadcast']) && empty($chat['megagroup'])) {
                $this->decide($group, TelegramScrapeGroup::JOIN_REJECTED, 'channel');

                $this->log('checked', 'A channel, not a group', $account->id, $group->id);

                return 0;
            }

            $members = (int) ($full['full']['participants_count'] ?? $chat['participants_count'] ?? 0);

            $history = $api->messages->getHistory(peer: $peer, limit: 100, floodWaitLimit: 0);
        } catch (Throwable $e) {
            if ($e instanceof RPCErrorException && in_array($e->rpc, self::GROUP_GONE, true)) {
                $this->markDead($group, $e->rpc, $account->id);

                return 0;
            }

            if (! self::isAccountTrouble($e)) {
                /* Not readable now; tried again in an hour or at the next recheck. */
                $group->update(['last_error' => mb_substr($this->reason($e), 0, 500), 'checked_at' => now()]);
            }

            throw $e;
        }

        $messages = (array) ($history['messages'] ?? []);

        $this->applyCheck($group, $chat, $members, $messages, $config, now()->timestamp);

        $this->log('checked', $group->reject_reason ?? 'ok', $account->id, $group->id, [
            'members' => $group->members_count,
            'per_day' => $group->per_day,
            'messages_day' => $group->messages_day,
            'senders_week' => $group->senders_week,
            'topic_hits' => $group->topic_hits,
            'script_share' => $group->script_share,
            'score' => $group->score,
        ]);

        /* Only a group of our topic is followed further: its neighbours are likely ours too. */
        if (in_array($group->reject_reason, ['off_topic', 'script', 'channel'], true)) {
            return 0;
        }

        return $this->followLinks($account, $group, $messages, (array) ($history['chats'] ?? []));
    }

    /**
     * An invite link cannot be read from outside: its title, its size and
     * whether it is a group are all there is to go by.
     *
     * @param array<string, mixed> $config
     */
    private function checkInvite(API $api, TelegramAccount $account, TelegramScrapeGroup $group, array $config): void
    {
        try {
            $invite = $api->messages->checkChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0);
        } catch (RPCErrorException $e) {
            if (in_array($e->rpc, self::GROUP_GONE, true)) {
                $this->markDead($group, $e->rpc, $account->id);

                return;
            }

            throw $e;
        }

        /* chatInviteAlready / chatInvitePeek: one of ours is in it already. */
        $chat = (array) ($invite['chat'] ?? $invite);
        $title = $chat['title'] ?? $invite['title'] ?? $group->title;
        $members = (int) ($invite['participants_count'] ?? $chat['participants_count'] ?? 0);
        $isGroup = ! empty($invite['megagroup']) || ! empty($chat['megagroup']) || empty($invite['broadcast']);

        $reason = match (true) {
            ! $isGroup => 'channel',
            $members < (int) $config['min_members'] => 'too_small',
            ! GroupTopic::mentions((string) $title, $this->roots ?: GroupTopic::roots()) => 'off_topic',
            default => null,
        };

        $values = [
            'title' => $title,
            'members_count' => $members,
            'reject_reason' => $reason,
            'checked_at' => now(),
            'last_error' => null,
            'locked_until' => null,
        ];

        if ($group->decided_by !== 'manual' && $group->join_status !== TelegramScrapeGroup::JOIN_JOINED) {
            $values['join_status'] = $reason === null ? TelegramScrapeGroup::JOIN_APPROVED : TelegramScrapeGroup::JOIN_REJECTED;
        }

        $group->update($values);

        $this->log('checked', ($reason ?? 'ok') . ' (invite)', $account->id, $group->id);
    }

    /**
     * What the check read, written on the group. A person's approve or
     * reject stands; the numbers are refreshed all the same.
     *
     * @param array<string, mixed>       $chat
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $config
     */
    private function applyCheck(
        TelegramScrapeGroup $group,
        array $chat,
        int $members,
        array $messages,
        array $config,
        int $now,
    ): void {
        $roots = $this->roots ?: GroupTopic::roots();
        $title = $chat['title'] ?? $group->title;

        $metrics = GroupQuality::measure($messages, $now, $roots);
        $reason = GroupQuality::verdict($metrics, $members, $config, $now, $title, $roots);

        $values = [
            'title' => $title,
            'telegram_chat_id' => GroupTopic::chatId($chat) ?? $group->telegram_chat_id,
            'members_count' => $members,
            'last_message_at' => $metrics['last_message_at'] !== null
                ? Carbon::createFromTimestamp($metrics['last_message_at'])
                : null,
            'messages_day' => $metrics['messages_day'],
            'messages_week' => $metrics['messages_week'],
            'senders_week' => $metrics['senders_week'],
            'per_day' => $metrics['per_day'],
            'script_share' => $metrics['script_share'],
            'topic_hits' => $metrics['topic_messages'],
            'score' => GroupQuality::score($metrics),
            'reject_reason' => $reason,
            'checked_at' => Carbon::createFromTimestamp($now),
            'last_error' => null,
            'locked_until' => null,
        ];

        /*
         * A joined group stays joined: a reason on it is a warning (it went
         * quiet), the accounts are not taken out.
         */
        if ($group->decided_by !== 'manual') {
            $values['join_status'] = match (true) {
                $group->join_status === TelegramScrapeGroup::JOIN_JOINED => TelegramScrapeGroup::JOIN_JOINED,
                $reason !== null => TelegramScrapeGroup::JOIN_REJECTED,
                default => TelegramScrapeGroup::JOIN_APPROVED,
            };
        }

        $group->update($values);
    }

    /**
     * The groups a checked group's messages lead to, kept as new ones.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $chats
     *
     * @return int the groups new to us
     */
    private function followLinks(TelegramAccount $account, TelegramScrapeGroup $group, array $messages, array $chats): int
    {
        $new = 0;

        foreach (GroupTopic::links($messages, $chats, $this->roots ?: GroupTopic::roots(), $group->link) as $found) {
            $new += $this->discover(
                $found['link'],
                $found['source'] === 'forward' ? TelegramScrapeGroup::SOURCE_FORWARD : TelegramScrapeGroup::SOURCE_LINK,
                $group->link,
                null,
                $found['title'],
                $found['members'],
                $found['chat_id'],
            ) ? 1 : 0;
        }

        if ($new > 0) {
            $this->log('found', "{$new} new groups from its messages", $account->id, $group->id, ['new' => $new]);
        }

        return $new;
    }

    /*
    |--------------------------------------------------------------------------
    | Join
    |--------------------------------------------------------------------------
    */

    /**
     * Its pause is over, and today's joins and the groups it holds are
     * under the limits.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     */
    private function canJoin(TelegramAccount $account, array $config, array $meta): bool
    {
        if (! empty($meta['full']) || self::inFuture($meta['next_join_at'] ?? null)) {
            return false;
        }

        $held = TelegramJoinMembership::query()
            ->where('telegram_account_id', $account->id)
            ->whereIn('status', [TelegramJoinMembership::STATUS_JOINED, TelegramJoinMembership::STATUS_REQUESTED]);

        if ((clone $held)->where('joined_at', '>=', now()->startOfDay())->count() >= (int) $config['joins_per_day']) {
            return false;
        }

        return $held->count() < (int) $config['max_groups_per_account'];
    }

    /**
     * The best group this account is not in yet and that still wants
     * accounts. Only one checked lately, or one a person approved.
     *
     * @param array<string, mixed> $config
     */
    private function nextGroupFor(int $accountId, array $config): ?TelegramScrapeGroup
    {
        return TelegramScrapeGroup::query()
            ->whereIn('join_status', [TelegramScrapeGroup::JOIN_APPROVED, TelegramScrapeGroup::JOIN_JOINED])
            ->whereDoesntHave('memberships', fn ($q) => $q->where('telegram_account_id', $accountId))
            ->whereRaw(
                '(select count(*) from telegram_join_memberships m where m.group_id = telegram_scrape_groups.id and m.status in (?, ?)) < ?',
                [
                    TelegramJoinMembership::STATUS_JOINED,
                    TelegramJoinMembership::STATUS_REQUESTED,
                    (int) $config['accounts_per_group'],
                ],
            )
            ->where(fn ($q) => $q
                ->where('decided_by', 'manual')
                ->orWhere('checked_at', '>=', now()->subDays((int) $config['recheck_days'])))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            /* A group a person wants first, then the livelier the sooner. */
            ->orderByRaw("decided_by = 'manual' desc")
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return bool whether the account is in the group now
     */
    private function joinOne(API $api, TelegramAccount $account, TelegramScrapeGroup $group, array $config): bool
    {
        try {
            $result = $group->isInvite()
                ? $api->messages->importChatInvite(hash: $group->inviteHash(), floodWaitLimit: 0)
                : $api->channels->joinChannel(channel: '@' . $group->link, floodWaitLimit: 0);
        } catch (RPCErrorException $e) {
            $rpc = $e->rpc;

            if ($rpc === 'USER_ALREADY_PARTICIPANT') {
                /* In already, by hand or long ago: no join spent, no pause. */
                $this->membership($group, $account, TelegramJoinMembership::STATUS_JOINED);
                $this->markJoined($group);

                $this->log('joined', 'Already a member', $account->id, $group->id);

                return true;
            }

            if ($rpc === 'INVITE_REQUEST_SENT') {
                $this->membership($group, $account, TelegramJoinMembership::STATUS_REQUESTED, null, now());
                $this->scheduleNext($account, $config);

                $this->log('requested', 'The admins have to approve', $account->id, $group->id);

                return false;
            }

            if (in_array($rpc, self::GROUP_GONE, true)) {
                $this->markDead($group, $rpc, $account->id);

                return false;
            }

            if (self::isAccountTrouble($e)) {
                throw $e;
            }

            $this->failed($group, $account, $rpc, $config);

            return false;
        } catch (Throwable $e) {
            if (self::isAccountTrouble($e)) {
                throw $e;
            }

            /* Written down, so the next burst does not try the same group again at once. */
            $this->failed($group, $account, mb_substr($e->getMessage(), 0, 200), $config);

            return false;
        }

        $this->learnChat($group, (array) ($result['chats'] ?? []));

        $this->membership($group, $account, TelegramJoinMembership::STATUS_JOINED, null, now());
        $this->scheduleNext($account, $config);
        $this->markJoined($group);

        $this->log('joined', 'Joined', $account->id, $group->id);

        return true;
    }

    private function markJoined(TelegramScrapeGroup $group): void
    {
        if ($group->join_status !== TelegramScrapeGroup::JOIN_JOINED) {
            $group->update(['join_status' => TelegramScrapeGroup::JOIN_JOINED]);
        }
    }

    /**
     * An invite link said nothing about the group before; the join does.
     *
     * @param list<array<string, mixed>> $chats
     */
    private function learnChat(TelegramScrapeGroup $group, array $chats): void
    {
        $chat = $chats[0] ?? null;

        if (! is_array($chat)) {
            return;
        }

        $group->update([
            'title' => $chat['title'] ?? $group->title,
            'telegram_chat_id' => GroupTopic::chatId($chat) ?? $group->telegram_chat_id,
        ]);
    }

    private function membership(
        TelegramScrapeGroup $group,
        TelegramAccount $account,
        string $status,
        ?string $error = null,
        ?Carbon $joinedAt = null,
    ): void {
        TelegramJoinMembership::query()->updateOrCreate(
            ['group_id' => $group->id, 'telegram_account_id' => $account->id],
            ['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255), 'joined_at' => $joinedAt],
        );
    }

    /**
     * This account could not get in. Another one will try; after a few the
     * group is given up.
     *
     * @param array<string, mixed> $config
     */
    private function failed(TelegramScrapeGroup $group, TelegramAccount $account, string $error, array $config): void
    {
        $this->membership($group, $account, TelegramJoinMembership::STATUS_FAILED, $error);

        /* Nothing was joined, but the request was made: the pause still applies. */
        $this->scheduleNext($account, $config);

        $this->log('failed', 'Join: ' . $error, $account->id, $group->id);

        $failures = TelegramJoinMembership::query()
            ->where('group_id', $group->id)
            ->where('status', TelegramJoinMembership::STATUS_FAILED)
            ->count();

        if ($failures >= self::FAILED_ACCOUNTS_LIMIT) {
            $this->markDead($group, "{$failures} accounts failed: {$error}", $account->id);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Members
    |--------------------------------------------------------------------------
    */

    /**
     * One of this account's database groups whose people were never read,
     * or long ago. A contacts group is the contact sync's to read.
     *
     * @param array<string, mixed> $config
     */
    private function staleMembersOf(int $accountId, array $config): ?TelegramScrapeGroup
    {
        return TelegramScrapeGroup::query()
            ->where('type', TelegramScrapeGroup::TYPE_DATABASE)
            ->whereHas('memberships', fn ($q) => $q
                ->where('telegram_account_id', $accountId)
                ->where('status', TelegramJoinMembership::STATUS_JOINED))
            ->where('join_status', '!=', TelegramScrapeGroup::JOIN_DEAD)
            ->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', now()->subDays((int) $config['members_every_days'])))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderByRaw('last_synced_at is null desc')
            ->orderBy('last_synced_at')
            ->first();
    }

    /**
     * Who is in the group, page by page up to the panel's limit, into
     * telegram_scraped_users and telegram_scrape_group_user.
     *
     * @param array<string, mixed> $config
     *
     * @return int the people read
     */
    private function readMembers(API $api, TelegramAccount $account, TelegramScrapeGroup $group, array $config): int
    {
        /* A group joined by an invite has no username, only its id. */
        $peer = $group->telegram_chat_id ?: '@' . $group->link;
        $max = (int) $config['max_members_read'];

        $read = 0;
        $offset = 0;

        while ($read < $max && ! $this->stopping) {
            try {
                $page = $api->channels->getParticipants(
                    channel: $peer,
                    filter: ['_' => 'channelParticipantsRecent'],
                    offset: $offset,
                    limit: min(self::MEMBERS_PAGE, $max - $read),
                    floodWaitLimit: 0,
                );
            } catch (RPCErrorException $e) {
                if (in_array($e->rpc, self::MEMBERS_HIDDEN, true)) {
                    $group->update(['last_synced_at' => now(), 'locked_until' => null]);

                    $this->log('members', "Members hidden ({$e->rpc})", $account->id, $group->id);

                    return $read;
                }

                if (! self::isAccountTrouble($e)) {
                    /* Tried again at the next members turn, not at once. */
                    $group->update(['members_read' => $read, 'last_synced_at' => now(), 'locked_until' => null]);

                    $this->log('failed', "Members: {$e->rpc}", $account->id, $group->id);

                    return $read;
                }

                throw $e;
            }

            $participants = (array) ($page['participants'] ?? []);

            if ($participants === []) {
                break;
            }

            $this->storeMembers($group, (array) ($page['users'] ?? []));

            $read += count($participants);
            $offset += count($participants);

            if ($offset >= (int) ($page['count'] ?? 0)) {
                break;
            }

            $this->wait(random_int(1, 3));
        }

        $group->update(['members_read' => $read, 'last_synced_at' => now(), 'locked_until' => null]);

        $this->log('members', "{$read} people read", $account->id, $group->id, ['read' => $read]);

        return $read;
    }

    /**
     * One row a person in telegram_scraped_users, whatever the number of
     * groups; the group-person link in telegram_scrape_group_user. A phone
     * is never wiped by a later read that does not show it.
     *
     * @param list<array<string, mixed>> $users the users part of a participants page
     *
     * @return int the people stored
     */
    private function storeMembers(TelegramScrapeGroup $group, array $users): int
    {
        $own = $this->ownIds();
        $now = now();
        $rows = [];

        foreach ($users as $user) {
            if (! isset($user['id'])) {
                continue;
            }

            $id = (int) $user['id'];

            if (in_array($id, $own, true)) {
                continue;
            }

            $phone = isset($user['phone']) ? TelegramAccount::normalizePhone((string) $user['phone']) : '';

            $rows[$id] = [
                'telegram_user_id' => $id,
                'username' => self::cut(GroupTopic::username($user), 191),
                'first_name' => self::cut($user['first_name'] ?? null, 191),
                'last_name' => self::cut($user['last_name'] ?? null, 191),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 32) : null,
                'is_bot' => ! empty($user['bot']) || ($user['type'] ?? null) === 'bot',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500, true) as $chunk) {
            $withPhone = array_values(array_filter($chunk, fn (array $row) => $row['phone'] !== null));
            $without = array_values(array_filter($chunk, fn (array $row) => $row['phone'] === null));

            if ($withPhone !== []) {
                DB::table('telegram_scraped_users')->upsert($withPhone, ['telegram_user_id'], ['username', 'first_name', 'last_name', 'phone', 'is_bot', 'updated_at']);
            }

            if ($without !== []) {
                DB::table('telegram_scraped_users')->upsert($without, ['telegram_user_id'], ['username', 'first_name', 'last_name', 'is_bot', 'updated_at']);
            }

            $ids = DB::table('telegram_scraped_users')->whereIn('telegram_user_id', array_keys($chunk))->pluck('id');

            DB::table('telegram_scrape_group_user')->insertOrIgnore($ids->map(fn ($id) => [
                'group_id' => $group->id,
                'scraped_user_id' => (int) $id,
                'seen_at' => $now,
            ])->all());
        }

        return count($rows);
    }

    /**
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

    /*
    |--------------------------------------------------------------------------
    | The account
    |--------------------------------------------------------------------------
    */

    /**
     * Open the account and do the work. The session stays awake between
     * bursts - they come seconds apart - and goes to sleep when the worker
     * ends (see work()).
     *
     * @param array<string, mixed> $config
     * @param callable(API): void  $work
     */
    private function session(TelegramAccount $account, array $config, callable $work): void
    {
        try {
            $api = $this->madeline->for($account);

            if (! $api) {
                throw $this->madeline->lastError ?? new RuntimeException('Failed to start MadelineProto.');
            }

            $this->awake = $api;

            $work($api);

            $this->pool->registerSuccess($account, ProcessEnum::GroupJoin);
        } catch (Throwable $e) {
            if (! $this->trouble($account, $e, $config)) {
                $this->pool->registerFailure($account, ProcessEnum::GroupJoin, mb_substr($this->reason($e), 0, 200));

                $this->log('failed', $this->reason($e), $account->id, null, ['exception' => $e::class]);
            }
        }
    }

    /**
     * Whether the error is the account's, not the group's - and then what
     * becomes of the account. True means: stop working with it now.
     *
     * @param array<string, mixed> $config
     */
    private function trouble(TelegramAccount $account, Throwable $e, array $config, ?int $groupId = null): bool
    {
        if ($code = DeadSession::code($e)) {
            DeadSession::retire($account->id, $code);

            $this->pool->disable($account, ProcessEnum::GroupJoin, $code);

            $this->log('disabled', $code, $account->id, $groupId);

            return true;
        }

        $rpc = $e instanceof RPCErrorException ? $e->rpc : '';

        if ($e instanceof RateLimitError || str_starts_with($rpc, 'FLOOD_WAIT') || str_starts_with($rpc, 'FLOOD_PREMIUM_WAIT')) {
            $this->floodWait($account, self::waitSeconds($e), $config, $groupId, $rpc);

            return true;
        }

        if ($rpc === 'PEER_FLOOD') {
            $this->floodWait($account, self::PEER_FLOOD_SECONDS, $config, $groupId, $rpc);

            return true;
        }

        if (in_array($rpc, self::FROZEN, true)) {
            $this->pool->disable($account, ProcessEnum::GroupJoin, $rpc);

            $this->log('disabled', "{$rpc}: the account is frozen by Telegram", $account->id, $groupId);

            return true;
        }

        if ($rpc === 'CHANNELS_TOO_MUCH') {
            $this->setMeta($account, ['full' => true]);

            $this->log('full', 'The account is in as many groups as Telegram allows', $account->id, $groupId);

            return true;
        }

        return false;
    }

    /**
     * The errors trouble() takes on: they are the account's, not a group's.
     */
    private static function isAccountTrouble(Throwable $e): bool
    {
        if (DeadSession::code($e) !== null || $e instanceof RateLimitError) {
            return true;
        }

        $rpc = $e instanceof RPCErrorException ? $e->rpc : '';

        return str_starts_with($rpc, 'FLOOD_WAIT')
            || str_starts_with($rpc, 'FLOOD_PREMIUM_WAIT')
            || in_array($rpc, ['PEER_FLOOD', 'CHANNELS_TOO_MUCH', ...self::FROZEN], true);
    }

    /**
     * The account waits what Telegram said, plus the panel's extra seconds,
     * then goes on by itself.
     *
     * @param array<string, mixed> $config
     */
    private function floodWait(TelegramAccount $account, int $seconds, array $config, ?int $groupId, string $rpc): void
    {
        $extra = random_int((int) $config['flood_extra_min'], (int) $config['flood_extra_max']);
        $until = now()->addSeconds($seconds + $extra);

        $this->setMeta($account, [
            'flood_until' => $until->toIso8601String(),
            'flood_rpc' => $rpc !== '' ? $rpc : 'FLOOD_WAIT',
        ]);

        $this->log('flood', "{$rpc}: waits {$seconds}s + {$extra}s, until {$until->format('d.m H:i:s')}", $account->id, $groupId, [
            'seconds' => $seconds,
            'extra' => $extra,
            'until' => $until->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function scheduleNext(TelegramAccount $account, array $config): void
    {
        $minutes = random_int((int) $config['join_delay_min'], (int) $config['join_delay_max']);

        $this->setMeta($account, ['next_join_at' => now()->addMinutes($minutes)->toIso8601String()]);
    }

    /**
     * What the worker is busy with, for the status page.
     */
    private function doing(TelegramAccount $account, string $what): void
    {
        try {
            $this->setMeta($account, [
                'doing' => $what,
                'heartbeat_at' => now()->toIso8601String(),
                'pid' => getmypid(),
            ]);
        } catch (Throwable) {
            // The page shows an older state; the work goes on.
        }
    }

    /**
     * Merge into the account's group_join meta; a null removes the key.
     *
     * @param array<string, mixed> $changes
     */
    private function setMeta(TelegramAccount $account, array $changes): void
    {
        $state = $this->pool->getOrCreate($account, ProcessEnum::GroupJoin);

        $meta = array_filter(
            array_replace((array) $state->meta, $changes),
            static fn ($value) => $value !== null,
        );

        $state->update(['meta' => $meta]);
    }

    /**
     * Until its pause is over, between 5 seconds and a minute.
     *
     * @param array<string, mixed> $meta
     */
    private function idleSeconds(array $meta): int
    {
        $left = self::secondsUntil($meta['next_join_at'] ?? null);

        return $left > 0 ? max(5, min(60, $left)) : 60;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Take a search or a group for this worker; false when another has it.
     *
     * @param class-string<TelegramScrapeGroup|TelegramJoinSearch> $model
     */
    private function take(string $model, int $id): bool
    {
        return $model::query()
            ->whereKey($id)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['locked_until' => now()->addMinutes(self::LOCK_MINUTES)]) === 1;
    }

    /**
     * Give back what the burst took and did not finish.
     *
     * @param array<string, mixed> $plan
     */
    private function release(array $plan): void
    {
        $groups = array_filter([$plan['join']?->id, $plan['members']?->id, ...array_map(fn ($g) => $g->id, $plan['checks'])]);
        $searches = array_map(fn ($s) => $s->id, $plan['searches']);

        try {
            if ($groups !== []) {
                TelegramScrapeGroup::query()->whereIn('id', $groups)->update(['locked_until' => null]);
            }

            if ($searches !== []) {
                TelegramJoinSearch::query()->whereIn('id', $searches)->update(['locked_until' => null]);
            }
        } catch (Throwable) {
            // They come free by themselves after LOCK_MINUTES.
        }
    }

    /**
     * The check's verdict; a person's approve or reject stands.
     */
    private function decide(TelegramScrapeGroup $group, string $status, ?string $reason): void
    {
        $values = ['reject_reason' => $reason, 'checked_at' => now(), 'last_error' => null, 'locked_until' => null];

        if ($group->decided_by !== 'manual') {
            $values['join_status'] = $status;
        }

        $group->update($values);
    }

    private function markDead(TelegramScrapeGroup $group, string $reason, ?int $accountId = null): void
    {
        $group->update([
            'join_status' => TelegramScrapeGroup::JOIN_DEAD,
            'last_error' => mb_substr($reason, 0, 500),
            'checked_at' => now(),
            'locked_until' => null,
        ]);

        $this->log('dead', $reason, $accountId, $group->id);
    }

    /**
     * In one-second steps so a stop signal is not slept through.
     */
    private function wait(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->stopping; $i++) {
            sleep(1);
        }
    }

    private static function waitSeconds(Throwable $e): int
    {
        if ($e instanceof RateLimitError) {
            return max(1, $e->getWaitTime());
        }

        $rpc = $e instanceof RPCErrorException ? $e->rpc : $e->getMessage();

        return preg_match('/(\d+)/', $rpc, $m) === 1 ? max(1, (int) $m[1]) : 60;
    }

    private static function inFuture(mixed $at): bool
    {
        return self::secondsUntil($at) > 0;
    }

    private static function secondsUntil(mixed $at): int
    {
        if (! is_string($at) || $at === '') {
            return 0;
        }

        $time = strtotime($at);

        return $time === false ? 0 : max(0, $time - now()->timestamp);
    }

    private static function cut(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function reason(Throwable $e): string
    {
        return $e instanceof RPCErrorException ? $e->rpc : $e->getMessage();
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
        TelegramJoinLog::query()->create([
            'run_id' => $this->runId,
            'telegram_account_id' => $accountId,
            'group_id' => $groupId,
            'event' => $event,
            'message' => mb_substr($message, 0, 500),
            'context' => $context,
        ]);
    }
}
