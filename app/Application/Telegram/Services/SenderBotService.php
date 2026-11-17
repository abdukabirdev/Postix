<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotGroup;
use DomainException;

/**
 * The sender bot itself: the token from the panel, the updates (they come
 * by the webhook set in izicrm.uz and forwarded here - this project never
 * sets, deletes or polls past it), the posts that are due, the groups.
 */
final class SenderBotService
{
    /** The longest sleep between two looks at the posts, seconds. */
    private const NAP = 20;

    public function __construct(
        private readonly SenderBotSettings $settings,
        private readonly SenderBotDialog $dialog,
        private readonly SenderBotDelivery $delivery,
    ) {}

    /**
     * The token typed in the panel: a bot of its own. Its webhook (set in
     * izicrm.uz) is left as it is.
     *
     * @return array{id: int, username: string, name: string}
     *
     * @throws DomainException taken
     * @throws BotApiError
     */
    public function connect(string $token): array
    {
        if (in_array($token, SenderBotSettings::takenTokens(), true)) {
            throw new DomainException('taken');
        }

        $api = new BotApi($token);
        $me = (array) $api->call('getMe');

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
     * Posts what is due for $seconds, sleeping until the next round. The
     * people's messages are not asked for here: they come by the webhook.
     *
     * @param callable(string): void $say
     *
     * @return array{copied: int}
     */
    public function run(int $seconds, callable $say): array
    {
        $totals = ['copied' => 0];
        $token = $this->settings->token();

        if ($token === null) {
            return $totals;
        }

        $api = new BotApi($token);
        $until = time() + $seconds;

        do {
            if (! $this->settings->enabled() || $this->settings->token() !== $token) {
                break;
            }

            $totals['copied'] += $this->delivery->dispatch($api, $say);
            $this->settings->setState(['polled_at' => now()->toIso8601String(), 'ran_at' => now()->toIso8601String()]);

            $nap = min(self::NAP, $this->delivery->dueIn(self::NAP), $until - time() - 1);

            if ($nap >= 1) {
                sleep($nap);
            }
        } while (time() < $until - 1);

        return $totals;
    }

    /**
     * One update, as Telegram sent it to the webhook.
     *
     * @throws BotApiError
     */
    public function handleUpdate(array $update): void
    {
        $api = $this->settings->api();

        if ($api === null || ! $this->settings->enabled()) {
            return;
        }

        $this->handle($api, $update);
        $this->settings->setState(['polled_at' => now()->toIso8601String(), 'error' => null, 'error_at' => null]);
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
}
