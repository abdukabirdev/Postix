<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotGroup;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sender bot itself: the token from the panel, the poll that answers
 * people and posts what is due, and the groups it is in.
 */
final class SenderBotService
{
    private const LONG_POLL = 25;

    private const UPDATES = ['message', 'callback_query', 'my_chat_member'];

    /** After a network error, at most this long before asking again, seconds. */
    private const WAIT_IN_PLACE = 30;

    public function __construct(
        private readonly SenderBotSettings $settings,
        private readonly SenderBotDialog $dialog,
        private readonly SenderBotDelivery $delivery,
    ) {}

    /**
     * The token typed in the panel: a bot of its own, with no webhook.
     *
     * @return array{id: int, username: string, name: string}
     *
     * @throws DomainException taken | webhook
     * @throws BotApiError
     */
    public function connect(string $token): array
    {
        if (in_array($token, SenderBotSettings::takenTokens(), true)) {
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

        /* The commands in the bot's menu. */
        try {
            $api->call('setMyCommands', ['commands' => [['command' => 'start', 'description' => 'Menu / Menyu']]]);
        } catch (BotApiError) {
            /* Only the menu button: the bot works without it. */
        }

        return $bot;
    }

    /**
     * Answers people and posts what is due for $seconds. An update is
     * marked read only once it is handled.
     *
     * @param callable(string): void $say
     *
     * @return array{updates: int, copied: int}
     */
    public function poll(int $seconds, callable $say): array
    {
        $totals = ['updates' => 0, 'copied' => 0];
        $token = $this->settings->token();

        if ($token === null) {
            return $totals;
        }

        $api = new BotApi($token);
        $until = time() + $seconds;

        while (($wait = min(self::LONG_POLL, $until - time() - 1)) >= 1) {
            if (! $this->settings->enabled() || $this->settings->token() !== $token) {
                break;
            }

            /* What is due goes first; the ask waits no longer than the next round. */
            $totals['copied'] += $this->delivery->dispatch($api, $say);
            $wait = max(1, min($wait, $this->delivery->dueIn($wait)));

            try {
                $updates = $api->call('getUpdates', [
                    'offset' => $this->settings->state()['offset'],
                    'timeout' => $wait,
                    'allowed_updates' => self::UPDATES,
                ], $wait + 15);
            } catch (BotApiError $e) {
                $this->trouble($e, $say);

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
                    $this->handle($api, $update);
                } catch (BotApiError $e) {
                    $this->trouble($e, $say);

                    /* A person who blocked the bot will never take the answer: read, not asked again. */
                    if ($e->transient) {
                        sleep(max(3, min(self::WAIT_IN_PLACE, $e->retryAfter)));

                        continue 2;
                    }
                } catch (Throwable $e) {
                    Log::error('Sender bot: an update failed', ['update' => $update['update_id'], 'error' => $e->getMessage()]);
                    $say('update ' . $update['update_id'] . ' failed: ' . $e->getMessage());
                }

                $totals['updates']++;
                $this->settings->setState(['offset' => (int) $update['update_id'] + 1]);
            }
        }

        if ($this->settings->enabled() && $this->settings->token() === $token) {
            $totals['copied'] += $this->delivery->dispatch($api, $say);
        }

        return $totals;
    }

    /**
     * A group typed in the panel: @name, t.me link or chat id. The bot must
     * be in it to post; it is added either way and the page says so.
     *
     * @throws DomainException no_bot | not_found
     */
    public function addGroup(string $input): TelegramSenderBotGroup
    {
        $api = $this->settings->api() ?? throw new DomainException('no_bot');
        $chat = self::chatRef($input) ?? throw new DomainException('not_found');

        try {
            $info = (array) $api->call('getChat', ['chat_id' => $chat]);
        } catch (BotApiError $e) {
            throw new DomainException('not_found', previous: $e);
        }

        $group = TelegramSenderBotGroup::query()->firstOrNew(['chat_id' => (int) $info['id']]);
        $group->fill([
            'title' => mb_substr((string) ($info['title'] ?? ''), 0, 255) ?: null,
            'username' => isset($info['username']) ? (string) $info['username'] : null,
            'type' => (string) ($info['type'] ?? ''),
            'is_active' => true,
            'bot_status' => $this->standing($api, (int) $info['id']),
        ])->save();

        return $group;
    }

    /**
     * Asks Telegram again where the bot stands in every group and what each
     * is called now.
     */
    public function checkGroups(): int
    {
        $api = $this->settings->api() ?? throw new DomainException('no_bot');
        $checked = 0;

        foreach (TelegramSenderBotGroup::query()->whereNotNull('chat_id')->get() as $group) {
            try {
                $info = (array) $api->call('getChat', ['chat_id' => $group->chat_id]);
                $group->fill([
                    'title' => mb_substr((string) ($info['title'] ?? $group->title), 0, 255) ?: $group->title,
                    'username' => isset($info['username']) ? (string) $info['username'] : null,
                    'bot_status' => $this->standing($api, $group->chat_id),
                    'last_error' => null,
                ]);
            } catch (BotApiError $e) {
                $group->fill(['bot_status' => 'left', 'last_error' => mb_substr($e->reason(), 0, 500)]);
            }

            $group->save();
            $checked++;
        }

        return $checked;
    }

    private function handle(BotApi $api, array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->membership((array) $update['my_chat_member']);

            return;
        }

        if (isset($update['callback_query'])) {
            $this->dialog->callback($api, (array) $update['callback_query']);

            return;
        }

        $message = (array) ($update['message'] ?? []);
        $chat = (array) ($message['chat'] ?? []);

        if (($chat['type'] ?? '') === 'private') {
            $this->dialog->message($api, $message);

            return;
        }

        /* A group became a supergroup: its id changed. */
        if (isset($message['migrate_to_chat_id'], $chat['id'])) {
            TelegramSenderBotGroup::query()
                ->where('chat_id', (int) $chat['id'])
                ->update(['chat_id' => (int) $message['migrate_to_chat_id'], 'type' => 'supergroup']);
        }
    }

    /**
     * The bot was added to a group, made admin, removed: the group is
     * listed (off until the admin turns it on) with where the bot stands.
     */
    private function membership(array $change): void
    {
        $chat = (array) ($change['chat'] ?? []);

        if (! isset($chat['id']) || ! in_array($chat['type'] ?? '', ['group', 'supergroup', 'channel'], true)) {
            return;
        }

        $group = TelegramSenderBotGroup::query()->firstOrNew(['chat_id' => (int) $chat['id']]);

        if (! $group->exists) {
            $group->is_active = false;
        }

        $group->fill([
            'title' => mb_substr((string) ($chat['title'] ?? ''), 0, 255) ?: $group->title,
            'username' => isset($chat['username']) ? (string) $chat['username'] : $group->username,
            'type' => (string) $chat['type'],
            'bot_status' => (string) ($change['new_chat_member']['status'] ?? $group->bot_status),
        ])->save();
    }

    private function standing(BotApi $api, int $chatId): ?string
    {
        $botId = (int) ($this->settings->current()['bot']['id'] ?? 0);

        if ($botId === 0) {
            return null;
        }

        try {
            return (string) (((array) $api->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]))['status'] ?? '') ?: null;
        } catch (BotApiError) {
            return 'left';
        }
    }

    /** @-name, t.me link or numeric id -> what getChat takes. */
    public static function chatRef(string $input): int|string|null
    {
        $input = trim($input);

        if (preg_match('/^-?\d{5,20}$/', $input) === 1) {
            return (int) $input;
        }

        if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z][A-Za-z0-9_]{3,})/?$~', $input, $m) === 1) {
            return '@' . $m[1];
        }

        if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{3,})$/', $input, $m) === 1) {
            return '@' . $m[1];
        }

        return null;
    }

    private function trouble(BotApiError $e, callable $say): void
    {
        $this->settings->setState(['error' => mb_substr($e->reason(), 0, 300), 'error_at' => now()->toIso8601String()]);
        $say('Telegram: ' . $e->getMessage());
    }
}
