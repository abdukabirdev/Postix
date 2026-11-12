<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramReadRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The Telegram side of the developer's chat reader: reads chats, messages and
 * people with the session of the process that holds the account, and leaves
 * the answer on the request (see ChatReadQueue for the web side).
 *
 * Read only, on purpose: nothing here sends, edits, joins or marks a chat as
 * read (getHistory does not), and no phone number is ever put in an answer.
 * An invite link is never opened - a chat is an id, "me" or an @username.
 *
 * $telegram is the account's API (the handler itself in a listener).
 */
final class ChatReader
{
    public const OPS = ['dialogs', 'history', 'search', 'chat', 'members', 'user'];

    private const PAGE = 100;

    /** One request does not keep the process busy longer than this, seconds. */
    private const BUDGET_SECONDS = 40;

    private const TEXT_PREVIEW = 160;

    /** Bot API ids of channels and supergroups are -100<id>. */
    private const CHANNEL_OFFSET = 1_000_000_000_000;

    /**
     * Answers the requests waiting for $accountId, a few at a time.
     */
    public function serve(object $telegram, int $accountId): void
    {
        $rows = TelegramReadRequest::query()
            ->where('telegram_account_id', $accountId)
            ->where('status', TelegramReadRequest::STATUS_PENDING)
            ->orderBy('id')
            ->limit(3)
            ->get();

        foreach ($rows as $row) {
            $claimed = TelegramReadRequest::query()
                ->whereKey($row->id)
                ->where('status', TelegramReadRequest::STATUS_PENDING)
                ->update(['status' => TelegramReadRequest::STATUS_RUNNING, 'started_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $this->run($telegram, $row);
        }
    }

    private function run(object $telegram, TelegramReadRequest $row): void
    {
        try {
            $result = $this->perform($telegram, $row->op, (array) $row->params);

            TelegramReadRequest::query()->whereKey($row->id)->update([
                'status' => TelegramReadRequest::STATUS_DONE,
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                'error' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Chat reader: request failed', [
                'request_id' => $row->id,
                'op' => $row->op,
                'account_id' => $row->telegram_account_id,
                'error' => $e->getMessage(),
            ]);

            TelegramReadRequest::query()->whereKey($row->id)->update([
                'status' => TelegramReadRequest::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function perform(object $telegram, string $op, array $params): array
    {
        return match ($op) {
            'dialogs' => $this->dialogs($telegram, $params),
            'history' => $this->history($telegram, $params),
            'search' => $this->search($telegram, $params),
            'chat' => $this->chat($telegram, $params),
            'members' => $this->members($telegram, $params),
            'user' => $this->user($telegram, $params),
            default => throw new InvalidArgumentException("Unknown operation: {$op}"),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    */

    /**
     * The account's chats, the newest first: id, kind, title, unread, the last message.
     *
     * @param  array<string, mixed> $params limit (50, up to 200), kind (private|group|channel|bot), q, unread
     * @return array<string, mixed>
     */
    private function dialogs(object $telegram, array $params): array
    {
        $limit = $this->clamp($params['limit'] ?? 50, 1, 200);
        $kind = (string) ($params['kind'] ?? 'any');
        $needle = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $unread = (bool) ($params['unread'] ?? false);

        $deadline = microtime(true) + self::BUDGET_SECONDS;

        $rows = [];
        $scanned = 0;
        $more = false;

        $offsetDate = 0;
        $offsetId = 0;
        $offsetPeer = ['_' => 'inputPeerEmpty'];

        while (microtime(true) < $deadline && $scanned < 3000) {
            $page = (array) $telegram->messages->getDialogs([
                'offset_date' => $offsetDate,
                'offset_id' => $offsetId,
                'offset_peer' => $offsetPeer,
                'limit' => self::PAGE,
                'hash' => 0,
            ]);

            $dialogs = array_values(array_filter((array) ($page['dialogs'] ?? []), 'is_array'));

            if ($dialogs === []) {
                break;
            }

            $entities = $this->entities($page);

            $messages = [];

            foreach ((array) ($page['messages'] ?? []) as $message) {
                if (is_array($message) && ($key = $this->peerId($message['peer_id'] ?? null)) !== null) {
                    $messages[$key . ':' . ($message['id'] ?? 0)] = $message;
                }
            }

            foreach ($dialogs as $dialog) {
                $scanned++;

                $id = $this->peerId($dialog['peer'] ?? null);

                if ($id === null) {
                    continue;
                }

                $info = $this->describe($id, $entities);
                $last = $messages[$id . ':' . ($dialog['top_message'] ?? 0)] ?? null;

                $unreadCount = (int) ($dialog['unread_count'] ?? 0);

                if (
                    ($kind !== 'any' && $kind !== '' && $info['kind'] !== $kind)
                    || ($unread && $unreadCount === 0)
                    || ($needle !== '' && ! str_contains(mb_strtolower($info['title'] . ' ' . ($info['username'] ?? '')), $needle))
                ) {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'kind' => $info['kind'],
                    'title' => $info['title'],
                    'username' => $info['username'],
                    'members' => $info['members'],
                    'unread' => $unreadCount,
                    'archived' => (int) ($dialog['folder_id'] ?? 0) === 1,
                    'last' => $last === null ? null : [
                        'id' => (int) ($last['id'] ?? 0),
                        'date' => $this->date($last['date'] ?? null),
                        'out' => (bool) ($last['out'] ?? false),
                        'from' => $this->sender($last, $entities, $id),
                        'text' => mb_substr((string) ($last['message'] ?? ''), 0, self::TEXT_PREVIEW),
                    ],
                ];

                if (count($rows) >= $limit) {
                    $more = true;

                    break 2;
                }
            }

            /* The last page of all of them is the one that came back short or whole ("messages.dialogs"). */
            if (($page['_'] ?? '') === 'messages.dialogs' || count($dialogs) < self::PAGE) {
                break;
            }

            $tail = end($dialogs);
            $tailId = $this->peerId($tail['peer'] ?? null);
            $tailMessage = $messages[$tailId . ':' . ($tail['top_message'] ?? 0)] ?? null;

            if ($tailId === null || $tailMessage === null) {
                break;
            }

            $offsetId = (int) $tail['top_message'];
            $offsetDate = (int) ($tailMessage['date'] ?? 0);
            $offsetPeer = $tailId;
        }

        return ['count' => count($rows), 'scanned' => $scanned, 'more' => $more, 'dialogs' => $rows];
    }

    /**
     * A chat's messages, oldest first by default.
     *
     * @param  array<string, mixed> $params chat, limit (100, up to 500), before_id, min_date, max_date (unix), order (asc|desc)
     * @return array<string, mixed>
     */
    private function history(object $telegram, array $params): array
    {
        $peer = $this->peer($params['chat'] ?? null);
        $limit = $this->clamp($params['limit'] ?? 100, 1, 500);
        $minDate = (int) ($params['min_date'] ?? 0);
        $maxDate = (int) ($params['max_date'] ?? 0);
        $offsetId = (int) ($params['before_id'] ?? 0);

        $deadline = microtime(true) + self::BUDGET_SECONDS;

        $messages = [];
        $chat = null;
        $reachedStart = false;
        $timedOut = false;
        $first = true;

        while (count($messages) < $limit) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;

                break;
            }

            $want = min(self::PAGE, $limit - count($messages));

            $page = (array) $telegram->messages->getHistory([
                'peer' => $peer,
                'offset_id' => $offsetId,
                /* Telegram gives what is older than the date; +1 keeps the last second in. */
                'offset_date' => $first && $maxDate > 0 && $offsetId === 0 ? $maxDate + 1 : 0,
                'add_offset' => 0,
                'limit' => $want,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);

            $first = false;

            $raw = array_values(array_filter((array) ($page['messages'] ?? []), 'is_array'));

            if ($raw === []) {
                $reachedStart = true;

                break;
            }

            $entities = $this->entities($page);

            $chatId = $this->peerId($raw[0]['peer_id'] ?? null);

            if ($chat === null && $chatId !== null) {
                $chat = ['id' => $chatId] + $this->describe($chatId, $entities);
            }

            foreach ($raw as $message) {
                $offsetId = (int) ($message['id'] ?? $offsetId);

                if ($minDate > 0 && (int) ($message['date'] ?? 0) < $minDate) {
                    $reachedStart = true;

                    break 2;
                }

                $messages[] = $this->message($message, $entities, $chatId);
            }

            if (count($raw) < $want) {
                $reachedStart = true;

                break;
            }
        }

        $oldest = $messages === [] ? null : (int) end($messages)['id'];

        if (($params['order'] ?? 'asc') !== 'desc') {
            $messages = array_reverse($messages);
        }

        return [
            'chat' => $chat ?? ['id' => is_int($peer) ? $peer : null],
            'count' => count($messages),
            /* Pass as before_id to read what is older; null when the chat's start is reached. */
            'next_before_id' => $reachedStart ? null : $oldest,
            'reached_start' => $reachedStart,
            'timed_out' => $timedOut,
            'messages' => $messages,
        ];
    }

    /**
     * Messages by words, in one chat or in all of them.
     *
     * @param  array<string, mixed> $params q, chat, from, limit (50, up to 100), before_id, min_date, max_date
     * @return array<string, mixed>
     */
    private function search(object $telegram, array $params): array
    {
        $query = trim((string) ($params['q'] ?? ''));
        $limit = $this->clamp($params['limit'] ?? 50, 1, 100);

        $base = [
            'q' => $query,
            'filter' => ['_' => 'inputMessagesFilterEmpty'],
            'min_date' => (int) ($params['min_date'] ?? 0),
            'max_date' => (int) ($params['max_date'] ?? 0),
            'offset_id' => (int) ($params['before_id'] ?? 0),
            'limit' => $limit,
        ];

        if (isset($params['chat']) && $params['chat'] !== '') {
            $page = (array) $telegram->messages->search($base + [
                'peer' => $this->peer($params['chat']),
                'add_offset' => 0,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
                ...(isset($params['from']) && $params['from'] !== '' ? ['from_id' => $this->peer($params['from'])] : []),
            ]);
        } else {
            $page = (array) $telegram->messages->searchGlobal($base + [
                'offset_rate' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
            ]);
        }

        $entities = $this->entities($page);

        $messages = [];

        foreach ((array) ($page['messages'] ?? []) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $chatId = $this->peerId($message['peer_id'] ?? null);

            $messages[] = $this->message($message, $entities, $chatId) + [
                'chat' => $chatId === null ? null : $this->describe($chatId, $entities)['title'],
                'chat_id' => $chatId,
            ];
        }

        return [
            'count' => count($messages),
            'total' => (int) ($page['count'] ?? count($messages)),
            'next_before_id' => $messages === [] ? null : (int) end($messages)['id'],
            'messages' => $messages,
        ];
    }

    /**
     * What a chat is: title, kind, username, about, how many are in it.
     *
     * @param  array<string, mixed> $params chat
     * @return array<string, mixed>
     */
    private function chat(object $telegram, array $params): array
    {
        $peer = $this->peer($params['chat'] ?? null);

        $info = (array) $telegram->getInfo($peer);
        $id = (int) ($info['bot_api_id'] ?? 0);

        $entity = (array) ($info['Chat'] ?? $info['User'] ?? []);

        $entities = [];

        if (isset($info['Chat']['id'])) {
            $entities['chats'][(int) $info['Chat']['id']] = $info['Chat'];
        }

        if (isset($info['User']['id'])) {
            $entities['users'][(int) $info['User']['id']] = $info['User'];
        }

        $described = $this->describe($id, $entities);

        $full = [];

        try {
            $full = (array) $telegram->getFullInfo($peer);
        } catch (Throwable) {
            /* The basics are enough when Telegram will not say more. */
        }

        $details = (array) ($full['full'] ?? []);

        return [
            'id' => $id,
            'type' => $info['type'] ?? null,
            'kind' => $described['kind'],
            'title' => $described['title'],
            'username' => $described['username'],
            'about' => $this->text($details['about'] ?? null),
            'members' => $details['participants_count'] ?? $described['members'],
            'admins' => $details['admins_count'] ?? null,
            'linked_chat_id' => isset($details['linked_chat_id']) ? -self::CHANNEL_OFFSET - (int) $details['linked_chat_id'] : null,
            'slowmode_seconds' => $details['slowmode_seconds'] ?? null,
            'created' => $this->date($entity['date'] ?? null),
            'restricted' => (bool) ($entity['restricted'] ?? false),
        ];
    }

    /**
     * The people of a group, the newest first.
     *
     * @param  array<string, mixed> $params chat, limit (100, up to 200), offset
     * @return array<string, mixed>
     */
    private function members(object $telegram, array $params): array
    {
        $peer = $this->peer($params['chat'] ?? null);
        $limit = $this->clamp($params['limit'] ?? 100, 1, 200);
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $info = (array) $telegram->getInfo($peer);
        $type = (string) ($info['type'] ?? '');

        if ($type === 'user' || $type === 'bot') {
            throw new InvalidArgumentException('A private chat has no members list.');
        }

        if (isset($info['channel_id']) || in_array($type, ['channel', 'supergroup'], true)) {
            /* Not getPwrChat: it searches the members letter by letter and waits out any flood wait. */
            $page = (array) $telegram->channels->getParticipants([
                'channel' => $peer,
                'filter' => ['_' => 'channelParticipantsRecent'],
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $total = (int) ($page['count'] ?? 0);
        } else {
            $page = (array) $telegram->messages->getFullChat(['chat_id' => (int) ($info['chat_id'] ?? 0)]);

            $participants = (array) ($page['full_chat']['participants']['participants'] ?? []);
            $total = count($participants);

            $page['participants'] = array_slice($participants, $offset, $limit);
        }

        $users = [];

        foreach ((array) ($page['users'] ?? []) as $user) {
            if (is_array($user) && isset($user['id'])) {
                $users[(int) $user['id']] = $user;
            }
        }

        $members = [];

        foreach ((array) ($page['participants'] ?? []) as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            $userId = (int) ($participant['user_id'] ?? $this->peerId($participant['peer'] ?? null) ?? 0);
            $user = $users[$userId] ?? [];

            $members[] = [
                'id' => $userId,
                'name' => $this->personName($user, $userId),
                'username' => $this->username($user),
                'bot' => (bool) ($user['bot'] ?? false),
                'role' => $this->role((string) ($participant['_'] ?? '')),
                'rank' => $participant['rank'] ?? null,
                'joined' => $this->date($participant['date'] ?? null),
                'invited_by' => isset($participant['inviter_id']) ? (int) $participant['inviter_id'] : null,
            ];
        }

        return [
            'total' => $total,
            'offset' => $offset,
            'count' => count($members),
            'more' => $offset + count($members) < $total,
            'members' => $members,
        ];
    }

    /**
     * One person: name, username, bio, when they were last seen. No phone.
     *
     * @param  array<string, mixed> $params user (id or @username)
     * @return array<string, mixed>
     */
    private function user(object $telegram, array $params): array
    {
        $peer = $this->peer($params['user'] ?? null);

        $info = (array) $telegram->getInfo($peer);
        $user = (array) ($info['User'] ?? []);

        if ($user === []) {
            throw new InvalidArgumentException('That is not a person.');
        }

        $bio = null;

        try {
            $full = (array) $telegram->getFullInfo($peer);
            $bio = ((array) ($full['full'] ?? []))['about'] ?? null;
        } catch (Throwable) {
            /* The bio is a nicety. */
        }

        $status = (array) ($user['status'] ?? []);

        return [
            'id' => (int) ($info['bot_api_id'] ?? $user['id'] ?? 0),
            'name' => $this->personName($user, (int) ($user['id'] ?? 0)),
            'username' => $this->username($user),
            'bot' => (bool) ($user['bot'] ?? false),
            'deleted' => (bool) ($user['deleted'] ?? false),
            'bio' => $this->text($bio),
            'last_seen' => match ($status['_'] ?? '') {
                'userStatusOnline' => 'online',
                'userStatusOffline' => $this->date($status['was_online'] ?? null),
                'userStatusRecently' => 'recently',
                'userStatusLastWeek' => 'last week',
                'userStatusLastMonth' => 'last month',
                default => null,
            },
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shapes
    |--------------------------------------------------------------------------
    */

    /**
     * A message the way a person reads it.
     *
     * @param  array<string, mixed> $message
     * @param  array<string, array<int, array<string, mixed>>> $entities
     * @return array<string, mixed>
     */
    private function message(array $message, array $entities, ?int $chatId): array
    {
        $row = [
            'id' => (int) ($message['id'] ?? 0),
            'date' => $this->date($message['date'] ?? null),
            'out' => (bool) ($message['out'] ?? false),
            'from' => $this->sender($message, $entities, $chatId),
            'from_id' => $this->peerId($message['from_id'] ?? null),
            'text' => (string) ($message['message'] ?? ''),
        ];

        if (($message['_'] ?? '') === 'messageService') {
            $row['service'] = (string) ($message['action']['_'] ?? 'service');
        }

        $replyTo = (int) ($message['reply_to']['reply_to_msg_id'] ?? 0);

        if ($replyTo > 0) {
            $row['reply_to'] = $replyTo;
        }

        if (is_array($message['fwd_from'] ?? null)) {
            $from = $message['fwd_from'];
            $fromId = $this->peerId($from['from_id'] ?? null);

            $row['forwarded_from'] = $from['from_name']
                ?? ($fromId === null ? null : $this->describe($fromId, $entities)['title']);
        }

        $media = $this->media($message['media'] ?? null);

        if ($media !== null) {
            $row['media'] = $media;
        }

        if (! empty($message['edit_date'])) {
            $row['edited'] = $this->date($message['edit_date']);
        }

        if (isset($message['views'])) {
            $row['views'] = (int) $message['views'];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, array<int, array<string, mixed>>> $entities
     */
    private function sender(array $message, array $entities, ?int $chatId): ?string
    {
        $fromId = $this->peerId($message['from_id'] ?? null);

        /* A channel's post, or my own message in a private chat, carries no sender. */
        if ($fromId === null) {
            if (! empty($message['out'])) {
                return 'me';
            }

            return $chatId === null ? null : $this->describe($chatId, $entities)['title'];
        }

        return $this->describe($fromId, $entities)['title'];
    }

    /**
     * What the attachment is, in a few words.
     *
     * @return array<string, mixed>|null
     */
    private function media(mixed $media): ?array
    {
        if (! is_array($media) || ! isset($media['_'])) {
            return null;
        }

        $type = (string) $media['_'];

        switch ($type) {
            case 'messageMediaPhoto':
                return ['type' => 'photo'];

            case 'messageMediaDocument':
                $document = (array) ($media['document'] ?? []);
                $kind = 'file';
                $name = null;
                $duration = null;

                foreach ((array) ($document['attributes'] ?? []) as $attribute) {
                    switch ((string) ($attribute['_'] ?? '')) {
                        case 'documentAttributeFilename':
                            $name = (string) ($attribute['file_name'] ?? '');

                            break;
                        case 'documentAttributeAudio':
                            $kind = ! empty($attribute['voice']) ? 'voice' : 'audio';
                            $duration = (int) ($attribute['duration'] ?? 0);

                            break;
                        case 'documentAttributeVideo':
                            $kind = ! empty($attribute['round_message']) ? 'video_note' : 'video';
                            $duration = (int) ($attribute['duration'] ?? 0);

                            break;
                        case 'documentAttributeAnimated':
                            $kind = 'gif';

                            break;
                        case 'documentAttributeSticker':
                            $kind = 'sticker';

                            break;
                    }
                }

                return array_filter([
                    'type' => $kind,
                    'name' => $name,
                    'seconds' => $duration,
                    'bytes' => isset($document['size']) ? (int) $document['size'] : null,
                ], static fn ($value): bool => $value !== null && $value !== '');

            case 'messageMediaWebPage':
                return array_filter(['type' => 'link', 'url' => $media['webpage']['url'] ?? null, 'title' => $media['webpage']['title'] ?? null]);

            case 'messageMediaPoll':
                return array_filter(['type' => 'poll', 'question' => $this->text($media['poll']['question'] ?? null)]);

            case 'messageMediaContact':
                /* The person, not the number. */
                return array_filter(['type' => 'contact', 'name' => trim(($media['first_name'] ?? '') . ' ' . ($media['last_name'] ?? ''))]);

            case 'messageMediaGeo':
            case 'messageMediaGeoLive':
            case 'messageMediaVenue':
                return ['type' => 'location'];

            default:
                return ['type' => lcfirst(str_replace('messageMedia', '', $type))];
        }
    }

    /**
     * What the answer says about its people and chats, by id.
     *
     * @param  array<string, mixed> $page
     * @return array{users: array<int, array<string, mixed>>, chats: array<int, array<string, mixed>>}
     */
    private function entities(array $page): array
    {
        $entities = ['users' => [], 'chats' => []];

        foreach ((array) ($page['users'] ?? []) as $user) {
            if (is_array($user) && isset($user['id'])) {
                $entities['users'][(int) $user['id']] = $user;
            }
        }

        foreach ((array) ($page['chats'] ?? []) as $chat) {
            if (is_array($chat) && isset($chat['id'])) {
                $entities['chats'][(int) $chat['id']] = $chat;
            }
        }

        return $entities;
    }

    /**
     * Title, kind and username of the chat or person with this Bot API id.
     *
     * @param  array<string, array<int, array<string, mixed>>> $entities
     * @return array{kind: string, title: string, username: string|null, members: int|null}
     */
    private function describe(int $id, array $entities): array
    {
        if ($id > 0) {
            $user = $entities['users'][$id] ?? [];

            return [
                'kind' => ! empty($user['bot']) ? 'bot' : 'private',
                'title' => $this->personName($user, $id),
                'username' => $this->username($user),
                'members' => null,
            ];
        }

        $channel = $id <= -self::CHANNEL_OFFSET;
        $raw = $channel ? -$id - self::CHANNEL_OFFSET : -$id;
        $chat = $entities['chats'][$raw] ?? [];

        return [
            'kind' => $channel ? (! empty($chat['megagroup']) || ! empty($chat['gigagroup']) ? 'group' : 'channel') : 'group',
            'title' => (string) ($chat['title'] ?? "chat {$id}"),
            'username' => $this->username($chat),
            'members' => isset($chat['participants_count']) ? (int) $chat['participants_count'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function personName(array $user, int $id): string
    {
        if (! empty($user['deleted'])) {
            return 'Deleted account';
        }

        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        return $name !== '' ? $name : ($this->username($user) !== null ? '@' . $this->username($user) : "user {$id}");
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function username(array $entity): ?string
    {
        $username = $entity['username'] ?? ($entity['usernames'][0]['username'] ?? null);

        return is_string($username) && $username !== '' ? $username : null;
    }

    private function role(string $type): string
    {
        return match (true) {
            str_contains($type, 'Creator') => 'creator',
            str_contains($type, 'Admin') => 'admin',
            str_contains($type, 'Banned') => 'banned',
            str_contains($type, 'Left') => 'left',
            default => 'member',
        };
    }

    /**
     * A peer array as the Bot API id: a user is its id, a chat -id, a channel -100id.
     */
    private function peerId(mixed $peer): ?int
    {
        if (! is_array($peer)) {
            return null;
        }

        return match ($peer['_'] ?? '') {
            'peerUser' => (int) $peer['user_id'],
            'peerChat' => -(int) $peer['chat_id'],
            'peerChannel' => -self::CHANNEL_OFFSET - (int) $peer['channel_id'],
            default => null,
        };
    }

    /**
     * A chat as Telegram takes it: an id, "me" or an @username. Never an
     * invite link - opening one would join the chat.
     */
    private function peer(mixed $chat): int|string
    {
        $chat = trim((string) $chat);

        if (preg_match('/^-?\d{1,20}$/', $chat) === 1) {
            return (int) $chat;
        }

        if ($chat === 'me' || preg_match('/^@[A-Za-z0-9_]{3,64}$/', $chat) === 1) {
            return $chat;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $chat) === 1) {
            return '@' . $chat;
        }

        throw new InvalidArgumentException('A chat is an id, "me" or an @username.');
    }

    private function date(mixed $timestamp): ?string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0
            ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i:s')
            : null;
    }

    private function text(mixed $value): ?string
    {
        $value = is_array($value) ? (string) ($value['text'] ?? '') : (string) $value;

        return trim($value) === '' ? null : $value;
    }

    private function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
