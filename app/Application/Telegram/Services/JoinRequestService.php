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
 * Our own accounts ask too: a group that takes new members by request
 * answers an account's join with INVITE_REQUEST_SENT. The bot approves them
 * as well, but they are not people of the base; the invite run waits for
 * that approval and goes on adding (GroupInviteService::ensureJoined()).
 *
 * The bot is asked by getUpdates (long polling), a minute at a time, from
 * the scheduler: no webhook, nothing public to set up.
 */
final class JoinRequestService
{
    /** One getUpdates waits this long for something to come, seconds. */
    private const LONG_POLL = 25;

    private const UPDATES = ['chat_join_request', 'my_chat_member'];

    /** A 429 shorter than this is waited out on the spot, seconds. */
    private const WAIT_IN_PLACE = 30;

    public function __construct(private readonly JoinBotSettings $settings) {}

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

        return $totals;
    }

    /**
     * Where the bot stands in each of our groups (not archived): an admin
     * that may let people in, an admin without that right, a plain member,
     * not there. A group joined only by an invite link is known by its chat
     * id once an account has joined it.
     *
     * @return Collection<int, array{id: int, title: string, link: string, is_active: bool, state: string, error: string|null}>
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
                [$state, $error] = $api === null || $botId === 0 ? ['no_bot', null] : ($chat === null ? ['unknown', null] : self::standing($api, $chat, $botId));

                return [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->link,
                    'link' => $group->link,
                    'is_active' => $group->is_active,
                    'state' => $state,
                    'error' => $error,
                ];
            })
            ->values();
    }

    /**
     * @return array{0: string, 1: string|null} ok | no_right | not_admin | absent | error, and Telegram's words
     */
    private static function standing(BotApi $api, int|string $chat, int $botId): array
    {
        try {
            $member = (array) $api->call('getChatMember', ['chat_id' => $chat, 'user_id' => $botId], 8);
        } catch (BotApiError $e) {
            /* "chat not found": the bot was never added there. */
            return in_array($e->getCode(), [400, 403], true) ? ['absent', $e->reason()] : ['error', $e->reason()];
        }

        return match ($member['status'] ?? null) {
            'creator' => ['ok', null],
            'administrator' => ! empty($member['can_invite_users']) ? ['ok', null] : ['no_right', null],
            'member', 'restricted' => ['not_admin', null],
            default => ['absent', null],
        };
    }

    /**
     * @param array<string, mixed>                                    $update
     * @param array{approved: int, failed: int, ours: int, new: int} $totals
     * @param callable(string): void                                  $say
     */
    private function handle(BotApi $api, array $update, array &$totals, callable $say): void
    {
        if (is_array($update['my_chat_member'] ?? null)) {
            $this->rememberChat($update['my_chat_member']);

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
    }

    /**
     * @return string|null null: in the group now; else why not
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

                $reason = $e->reason();

                return $reason === 'USER_ALREADY_PARTICIPANT' ? null : $reason;
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

        if (in_array($status, ['left', 'kicked'], true)) {
            unset($chats[(string) $chat['id']]);
        } else {
            $chats[(string) $chat['id']] = [
                'title' => self::cut($chat['title'] ?? null),
                'username' => $chat['username'] ?? null,
                'admin' => in_array($status, ['administrator', 'creator'], true),
                'can_invite' => $status === 'creator' || ! empty($member['can_invite_users']),
                'at' => now()->toIso8601String(),
            ];
        }

        $this->settings->setState(['chats' => $chats]);
    }

    /**
     * @param callable(string): void $say
     */
    private function trouble(Throwable $e, callable $say): void
    {
        $this->settings->setState(['error' => mb_substr($e->getMessage(), 0, 300), 'error_at' => now()->toIso8601String()]);

        $say('Bot API: ' . $e->getMessage());
    }

    private static function cut(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
