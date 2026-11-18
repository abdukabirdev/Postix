<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotUser;
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
        private readonly SenderBotAdminDialog $admin,
        private readonly SenderBotDrivers $drivers,
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
            $api->call('setMyCommands', ['commands' => [
                ['command' => 'start', 'description' => 'Menu / Menyu'],
                ['command' => 'help', 'description' => 'Помощь / Yordam'],
            ]]);
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
        ]);
        $group->invite_link = $this->inviteLink($api, $group, $info);
        $group->save();

        SenderBotHistory::add('group_added', TelegramSenderBotEvent::ACTOR_ADMIN, group: $group, data: ['title' => $group->label()]);

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
                $group->invite_link = $this->inviteLink($api, $group, $info);
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
            $query = (array) $update['callback_query'];
            $data = (string) ($query['data'] ?? '');

            str_starts_with($data, 'am:') || str_starts_with($data, 'a:')
                ? $this->admin->callback($api, $query)
                : $this->dialog->callback($api, $query);

            return;
        }

        $message = (array) ($update['message'] ?? []);
        $chat = (array) ($message['chat'] ?? []);

        if (($chat['type'] ?? '') === 'private') {
            match (true) {
                $this->admin->wants($message) => $this->admin->message($api, $message),
                $this->drivers->wants($message) && ! $this->writingPost($message) => $this->drivers->handle($api, $message),
                default => $this->dialog->message($api, $message),
            };

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
     * An operator in the middle of a post may forward a message to post it:
     * that is theirs, not a driver's.
     */
    private function writingPost(array $message): bool
    {
        if (preg_match('/^\/start\s+p\d+$/', trim((string) ($message['text'] ?? ''))) === 1) {
            return false;
        }

        $user = TelegramSenderBotUser::query()->where('telegram_user_id', (int) ($message['from']['id'] ?? 0))->first();

        return $user !== null
            && $user->status === TelegramSenderBotUser::STATUS_APPROVED
            && $user->step === SenderBotDialog::STEP_TEXT;
    }

    /**
     * The link people open a group by when it has no @name: the one Telegram
     * gives the bot (it is admin), or a link of its own the bot makes - never
     * the main link exported anew, which would cut off the old one. Kept as
     * it was when none can be had (the admin may have typed it).
     */
    private function inviteLink(BotApi $api, TelegramSenderBotGroup $group, array $info): ?string
    {
        if (isset($info['username'])) {
            return $group->invite_link;
        }

        if (is_string($info['invite_link'] ?? null) && $info['invite_link'] !== '') {
            return $info['invite_link'];
        }

        if ($group->invite_link !== null || ! in_array($group->bot_status, ['administrator', 'creator'], true)) {
            return $group->invite_link;
        }

        try {
            $link = (array) $api->call('createChatInviteLink', ['chat_id' => $group->chat_id, 'name' => 'Sender bot']);

            return is_string($link['invite_link'] ?? null) ? $link['invite_link'] : null;
        } catch (BotApiError) {
            return null;
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

        $before = $group->exists ? $group->botIsIn() : false;

        $group->fill([
            'title' => mb_substr((string) ($chat['title'] ?? ''), 0, 255) ?: $group->title,
            'username' => isset($chat['username']) ? (string) $chat['username'] : $group->username,
            'type' => (string) $chat['type'],
            'bot_status' => (string) ($change['new_chat_member']['status'] ?? $group->bot_status),
        ])->save();

        if ($before !== $group->botIsIn()) {
            SenderBotHistory::add($group->botIsIn() ? 'group_joined' : 'group_left', group: $group, data: ['title' => $group->label(), 'status' => $group->bot_status]);
        }
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
