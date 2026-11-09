<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramInviteGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Exclude from the groups" under the bot's report of a deleted message
 * (the owner, 2026-11-09): an admin of the bot presses it, and the bot
 * removes the author from every one of our groups it is an admin of -
 * banChatMember, so a link does not bring them back; a channel that wrote
 * as itself, banChatSenderChat. Nobody is removed by the bot on its own:
 * only by the button.
 *
 * The report then says how it went and who pressed it, and loses the
 * button; when no group took the ban, the report stays as it was and the
 * one who pressed is told why - the button is there to try again once the
 * bot has the right.
 *
 * Never ours: our bot, our accounts, an admin of the bot. A group's own
 * admin Telegram refuses by itself.
 */
final class SpamBan
{
    /** callback_data of the button: ban:<the author's Telegram id>. */
    public const PREFIX = 'ban:';

    /** A 429 this short is waited out once, seconds. */
    private const WAIT_IN_PLACE = 5;

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly SpamAdmins $admins,
    ) {}

    /**
     * The button for a report about this author.
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null
     */
    public function button(?int $authorId): ?array
    {
        if ($authorId === null || $authorId === 0) {
            return null;
        }

        return ['inline_keyboard' => [[['text' => $this->line('button'), 'callback_data' => self::PREFIX . $authorId]]]];
    }

    /**
     * A button pressed under a report.
     *
     * @param array<string, mixed> $callback a Bot API CallbackQuery
     * @param callable(string): void $say
     *
     * @return bool whether it was this button
     */
    public function take(BotApi $api, array $callback, callable $say): bool
    {
        $data = (string) ($callback['data'] ?? '');

        if (! str_starts_with($data, self::PREFIX)) {
            return false;
        }

        $authorId = (int) substr($data, strlen(self::PREFIX));
        $from = (array) ($callback['from'] ?? []);

        try {
            if (! $this->admins->isAdmin($from)) {
                $this->answer($api, $callback, $this->line('not_admin'), true);
                $say('ban: pressed by ' . JoinRequestService::who($from) . ', not an admin of the bot');

                return true;
            }

            if ($authorId === 0 || $this->isOurs($authorId)) {
                $this->answer($api, $callback, $this->line('ours'), true);

                return true;
            }

            $results = $this->ban($api, $authorId);
            $banned = array_filter($results, static fn (array $result) => $result['error'] === null);
            $failed = array_filter($results, static fn (array $result) => $result['error'] !== null);

            $say("ban: {$authorId} by " . JoinRequestService::who($from) . ': ' . count($banned) . ' of ' . count($results) . ' groups');

            if ($banned === []) {
                $this->answer($api, $callback, $results === [] ? $this->line('no_groups') : $this->line('none', ['why' => $this->reasons($failed)]), true);

                return true;
            }

            $this->markReport($api, $callback, $this->line($failed === [] ? 'done' : 'partly', [
                'n' => count($banned),
                'total' => count($results),
                'admin' => SpamCleaner::nameOf($from) ?? JoinRequestService::who($from),
                'why' => $this->reasons($failed),
            ]));
            $this->answer($api, $callback, $this->line('done_short', ['n' => count($banned)]), false);
        } catch (Throwable $e) {
            Log::warning('Join bot: the ban button could not be handled', ['error' => $e->getMessage()]);

            try {
                $this->answer($api, $callback, $this->line('error'), true);
            } catch (Throwable) {
                // The press is answered or not; the bot goes on.
            }
        }

        return true;
    }

    /**
     * Out of every one of our groups the bot can reach.
     *
     * @return list<array{title: string, error: string|null}> each group: removed (null) or why not
     */
    private function ban(BotApi $api, int $authorId): array
    {
        $results = [];

        foreach (TelegramInviteGroup::query()->orderBy('id')->get() as $group) {
            $chat = $group->telegram_chat_id ?: ($group->isInvite() ? null : '@' . ltrim($group->link, '@'));
            $title = $group->title ?: $group->link;

            /* Joined by an invite link only, never seen by an account yet: the bot cannot name it. */
            if ($chat === null) {
                continue;
            }

            $results[] = ['title' => $title, 'error' => $this->banIn($api, $chat, $authorId)];
        }

        return $results;
    }

    /**
     * @return string|null null: removed; else why not, as a code the texts name
     */
    private function banIn(BotApi $api, int|string $chat, int $authorId): ?string
    {
        /* A channel that wrote as itself has a negative id. */
        [$method, $params] = $authorId < 0
            ? ['banChatSenderChat', ['chat_id' => $chat, 'sender_chat_id' => $authorId]]
            : ['banChatMember', ['chat_id' => $chat, 'user_id' => $authorId]];

        for ($try = 1; ; $try++) {
            try {
                $api->call($method, $params, 10);

                return null;
            } catch (BotApiError $e) {
                if ($try === 1 && $e->getCode() === 429 && $e->retryAfter > 0 && $e->retryAfter <= self::WAIT_IN_PLACE) {
                    sleep($e->retryAfter);

                    continue;
                }

                return self::code($e->reason());
            }
        }
    }

    /**
     * The report gets how it went on its end, and loses the button.
     *
     * @param array<string, mixed> $callback
     */
    private function markReport(BotApi $api, array $callback, string $result): void
    {
        $message = (array) ($callback['message'] ?? []);
        $chatId = $message['chat']['id'] ?? null;

        if ($chatId === null || empty($message['message_id'])) {
            return;
        }

        try {
            $api->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => (int) $message['message_id'],
                'text' => mb_substr(trim((string) ($message['text'] ?? '')) . "\n\n" . $result, 0, 4000),
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => []],
            ], 10);
        } catch (BotApiError $e) {
            Log::warning('Join bot: the report could not be marked after a ban', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function answer(BotApi $api, array $callback, string $text, bool $alert): void
    {
        if (empty($callback['id'])) {
            return;
        }

        try {
            $api->call('answerCallbackQuery', [
                'callback_query_id' => (string) $callback['id'],
                'text' => mb_substr($text, 0, 200),
                'show_alert' => $alert,
            ], 10);
        } catch (BotApiError) {
            // Too late to answer (a press older than the bot's last poll): the report shows it all the same.
        }
    }

    /**
     * Our bot, one of our accounts, an admin of the bot.
     */
    private function isOurs(int $id): bool
    {
        return $id === (int) ($this->settings->current()['bot']['id'] ?? 0)
            || $this->admins->isAdmin(['id' => $id])
            || TelegramAccount::query()->where('telegram_user_id', $id)->exists();
    }

    /**
     * «Group»: why not; one line per group.
     *
     * @param array<int, array{title: string, error: string|null}> $failed
     */
    private function reasons(array $failed): string
    {
        $lines = [];

        foreach ($failed as $result) {
            $code = (string) $result['error'];
            $text = __("driver_check.invites.bot.ban.errors.{$code}", [], $this->locale());
            $lines[] = "«{$result['title']}» — " . (is_string($text) && $text !== "driver_check.invites.bot.ban.errors.{$code}" ? $text : $code);
        }

        return implode("\n", $lines);
    }

    /**
     * Telegram's words for the usual refusals as a code the texts name.
     */
    private static function code(string $reason): string
    {
        $lower = mb_strtolower($reason);

        return match (true) {
            str_contains($lower, 'administrator'), str_contains($lower, 'owner'), $reason === 'USER_ADMIN_INVALID' => 'IS_ADMIN',
            str_contains($lower, 'not enough rights'), str_contains($lower, 'admin_required'), $reason === 'CHAT_ADMIN_REQUIRED' => 'NO_RIGHT',
            str_contains($lower, 'chat not found'), str_contains($lower, 'kicked'), str_contains($lower, 'not a member') => 'BOT_NOT_IN_CHAT',
            default => $reason,
        };
    }

    /**
     * @param array<string, mixed> $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return rtrim((string) __("driver_check.invites.bot.ban.{$key}", $replace, $this->locale()));
    }

    private function locale(): string
    {
        return $this->settings->reports()['locale'];
    }
}
