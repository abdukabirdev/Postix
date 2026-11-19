<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramJoinMembership;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who of the base is worth inviting into our groups. The owner answered
 * case by case (2026-11-18): online today, ten days ago, a month ago, wrote
 * or not - all taken; nothing known of them - not taken; left one of our
 * groups after being added - not taken in any other group either.
 *  - green (invite_ok true): something is known of them - when they were
 *    online (any status, "long ago" too), that they wrote in a group, or
 *    that they sit in our "members welcome" group - and they never left
 *    one of our groups;
 *  - red (false): Telegram showed them added into one of our groups and
 *    the "who left" check does not find them there any more (STATUS_LEFT);
 *    back in the group - green again;
 *  - unchecked (null): nothing known of them yet.
 * The invite takes the green only (while the panel's "only active" is on),
 * and never the ones who left.
 *
 * Learnt from what Telegram sends anyway: every user object carries its
 * status (members lists, contacts, messages), every message its author.
 * observe() keeps it on the person; each account looks once a day at its
 * contacts and at what was written in its groups (checkAccount()).
 */
final class PeopleActivity
{
    public const GREEN = 'green';

    public const RED = 'red';

    public const UNCHECKED = 'unchecked';

    public const STATES = [self::GREEN, self::RED, self::UNCHECKED];

    /** Seen on our group's members list this long ago: the overview's "in our groups" number. */
    public const OUR_GROUP_DAYS = 7;

    /** One account's last look, by account id (unix time). */
    private const ACCOUNTS_KEY = 'group_invite.activity_accounts';

    /** A group whose messages were read is not read again this long, by whichever account. Hours. */
    private const GROUP_FRESH_HOURS = 12;

    private const HISTORY_PAGE = 100;

    /** At most this many pages of a group's messages: a busy board writes thousands in three days. */
    private const HISTORY_PAGES = 10;

    /** @var list<int>|null */
    private ?array $ownIds = null;

    public function __construct(private readonly GroupInviteSettings $settings) {}

    /**
     * What a user's status says of when they were online: the moment, as
     * near as it is told, and its kind. A hidden "last seen" gives only a
     * range - its likely end is taken. Null: nothing said (a "min" user
     * comes without a status).
     *
     * @param array<string, mixed> $user
     *
     * @return array{at: Carbon|null, kind: string}|null
     */
    public static function seen(array $user, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $status = $user['status'] ?? null;

        if (! is_array($status)) {
            /* A full user without a status hides it entirely or never showed up: "long ago". */
            return ! empty($user['min']) ? null : ['at' => null, 'kind' => 'long_ago'];
        }

        return match ($status['_'] ?? null) {
            'userStatusOnline' => ['at' => $now->copy(), 'kind' => 'online'],
            'userStatusOffline' => ['at' => Carbon::createFromTimestamp((int) ($status['was_online'] ?? 0))->setTimezone($now->getTimezone()), 'kind' => 'offline'],
            /* "Recently": up to two-three days. */
            'userStatusRecently' => ['at' => $now->copy()->subDays(2), 'kind' => 'recently'],
            /* "Within a week". */
            'userStatusLastWeek' => ['at' => $now->copy()->subDays(6), 'kind' => 'last_week'],
            'userStatusLastMonth' => ['at' => $now->copy()->subDays(20), 'kind' => 'last_month'],
            default => ['at' => null, 'kind' => 'long_ago'],
        };
    }

    /**
     * Green, red or unchecked (null): left one of our groups - red; anything
     * known of them - green; nothing - unchecked.
     */
    public static function rule(bool $known, bool $left): ?bool
    {
        return $left ? false : ($known ? true : null);
    }

    /**
     * The people who were added into one of our groups and left it: their
     * ids in the base.
     */
    public static function leftQuery(): QueryBuilder
    {
        return DB::table('telegram_group_invites')
            ->where('status', TelegramGroupInvite::STATUS_LEFT)
            ->select('scraped_user_id');
    }

    /**
     * Keeps what these user objects tell: when each was online, when they
     * wrote ($posted: telegram id => unix time of their latest message),
     * that they are on our welcome group's list ($ourGroup). Each person
     * is green or red after. People new to the base are written only with
     * $insert - with $authorsOnly, only the authors among them (a page of
     * messages names old authors, mentioned and forwarded people too).
     *
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $users
     * @param array<int, int>                                             $posted
     *
     * @return array{seen: int, green: int, new: int}
     */
    public function observe(array $users, array $posted = [], bool $ourGroup = false, bool $insert = false, bool $authorsOnly = false): array
    {
        $now = now();
        $own = array_flip($this->ownIds());
        $found = [];

        foreach ($users as $user) {
            if (! is_array($user) || ($user['_'] ?? 'user') !== 'user' || ! empty($user['deleted'])) {
                continue;
            }

            $id = (int) ($user['id'] ?? 0);

            if ($id <= 0 || isset($own[$id])) {
                continue;
            }

            $found[$id] = $user;
        }

        $done = ['seen' => 0, 'green' => 0, 'new' => 0];

        foreach (array_chunk($found, 500, true) as $chunk) {
            $known = DB::table('telegram_scraped_users')
                ->whereIn('telegram_user_id', array_keys($chunk))
                ->get(['id', 'telegram_user_id', 'last_seen_at', 'seen_status', 'last_posted_at', 'our_group_at'])
                ->keyBy('telegram_user_id');

            $left = $known->isEmpty() ? [] : array_flip(self::leftQuery()
                ->whereIn('scraped_user_id', $known->pluck('id')->all())
                ->distinct()
                ->pluck('scraped_user_id')
                ->map(fn ($id) => (int) $id)
                ->all());

            $update = [];
            $fresh = [];

            foreach ($chunk as $id => $user) {
                $old = $known->get($id);

                if ($old === null && (! $insert || ! empty($user['bot']) || ($authorsOnly && ! isset($posted[$id])))) {
                    continue;
                }

                $seen = self::seen($user, $now);
                $lastSeen = self::later(self::time($old?->last_seen_at), $seen['at'] ?? null);
                $lastPosted = self::later(self::time($old?->last_posted_at), isset($posted[$id]) ? Carbon::createFromTimestamp($posted[$id])->setTimezone($now->getTimezone()) : null);
                $inOurs = $ourGroup ? $now->copy() : self::time($old?->our_group_at);
                $seenKind = $seen['kind'] ?? $old?->seen_status;
                $state = self::rule(
                    $lastSeen !== null || $seenKind !== null || $lastPosted !== null || $inOurs !== null,
                    $old !== null && isset($left[(int) $old->id]),
                );
                $green = $state === true;

                $row = [
                    'telegram_user_id' => $id,
                    'last_seen_at' => $lastSeen?->toDateTimeString(),
                    'seen_status' => $seenKind,
                    'last_posted_at' => $lastPosted?->toDateTimeString(),
                    'our_group_at' => $inOurs?->toDateTimeString(),
                    'activity_checked_at' => $now->toDateTimeString(),
                    'invite_ok' => $state,
                    'updated_at' => $now->toDateTimeString(),
                ];

                $done['seen']++;
                $done['green'] += $green ? 1 : 0;

                if ($old !== null) {
                    $update[] = $row;

                    continue;
                }

                $done['new']++;
                $phone = isset($user['phone']) ? TelegramAccount::normalizePhone((string) $user['phone']) : '';

                $fresh[] = [
                    ...$row,
                    'username' => self::cut(GroupTopic::username($user)),
                    'first_name' => self::cut($user['first_name'] ?? null),
                    'last_name' => self::cut($user['last_name'] ?? null),
                    'phone' => $phone !== '' ? mb_substr($phone, 0, 32) : null,
                    'is_bot' => false,
                    'created_at' => $now->toDateTimeString(),
                ];
            }

            if ($update !== []) {
                DB::table('telegram_scraped_users')->upsert(
                    $update,
                    ['telegram_user_id'],
                    ['last_seen_at', 'seen_status', 'last_posted_at', 'our_group_at', 'activity_checked_at', 'invite_ok', 'updated_at'],
                );
            }

            if ($fresh !== []) {
                DB::table('telegram_scraped_users')->insertOrIgnore($fresh);
            }
        }

        return $done;
    }

    /**
     * The authors of these messages and when each wrote last, from
     * $since (unix time) on.
     *
     * @param list<array<string, mixed>> $messages messages.getHistory()['messages']
     *
     * @return array<int, int> telegram id => unix time
     */
    public static function authors(array $messages, int $since): array
    {
        $posted = [];

        foreach ($messages as $message) {
            if (! is_array($message) || ($message['_'] ?? null) !== 'message') {
                continue;
            }

            $date = (int) ($message['date'] ?? 0);
            $from = self::userId($message['from_id'] ?? null);

            if ($from !== null && $date >= $since) {
                $posted[$from] = max($posted[$from] ?? 0, $date);
            }
        }

        return $posted;
    }

    /**
     * One page of a group's messages, read anyway by somebody else (Join
     * group's check): when its authors wrote and everybody's status is
     * kept; with $insert the authors new to the base are written too.
     *
     * @param array<string, mixed> $history messages.getHistory()
     *
     * @return array{seen: int, green: int, new: int}
     */
    public function fromHistory(array $history, bool $insert = false): array
    {
        $config = $this->settings->current();
        $posted = self::authors((array) ($history['messages'] ?? []), now()->subDays((int) $config['posted_days'])->timestamp);

        return $this->observe((array) ($history['users'] ?? []), $posted, insert: $insert, authorsOnly: true);
    }

    /**
     * observe() for the other processes (the contact sync, Join group):
     * a fault here is logged, it never stops their own work.
     *
     * @param array<int|string, mixed> $users
     */
    public static function quietly(array $users, ?array $history = null, bool $insert = false, bool $ourGroup = false): void
    {
        try {
            $activity = app(self::class);

            $history !== null ? $activity->fromHistory($history, $insert) : $activity->observe($users, ourGroup: $ourGroup, insert: $insert);
        } catch (Throwable $e) {
            Log::warning('People activity: not kept', ['message' => $e->getMessage()]);
        }
    }

    /**
     * The colours by who left our groups: whoever left turns red, whoever
     * is back in (or was red by the old "online lately" rule) turns green
     * again when something is known of them, unchecked when nothing is.
     * Run at every invite run start, after the "who left" lists of the
     * last run. No Telegram.
     *
     * @return array{green: int, red: int} the people whose colour changed
     */
    public function recompute(): array
    {
        $red = TelegramScrapedUser::query()
            ->whereIn('id', self::leftQuery())
            ->where(fn ($q) => $q->whereNull('invite_ok')->orWhere('invite_ok', true))
            ->update(['invite_ok' => false]);

        $notLeft = fn ($q) => $q->where('invite_ok', false)->whereNotIn('id', self::leftQuery());

        $green = TelegramScrapedUser::query()
            ->where($notLeft)
            ->where(fn ($q) => $q
                ->whereNotNull('seen_status')
                ->orWhereNotNull('last_seen_at')
                ->orWhereNotNull('last_posted_at')
                ->orWhereNotNull('our_group_at'))
            ->update(['invite_ok' => true]);

        /* Red by the old rule and nothing known: not checked, in fact. */
        TelegramScrapedUser::query()->where($notLeft)->update(['invite_ok' => null]);

        if ($green + $red > 0) {
            GroupInviteBase::forget();
        }

        return ['green' => $green, 'red' => $red];
    }

    /**
     * Whether this account's people are due a look: not looked at in the
     * panel's `activity_hours`.
     */
    public function dueFor(TelegramAccount $account): bool
    {
        $at = ((array) TelegramSetting::get(self::ACCOUNTS_KEY, []))[$account->id] ?? null;

        return ! is_numeric($at)
            || (int) $at <= now()->subHours((int) $this->settings->current()['activity_hours'])->timestamp;
    }

    /**
     * When each account last looked, by account id.
     *
     * @return array<int, Carbon>
     */
    public static function lastLooks(): array
    {
        return collect((array) TelegramSetting::get(self::ACCOUNTS_KEY, []))
            ->filter(fn ($at) => is_numeric($at))
            ->mapWithKeys(fn ($at, $id) => [(int) $id => Carbon::createFromTimestamp((int) $at)->setTimezone(config('app.timezone'))])
            ->all();
    }

    /**
     * One account looks at its people: the status of every contact of its
     * (one request), then who wrote in its groups in the last days - the
     * groups it joined first, then the public ones of the base, up to the
     * panel's `activity_groups`, each read by one account in GROUP_FRESH_HOURS.
     * A flood wait ends the groups part, not the run; a dead session is
     * thrown for the run to tell.
     *
     * @param callable(string): void $say
     * @param callable(): bool       $stopping
     *
     * @return array{contacts: int, groups: int, authors: int, green: int, new: int, error: string|null}
     */
    public function checkAccount(API $api, TelegramAccount $account, callable $say, callable $stopping): array
    {
        $config = $this->settings->current();
        $done = ['contacts' => 0, 'groups' => 0, 'authors' => 0, 'green' => 0, 'new' => 0, 'error' => null];

        try {
            $this->look($api, $account, $config, $say, $stopping, $done);
        } catch (Throwable $e) {
            /* A dead session is the run's to tell; anything else only ends the look. */
            if (GroupInviteService::classify($e, GroupInviteService::STEP_CHECK) === GroupInviteService::KIND_DEAD) {
                throw $e;
            }

            $done['error'] = self::code($e);
            $say("  activity: stopped by {$done['error']}");
        } finally {
            /* Looked at, or tried to: not again before `activity_hours` - unless stopped half way. */
            if (! $stopping()) {
                $looks = (array) TelegramSetting::get(self::ACCOUNTS_KEY, []);
                $looks[$account->id] = now()->timestamp;
                TelegramSetting::set(self::ACCOUNTS_KEY, $looks);
            }
        }

        return $done;
    }

    /**
     * @param array<string, mixed>                                                            $config
     * @param array{contacts: int, groups: int, authors: int, green: int, new: int, error: string|null} $done
     */
    private function look(API $api, TelegramAccount $account, array $config, callable $say, callable $stopping, array &$done): void
    {
        $contacts = (array) $api->contacts->getContacts(hash: 0);
        $seen = $this->observe((array) ($contacts['users'] ?? []));
        $done['contacts'] = $seen['seen'];
        $done['green'] += $seen['green'];

        $say("  activity: {$seen['seen']} contacts looked at, {$seen['green']} of them active");

        $since = now()->subDays((int) $config['posted_days'])->timestamp;

        foreach ($this->groupsFor($account, (int) $config['activity_groups']) as $group) {
            if ($stopping()) {
                break;
            }

            /* Taken: another account in this run will not read it too. */
            Cache::put(self::groupKey($group->id), now()->timestamp, now()->addHours(self::GROUP_FRESH_HOURS));

            try {
                $read = $this->readGroup($api, $group, $since);
            } catch (Throwable $e) {
                $kind = GroupInviteService::classify($e, GroupInviteService::STEP_CHECK);

                if ($kind === GroupInviteService::KIND_DEAD) {
                    throw $e;
                }

                $say("  activity: {$group->link} " . self::code($e));

                /* Not read: another account may. */
                Cache::forget(self::groupKey($group->id));

                /* A flood wait: the other groups are left for the next accounts. */
                if ($kind === GroupInviteService::KIND_WAIT) {
                    break;
                }

                continue;
            }

            $kept = $this->observe($read['users'], $read['posted'], insert: true, authorsOnly: true);

            $done['groups']++;
            $done['authors'] += count($read['posted']);
            $done['green'] += $kept['green'];
            $done['new'] += $kept['new'];

            $say("  activity: {$group->link}: " . count($read['posted']) . " wrote, {$kept['new']} new to the base");

            self::pause();
        }
    }

    /**
     * Who wrote in the group from $since on, newest page first, and every
     * user the pages name.
     *
     * @return array{posted: array<int, int>, users: array<int, array<string, mixed>>}
     */
    private function readGroup(API $api, TelegramScrapeGroup $group, int $since): array
    {
        $peer = ! $group->isInvite() ? '@' . $group->link : $group->telegram_chat_id;
        $posted = [];
        $users = [];
        $offsetId = 0;

        for ($page = 0; $page < self::HISTORY_PAGES; $page++) {
            $history = (array) $api->messages->getHistory(peer: $peer, offset_id: $offsetId, limit: self::HISTORY_PAGE, floodWaitLimit: 0);
            $messages = (array) ($history['messages'] ?? []);

            foreach ((array) ($history['users'] ?? []) as $user) {
                if (is_array($user) && isset($user['id'])) {
                    $users[(int) $user['id']] = $user;
                }
            }

            foreach (self::authors($messages, $since) as $id => $at) {
                $posted[$id] = max($posted[$id] ?? 0, $at);
            }

            $dates = array_filter(array_map(fn ($m) => is_array($m) ? (int) ($m['date'] ?? 0) : 0, $messages));
            $ids = array_filter(array_map(fn ($m) => is_array($m) ? (int) ($m['id'] ?? 0) : 0, $messages));

            if (count($messages) < self::HISTORY_PAGE || $dates === [] || $ids === [] || min($dates) < $since) {
                break;
            }

            $offsetId = min($ids);

            self::pause();
        }

        return ['posted' => $posted, 'users' => $users];
    }

    /**
     * The groups this account reads now: those it joined, then the public
     * ones of the base worth reading (the contact sync's, joined or
     * approved by Join group) - none read lately by anybody.
     *
     * @return list<TelegramScrapeGroup>
     */
    private function groupsFor(TelegramAccount $account, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $alive = fn ($q) => $q->where(fn ($q) => $q
            ->whereNull('join_status')
            ->orWhere('join_status', '!=', TelegramScrapeGroup::JOIN_DEAD));

        $mine = TelegramScrapeGroup::query()
            ->where($alive)
            ->whereIn('id', TelegramJoinMembership::query()
                ->where('telegram_account_id', $account->id)
                ->where('status', TelegramJoinMembership::STATUS_JOINED)
                ->select('group_id'))
            ->where(fn ($q) => $q->whereNotNull('telegram_chat_id')->orWhere(fn ($q) => $q->publicLink()))
            ->orderByDesc('last_message_at')
            ->get();

        $public = TelegramScrapeGroup::query()
            ->where($alive)
            ->where(fn ($q) => $q
                ->where('type', TelegramScrapeGroup::TYPE_CONTACTS)
                ->orWhereIn('join_status', [TelegramScrapeGroup::JOIN_JOINED, TelegramScrapeGroup::JOIN_APPROVED]))
            ->where(fn ($q) => $q->publicLink())
            ->orderByDesc('last_message_at')
            ->limit($limit * 20)
            ->get();

        $picked = [];

        foreach ($mine->concat($public) as $group) {
            if (count($picked) >= $limit) {
                break;
            }

            if (isset($picked[$group->id]) || Cache::has(self::groupKey($group->id))) {
                continue;
            }

            $picked[$group->id] = $group;
        }

        return array_values($picked);
    }

    private static function groupKey(int $id): string
    {
        return 'group_invite.activity_group.' . $id;
    }

    /**
     * from_id: a bot-API id from MadelineProto (a person's is positive), a
     * Peer array from a raw call, nothing for a post as the group itself.
     */
    private static function userId(mixed $from): ?int
    {
        if (is_array($from)) {
            $from = ($from['_'] ?? null) === 'peerUser' ? ($from['user_id'] ?? null) : null;
        }

        return is_numeric($from) && (int) $from > 0 ? (int) $from : null;
    }

    private static function time(mixed $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value);
    }

    private static function later(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->gt($b) ? $a : $b;
    }

    private static function cut(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }

    private static function code(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RPCErrorException) {
                return $current->rpc;
            }
        }

        return mb_substr($e->getMessage(), 0, 120);
    }

    /**
     * A second or so between requests; none in the tests.
     */
    private static function pause(): void
    {
        if (! app()->runningUnitTests()) {
            usleep(random_int(700, 1500) * 1000);
        }
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
}
