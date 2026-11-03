<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramJoinRequest;
use App\Models\Telegram\TelegramScrapedUser;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Our own bot approves every request to join a group it administers.
 *
 * Whoever asked goes into the base of people (telegram_scraped_users) - new
 * or with their name brought up to date. In one of our groups they also get
 * a line in its history: "joined" - asked by themselves, nobody invited
 * them, so the invite does not try them for that group. A line the invite
 * already wrote for them stays as it was (it is what the account's call
 * did); only someone who had left is in the group again.
 *
 * Our own accounts ask too. An account that is not in the group yet asks
 * to join by the bot's own link - one that takes requests - and the invite
 * run lets it in through the bot at once (requestLink(), letIn()); a group
 * that takes members by request answers its own link the same way. They
 * are not people of the base (GroupInviteService::ensureJoined()).
 *
 * Every action of the bot is reported to the owner (JoinBotReporter).
 *
 * The messages of the people on the spam list are deleted a few seconds
 * after they were sent (SpamCleaner): the bot, an admin of the group, gets
 * every message there, and deletes the due ones between its asks.
 *
 * The bot is asked by getUpdates (long polling), a minute at a time, from
 * the scheduler: no webhook, nothing public to set up.
 */
final class JoinRequestService
{
    /** One getUpdates waits this long for something to come, seconds. */
    private const LONG_POLL = 25;

    /**
     * chat_member: who came into a group where the bot is an admin - a bot
     * among them is muted; message: what is written there - the spam list's
     * messages are deleted.
     */
    private const UPDATES = ['chat_join_request', 'my_chat_member', 'chat_member', 'message'];

    /** What a muted bot may not do in the group: anything. */
    private const RIGHTS = [
        'can_send_messages',
        'can_send_audios',
        'can_send_documents',
        'can_send_photos',
        'can_send_videos',
        'can_send_video_notes',
        'can_send_voice_notes',
        'can_send_polls',
        'can_send_other_messages',
        'can_add_web_page_previews',
        'can_change_info',
        'can_invite_users',
        'can_pin_messages',
        'can_manage_topics',
    ];

    /** A 429 shorter than this is waited out on the spot, seconds. */
    private const WAIT_IN_PLACE = 30;

    /**
     * Our account's request is gone or the account is in: the other one
     * of the two (the poll, the invite run) let it in first.
     */
    private const LET_IN_ALREADY = ['USER_ALREADY_PARTICIPANT', 'HIDE_REQUESTER_MISSING'];

    /** The name of the bot's link in the group's list of links (32 characters at most). */
    private const LINK_NAME = 'Postix: our accounts';

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly JoinBotReporter $reporter,
        private readonly SpamCleaner $spam,
    ) {}

    /**
     * The token typed in the panel: a bot of its own (not one this project
     * already has), with no webhook another project relies on. Saved with
     * what getMe says about the bot.
     *
     * @return array{id: int, username: string, name: string}
     *
     * @throws DomainException taken | webhook
     * @throws BotApiError     the token is wrong, Telegram is out of reach
     */
    public function connect(string $token): array
    {
        if (in_array($token, JoinBotSettings::takenTokens(), true)) {
            throw new DomainException('taken');
        }

        $api = new BotApi($token);
        $me = (array) $api->call('getMe');
        $hook = (array) $api->call('getWebhookInfo');

        if (($hook['url'] ?? '') !== '') {
            throw new DomainException('webhook');
        }

        $bot = [
            'id' => (int) ($me['id'] ?? 0),
            'username' => (string) ($me['username'] ?? ''),
            'name' => trim((string) ($me['first_name'] ?? '')),
        ];

        $this->settings->saveToken($token, $bot);

        return $bot;
    }

    /**
     * Asks Telegram for new requests for $seconds and approves each one as
     * it comes. An update is marked read (offset) only once it is handled:
     * a request that met a network error comes again on the next ask.
     *
     * @param callable(string): void $say
     *
     * @return array{approved: int, failed: int, ours: int, new: int}
     */
    public function poll(int $seconds, callable $say): array
    {
        $totals = ['approved' => 0, 'failed' => 0, 'ours' => 0, 'new' => 0];
        $token = $this->settings->token();

        if ($token === null) {
            return $totals;
        }

        $api = new BotApi($token);
        $until = time() + $seconds;

        while (($wait = min(self::LONG_POLL, $until - time() - 1)) >= 1) {
            /* Switched off or another bot put in from the panel: this one stops. */
            if (! $this->settings->enabled() || $this->settings->token() !== $token) {
                break;
            }

            /* The spam list's messages whose time has come go first; the ask waits no longer than the next one. */
            $this->spam->sweep($api, $say);
            $wait = max(1, min($wait, $this->spam->dueIn()));

            try {
                $updates = $api->call('getUpdates', [
                    'offset' => $this->settings->state()['offset'],
                    'timeout' => $wait,
                    'allowed_updates' => self::UPDATES,
                ], $wait + 15);
            } catch (BotApiError $e) {
                $this->trouble($e, $say);

                /* 401: the token was revoked; 409: a webhook or another poll has the bot. Asking again will not help. */
                if (in_array($e->getCode(), [401, 409], true)) {
                    break;
                }

                sleep(max(3, min(self::WAIT_IN_PLACE, $e->retryAfter)));

                continue;
            }

            $this->settings->setState(['polled_at' => now()->toIso8601String(), 'error' => null, 'error_at' => null]);

            foreach ((array) $updates as $update) {
                if (! is_array($update) || ! isset($update['update_id'])) {
                    continue;
                }

                try {
                    $this->handle($api, $update, $totals, $say);
                } catch (BotApiError $e) {
                    /* Not marked read: the next ask brings it again. */
                    $this->trouble($e, $say);
                    sleep(max(3, min(self::WAIT_IN_PLACE, $e->retryAfter)));

                    continue 2;
                }

                $this->settings->setState(['offset' => (int) $update['update_id'] + 1]);
            }
        }

        if ($this->settings->enabled() && $this->settings->token() === $token) {
            $this->spam->sweep($api, $say);
        }

        return $totals;
    }

    /**
     * Where the bot stands in each of our groups (not archived): an admin
     * that may let people in, an admin without that right, a plain member,
     * not there. A group joined only by an invite link is known by its chat
     * id once an account has joined it.
     *
     * can_delete: whether it may delete the spam list's messages there
     * (null: not known).
     *
     * @return Collection<int, array{id: int, title: string, link: string, is_active: bool, state: string, error: string|null, can_delete: bool|null}>
     */
    public function groups(): Collection
    {
        $current = $this->settings->current();
        $api = $current['token'] !== null ? new BotApi($current['token']) : null;
        $botId = (int) ($current['bot']['id'] ?? 0);

        return TelegramInviteGroup::query()
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->map(function (TelegramInviteGroup $group) use ($api, $botId): array {
                $chat = $group->telegram_chat_id ?: ($group->isInvite() ? null : '@' . ltrim($group->link, '@'));
                [$state, $error, $canDelete] = $api === null || $botId === 0
                    ? ['no_bot', null, null]
                    : ($chat === null ? ['unknown', null, null] : self::standing($api, $chat, $botId));

                return [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->link,
                    'link' => $group->link,
                    'is_active' => $group->is_active,
                    'state' => $state,
                    'error' => $error,
                    'can_delete' => $canDelete,
                ];
            })
            ->values();
    }

    /**
     * @return array{0: string, 1: string|null, 2: bool|null} ok | no_right | no_restrict | no_delete | not_admin | absent | error, Telegram's words, whether it may delete messages
     */
    private static function standing(BotApi $api, int|string $chat, int $botId): array
    {
        try {
            $member = (array) $api->call('getChatMember', ['chat_id' => $chat, 'user_id' => $botId], 8);
        } catch (BotApiError $e) {
            /* "chat not found": the bot was never added there. */
            return in_array($e->getCode(), [400, 403], true) ? ['absent', $e->reason(), false] : ['error', $e->reason(), null];
        }

        $canDelete = ($member['status'] ?? null) === 'creator'
            || (($member['status'] ?? null) === 'administrator' && ! empty($member['can_delete_messages']));

        $state = match ($member['status'] ?? null) {
            'creator' => 'ok',
            'administrator' => match (true) {
                empty($member['can_invite_users']) => 'no_right',
                empty($member['can_restrict_members']) => 'no_restrict',
                ! $canDelete => 'no_delete',
                default => 'ok',
            },
            'member', 'restricted' => 'not_admin',
            default => 'absent',
        };

        return [$state, null, $canDelete];
    }

    /**
     * A bot in one of our groups may not write there: whoever added it, the
     * moment it is in. The group's own admins' bots are left alone, and so
     * is our bot. The bot is marked in the base: the invite never adds it.
     *
     * @param string $who how the report names it
     *
     * @return string|null null: it cannot write now; else why not
     */
    public function muteBot(TelegramInviteGroup $group, int $userId, string $who): ?string
    {
        $current = $this->settings->current();

        if ($userId === (int) ($current['bot']['id'] ?? 0)) {
            return null;
        }

        TelegramScrapedUser::query()->where('telegram_user_id', $userId)->update(['is_bot' => true]);
        GroupInviteBase::forget();

        if (! $current['enabled'] || $current['token'] === null || ! $group->telegram_chat_id) {
            return 'NO_BOT';
        }

        try {
            (new BotApi($current['token']))->call('restrictChatMember', [
                'chat_id' => (int) $group->telegram_chat_id,
                'user_id' => $userId,
                'permissions' => array_fill_keys(self::RIGHTS, false),
            ]);
        } catch (BotApiError $e) {
            $this->reporter->send('bot_mute_failed', ['group' => $group->title ?: $group->link, 'who' => $who, 'reason' => $e->reason()]);

            return $e->reason();
        }

        $this->reporter->send('bot_muted', ['group' => $group->title ?: $group->link, 'who' => $who]);

        return null;
    }

    /**
     * Someone came into a chat where our bot is an admin. A bot that came
     * into one of our groups as a plain member is muted at once.
     *
     * @param array<string, mixed>   $change
     * @param callable(string): void $say
     */
    private function newMember(array $change, callable $say): void
    {
        $member = (array) ($change['new_chat_member'] ?? []);
        $user = (array) ($member['user'] ?? []);

        if (empty($user['is_bot']) || ($member['status'] ?? null) !== 'member' || empty($user['id'])) {
            return;
        }

        $group = $this->ourGroup((array) ($change['chat'] ?? []));

        if ($group === null) {
            return;
        }

        $who = self::who($user);
        $error = $this->muteBot($group, (int) $user['id'], $who);

        $say(($error === null ? 'muted' : "could not mute ({$error})") . ": bot {$who} -> " . ($group->title ?: $group->link));
    }

    /**
     * @param array<string, mixed>                                    $update
     * @param array{approved: int, failed: int, ours: int, new: int} $totals
     * @param callable(string): void                                  $say
     */
    private function handle(BotApi $api, array $update, array &$totals, callable $say): void
    {
        if (is_array($update['message'] ?? null)) {
            $this->spam->take($update['message'], $say);

            return;
        }

        if (is_array($update['my_chat_member'] ?? null)) {
            $this->rememberChat($update['my_chat_member']);

            return;
        }

        if (is_array($update['chat_member'] ?? null)) {
            $this->newMember($update['chat_member'], $say);

            return;
        }

        $request = $update['chat_join_request'] ?? null;

        if (! is_array($request)) {
            return;
        }

        $chat = (array) ($request['chat'] ?? []);
        $from = (array) ($request['from'] ?? []);
        $chatId = (int) ($chat['id'] ?? 0);
        $userId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            return;
        }

        $group = $this->ourGroup($chat);
        $account = TelegramAccount::query()->where('telegram_user_id', $userId)->first();
        $error = $this->approve($api, $chatId, $userId);

        /* The invite run let its account in itself, and wrote and reported it. */
        if ($account !== null && in_array($error, self::LET_IN_ALREADY, true)) {
            $say("already let in by the invite run: our account #{$account->id} -> " . ($chat['title'] ?? $chatId));

            return;
        }

        if ($error === 'USER_ALREADY_PARTICIPANT') {
            $error = null;
        }

        $askedAt = isset($request['date']) ? Carbon::createFromTimestamp((int) $request['date']) : now();

        $person = null;
        $isNew = false;

        /* Asked, approved or not: a person of the base. Our own account is not one. */
        if ($account === null && empty($from['is_bot'])) {
            [$person, $isNew] = $this->remember($from);

            if ($group !== null && $error === null) {
                $this->joined($group, $person, $askedAt);
            }

            GroupInviteBase::forget();
        }

        TelegramJoinRequest::query()->create([
            'chat_id' => $chatId,
            'chat_title' => self::cut($chat['title'] ?? null),
            'invite_group_id' => $group?->id,
            'telegram_user_id' => $userId,
            'username' => self::cut($from['username'] ?? null),
            'first_name' => self::cut($from['first_name'] ?? null),
            'last_name' => self::cut($from['last_name'] ?? null),
            'scraped_user_id' => $person?->id,
            'telegram_account_id' => $account?->id,
            'is_new' => $isNew,
            'status' => $error === null ? TelegramJoinRequest::STATUS_APPROVED : TelegramJoinRequest::STATUS_FAILED,
            'error' => $error,
            'requested_at' => $askedAt,
        ]);

        $error === null ? $totals['approved']++ : $totals['failed']++;
        $totals['ours'] += $account !== null ? 1 : 0;
        $totals['new'] += $isNew ? 1 : 0;

        $who = $account !== null ? "our account #{$account->id}" : "user {$userId}" . ($isNew ? ' (new in the base)' : '');
        $say(($error === null ? 'approved' : "not approved ({$error})") . ": {$who} -> " . ($chat['title'] ?? $chatId));

        $where = ['group' => $group?->title ?: ($chat['title'] ?? $group?->link ?? (string) $chatId)];

        if ($account !== null) {
            $this->reporter->send($error === null ? 'approved_ours' : 'failed_ours', [...$where, 'phone' => $account->phone, 'reason' => $error]);
        } else {
            $this->reporter->send(
                $error !== null ? 'failed_person' : ($isNew ? 'approved_new' : 'approved_known'),
                [...$where, 'who' => self::who($from), 'reason' => $error],
            );
        }
    }

    /**
     * The bot's link to the group that takes requests: our accounts ask to
     * join by it, and the bot lets them in. Made once and kept; $fresh: the
     * kept one stopped working, a new one is made. A public group known
     * only by its @name is looked up for its id first.
     *
     * @return string|null the link's hash; null when the bot is not on
     *
     * @throws BotApiError the bot may not make links there (not an admin, no right to let people in), Telegram is out of reach
     */
    public function requestLink(TelegramInviteGroup $group, bool $fresh = false): ?string
    {
        $current = $this->settings->current();

        if (! $current['enabled'] || $current['token'] === null) {
            return null;
        }

        $api = new BotApi($current['token']);
        $chat = $this->chatOf($api, $group);

        if ($chat === null) {
            return null;
        }

        $kept = $this->settings->links()[(string) $chat] ?? null;

        if ($kept !== null && ! $fresh) {
            return self::hashOf($kept);
        }

        $made = (array) $api->call('createChatInviteLink', [
            'chat_id' => $chat,
            'name' => self::LINK_NAME,
            'creates_join_request' => true,
        ]);

        $link = (string) ($made['invite_link'] ?? '');
        $hash = self::hashOf($link);

        if ($hash === null) {
            return null;
        }

        $this->settings->saveLink((string) $chat, $link);

        $this->reporter->send('link_made', ['group' => $group->title ?: $group->link, 'link' => $link]);

        return $hash;
    }

    /**
     * The invite run's account asked to join by the bot's link: the bot
     * lets it in now, not waiting for the poll. Written and reported here;
     * when the poll was quicker, it wrote and reported it itself.
     *
     * @return string|null null: the account is in; else why not
     */
    public function letIn(TelegramInviteGroup $group, TelegramAccount $account): ?string
    {
        $token = $this->settings->token();

        if ($token === null || ! $group->telegram_chat_id || ! $account->telegram_user_id) {
            return 'NO_BOT';
        }

        try {
            $error = $this->approve(new BotApi($token), (int) $group->telegram_chat_id, (int) $account->telegram_user_id);
        } catch (BotApiError $e) {
            return $e->reason();
        }

        if (in_array($error, self::LET_IN_ALREADY, true)) {
            return null;
        }

        if ($error !== null) {
            return $error;
        }

        TelegramJoinRequest::query()->create([
            'chat_id' => (int) $group->telegram_chat_id,
            'chat_title' => self::cut($group->title),
            'invite_group_id' => $group->id,
            'telegram_user_id' => (int) $account->telegram_user_id,
            'username' => self::cut($account->username ?? null),
            'first_name' => self::cut($account->first_name ?? null),
            'last_name' => self::cut($account->last_name ?? null),
            'telegram_account_id' => $account->id,
            'status' => TelegramJoinRequest::STATUS_APPROVED,
            'requested_at' => now(),
        ]);

        $this->reporter->send('approved_ours', ['group' => $group->title ?: $group->link, 'phone' => $account->phone]);

        return null;
    }

    /**
     * The group as the Bot API knows it: its id; a public one known only
     * by its @name is looked up, and its id kept.
     *
     * @throws BotApiError
     */
    private function chatOf(BotApi $api, TelegramInviteGroup $group): ?int
    {
        if ($group->telegram_chat_id) {
            return (int) $group->telegram_chat_id;
        }

        if ($group->isInvite()) {
            return null;
        }

        $chat = (array) $api->call('getChat', ['chat_id' => '@' . ltrim($group->link, '@')], 10);
        $id = (int) ($chat['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $group->update(['telegram_chat_id' => $id]);

        return $id;
    }

    /**
     * https://t.me/+AbC_d-1 -> AbC_d-1 (and the older t.me/joinchat/AbC).
     */
    private static function hashOf(string $link): ?string
    {
        return preg_match('~(?:/\+|/joinchat/)([A-Za-z0-9_-]+)~', $link, $m) === 1 ? $m[1] : null;
    }

    /**
     * Ali Valiyev (@ali, ID 501) - the person as a report names them.
     *
     * @param array<string, mixed> $from
     */
    public static function who(array $from): string
    {
        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $tags = array_filter([
            ! empty($from['username']) ? '@' . $from['username'] : null,
            'ID ' . (int) ($from['id'] ?? 0),
        ]);

        return $name !== '' ? $name . ' (' . implode(', ', $tags) . ')' : implode(', ', $tags);
    }

    /**
     * @return string|null null: in the group now; else why not (USER_ALREADY_PARTICIPANT as well)
     *
     * @throws BotApiError a network or Telegram trouble: the request is asked about again later
     */
    private function approve(BotApi $api, int $chatId, int $userId): ?string
    {
        for ($try = 1; ; $try++) {
            try {
                $api->call('approveChatJoinRequest', ['chat_id' => $chatId, 'user_id' => $userId]);

                return null;
            } catch (BotApiError $e) {
                if ($e->transient) {
                    if ($try < 3 && $e->retryAfter > 0 && $e->retryAfter <= self::WAIT_IN_PLACE) {
                        sleep($e->retryAfter + 1);

                        continue;
                    }

                    throw $e;
                }

                return $e->reason();
            }
        }
    }

    /**
     * The person into the base, or their name brought up to date: what the
     * Bot API says is how they are now.
     *
     * @param array<string, mixed> $from
     *
     * @return array{0: TelegramScrapedUser, 1: bool} the person, whether they are new in the base
     */
    private function remember(array $from): array
    {
        $person = TelegramScrapedUser::query()->firstOrNew(['telegram_user_id' => (int) $from['id']]);
        $isNew = ! $person->exists;

        $person->username = self::cut($from['username'] ?? null);
        $person->first_name = self::cut($from['first_name'] ?? null) ?? $person->first_name;
        $person->last_name = self::cut($from['last_name'] ?? null);
        $person->is_bot = false;
        $person->save();

        return [$person, $isNew];
    }

    /**
     * The person's line in the group's history. Nobody tried them: a new
     * line "joined". One the invite wrote stays - it is what the account's
     * call did - except "left": they are in the group again, as "Who left"
     * would find.
     */
    private function joined(TelegramInviteGroup $group, TelegramScrapedUser $person, Carbon $askedAt): void
    {
        $row = TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->where('scraped_user_id', $person->id)
            ->first();

        if ($row === null) {
            TelegramGroupInvite::query()->create([
                'invite_group_id' => $group->id,
                'scraped_user_id' => $person->id,
                'status' => TelegramGroupInvite::STATUS_JOINED,
                'via' => TelegramGroupInvite::VIA_REQUEST,
                'attempted_at' => $askedAt,
                'checked_at' => now(),
            ]);

            return;
        }

        if ($row->status === TelegramGroupInvite::STATUS_LEFT) {
            $row->update(['status' => TelegramGroupInvite::STATUS_INVITED, 'left_at' => null, 'checked_at' => now()]);
        }
    }

    /**
     * The chat is one of our groups (an archived one too: its history is
     * kept): by its id, or by its public @name - then its id is kept for
     * next time.
     *
     * @param array<string, mixed> $chat
     */
    private function ourGroup(array $chat): ?TelegramInviteGroup
    {
        $id = (int) $chat['id'];

        $group = TelegramInviteGroup::withTrashed()
            ->where('telegram_chat_id', $id)
            ->orderBy('deleted_at')
            ->orderByDesc('id')
            ->first();

        $username = trim((string) ($chat['username'] ?? ''));

        if ($group === null && $username !== '') {
            $group = TelegramInviteGroup::withTrashed()
                ->whereRaw('LOWER(link) = ?', [mb_strtolower($username)])
                ->first();

            if ($group !== null && ! $group->telegram_chat_id) {
                $group->update(['telegram_chat_id' => $id]);
            }
        }

        return $group;
    }

    /**
     * The bot was made an admin somewhere, lost the right, was removed: the
     * page lists where it takes requests.
     *
     * @param array<string, mixed> $change
     */
    private function rememberChat(array $change): void
    {
        $chat = (array) ($change['chat'] ?? []);
        $member = (array) ($change['new_chat_member'] ?? []);

        if (! isset($chat['id']) || ($chat['type'] ?? null) === 'private') {
            return;
        }

        $chats = $this->settings->state()['chats'];
        $status = (string) ($member['status'] ?? '');
        $before = $chats[(string) $chat['id']] ?? null;
        $title = self::cut($chat['title'] ?? null) ?? (string) $chat['id'];

        if (in_array($status, ['left', 'kicked'], true)) {
            unset($chats[(string) $chat['id']]);
            $report = 'removed';
        } else {
            $now = [
                'title' => self::cut($chat['title'] ?? null),
                'username' => $chat['username'] ?? null,
                'admin' => in_array($status, ['administrator', 'creator'], true),
                'can_invite' => $status === 'creator' || ! empty($member['can_invite_users']),
                'can_restrict' => $status === 'creator' || ! empty($member['can_restrict_members']),
                'can_delete' => $status === 'creator' || ! empty($member['can_delete_messages']),
                'at' => now()->toIso8601String(),
            ];
            $chats[(string) $chat['id']] = $now;

            /* Another right changed: nothing new about letting people in, muting bots or deleting spam. */
            $same = is_array($before)
                && ($before['admin'] ?? null) === $now['admin']
                && ($before['can_invite'] ?? null) === $now['can_invite']
                && ($before['can_restrict'] ?? null) === $now['can_restrict']
                && ($before['can_delete'] ?? null) === $now['can_delete'];

            $report = match (true) {
                $same => null,
                ! $now['admin'] => 'member',
                ! $now['can_invite'] => 'admin_no_right',
                ! $now['can_restrict'] => 'admin_no_restrict',
                ! $now['can_delete'] => 'admin_no_delete',
                default => 'admin',
            };
        }

        $this->settings->setState(['chats' => $chats]);

        if ($report !== null) {
            $this->reporter->send("chat_{$report}", ['group' => $title]);
        }
    }

    /**
     * A trouble that asking again will not mend (the token revoked, another
     * program took the bot) is reported once, not every minute.
     *
     * @param callable(string): void $say
     */
    private function trouble(Throwable $e, callable $say): void
    {
        $was = $this->settings->state()['error'];
        $error = mb_substr($e->getMessage(), 0, 300);

        $this->settings->setState(['error' => $error, 'error_at' => now()->toIso8601String()]);

        $say('Bot API: ' . $e->getMessage());

        if ($e instanceof BotApiError && ! $e->transient && $was !== $error) {
            $this->reporter->send('bot_error', ['reason' => $e->reason()]);
        }
    }

    private static function cut(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
