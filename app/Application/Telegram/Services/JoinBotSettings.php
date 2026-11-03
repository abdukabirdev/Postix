<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSetting;
use Illuminate\Support\Carbon;

/**
 * Our own bot that approves the requests to join our groups: its token and
 * switch (set in the panel), and what the poll last said.
 *
 * Two TelegramSettings: the panel writes the first, the poll the second -
 * neither overwrites the other's.
 */
final class JoinBotSettings
{
    public const KEY = 'group_invite.join_bot';

    /** Where the poll is: the next update id, when it last asked Telegram, its last error, the chats the bot is in. */
    public const STATE_KEY = 'group_invite.join_bot.state';

    /**
     * The bot's own links that take requests, one per group: our accounts
     * ask to join by them (createChatInviteLink). Only the invite run
     * writes it, apart from the poll's state.
     */
    public const LINKS_KEY = 'group_invite.join_bot.links';

    /** Where the bot reports everything it does: a chat id set in the panel. */
    public const REPORTS_KEY = 'group_invite.join_bot.reports';

    /** Whether the last report got through; the poll and the invite run both write it. */
    public const REPORTS_STATE_KEY = 'group_invite.join_bot.reports.state';

    /** Reports go to the owner's chat until the panel says otherwise. */
    public const DEFAULT_REPORT_CHAT = '8028326577';

    /** The spam list: how many seconds after it was sent a message goes, and whether the owner is told (SpamCleaner). */
    public const SPAM_KEY = 'group_invite.join_bot.spam';

    /** A message of the spam list goes this many seconds after it was sent, until the panel says otherwise. */
    public const DEFAULT_SPAM_DELAY = 15;

    /** The longest wait the panel takes, seconds: the Bot API deletes a message for 48 hours. */
    public const MAX_SPAM_DELAY = 3600;

    /** The poll asked Telegram this recently, seconds: it is alive. */
    private const ALIVE_SECONDS = 120;

    /**
     * @return array{token: string|null, enabled: bool, bot: array{id: int, username: string, name: string}|null}
     */
    public function current(): array
    {
        $saved = TelegramSetting::get(self::KEY);
        $saved = is_array($saved) ? $saved : [];

        $token = is_string($saved['token'] ?? null) && $saved['token'] !== '' ? $saved['token'] : null;

        return [
            'token' => $token,
            'enabled' => $token !== null && filter_var($saved['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'bot' => $token !== null && is_array($saved['bot'] ?? null) ? $saved['bot'] : null,
        ];
    }

    public function token(): ?string
    {
        return $this->current()['token'];
    }

    /**
     * There is a token and the switch is on: the scheduler starts the poll.
     */
    public function enabled(): bool
    {
        return $this->current()['enabled'];
    }

    /**
     * @param array{id: int, username: string, name: string} $bot what getMe said
     */
    public function saveToken(string $token, array $bot): void
    {
        TelegramSetting::set(self::KEY, ['token' => $token, 'enabled' => true, 'bot' => $bot]);

        /* Another bot: its updates start from its own first one, its links are its own. */
        TelegramSetting::set(self::STATE_KEY, null);
        TelegramSetting::set(self::LINKS_KEY, null);
    }

    public function setEnabled(bool $enabled): void
    {
        $values = $this->current();

        TelegramSetting::set(self::KEY, [...$values, 'enabled' => $values['token'] !== null && $enabled]);
    }

    public function forget(): void
    {
        TelegramSetting::query()->whereIn('key', [self::KEY, self::STATE_KEY, self::LINKS_KEY])->delete();
    }

    /**
     * The links of the bot in now, by group (its chat id or @name).
     *
     * @return array<string, string>
     */
    public function links(): array
    {
        $saved = TelegramSetting::get(self::LINKS_KEY);

        if (! is_array($saved) || (int) ($saved['bot_id'] ?? 0) !== $this->botId() || ! is_array($saved['chats'] ?? null)) {
            return [];
        }

        return array_filter($saved['chats'], 'is_string');
    }

    /**
     * @param string|null $link null: the link is gone, a new one is made next time
     */
    public function saveLink(string $chat, ?string $link): void
    {
        $chats = $this->links();

        if ($link === null) {
            unset($chats[$chat]);
        } else {
            $chats[$chat] = $link;
        }

        TelegramSetting::set(self::LINKS_KEY, ['bot_id' => $this->botId(), 'chats' => $chats]);
    }

    /**
     * Where the reports go. Never set: the owner's chat; emptied in the
     * panel: nowhere. The locale is the one of whoever set it.
     *
     * @return array{chat_id: string|null, enabled: bool, locale: string}
     */
    public function reports(): array
    {
        $saved = TelegramSetting::get(self::REPORTS_KEY);

        if (! is_array($saved)) {
            return ['chat_id' => self::DEFAULT_REPORT_CHAT, 'enabled' => true, 'locale' => 'ru'];
        }

        $chat = trim((string) ($saved['chat_id'] ?? ''));

        return [
            'chat_id' => $chat === '' ? null : $chat,
            'enabled' => $chat !== '' && filter_var($saved['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'locale' => is_string($saved['locale'] ?? null) && $saved['locale'] !== '' ? $saved['locale'] : 'ru',
        ];
    }

    public function saveReports(?string $chatId, bool $enabled, string $locale): void
    {
        TelegramSetting::set(self::REPORTS_KEY, ['chat_id' => trim((string) $chatId), 'enabled' => $enabled, 'locale' => $locale]);
    }

    /**
     * The spam list's settings: a message goes $delay seconds after it was
     * sent; $notify: the owner is told of each, without a sound (to the
     * chat the reports go to).
     *
     * @return array{delay: int, notify: bool}
     */
    public function spam(): array
    {
        $saved = TelegramSetting::get(self::SPAM_KEY);
        $saved = is_array($saved) ? $saved : [];

        $delay = filter_var($saved['delay'] ?? null, FILTER_VALIDATE_INT);

        return [
            'delay' => $delay === false ? self::DEFAULT_SPAM_DELAY : max(0, min(self::MAX_SPAM_DELAY, $delay)),
            'notify' => filter_var($saved['notify'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function saveSpam(int $delay, bool $notify): void
    {
        TelegramSetting::set(self::SPAM_KEY, ['delay' => max(0, min(self::MAX_SPAM_DELAY, $delay)), 'notify' => $notify]);
    }

    /**
     * @return array{sent_at: string|null, error: string|null, error_at: string|null}
     */
    public function reportsState(): array
    {
        $state = TelegramSetting::get(self::REPORTS_STATE_KEY);
        $state = is_array($state) ? $state : [];

        return [
            'sent_at' => $state['sent_at'] ?? null,
            'error' => $state['error'] ?? null,
            'error_at' => $state['error_at'] ?? null,
        ];
    }

    /**
     * @param array{sent_at?: string|null, error?: string|null, error_at?: string|null} $state
     */
    public function setReportsState(array $state): void
    {
        TelegramSetting::set(self::REPORTS_STATE_KEY, array_filter(
            array_replace($this->reportsState(), $state),
            static fn ($value) => $value !== null,
        ));
    }

    private function botId(): int
    {
        return (int) ($this->current()['bot']['id'] ?? 0);
    }

    /**
     * @return array{offset: int, polled_at: string|null, error: string|null, error_at: string|null, chats: array<string, array<string, mixed>>}
     */
    public function state(): array
    {
        $state = TelegramSetting::get(self::STATE_KEY);
        $state = is_array($state) ? $state : [];

        return [
            'offset' => (int) ($state['offset'] ?? 0),
            'polled_at' => $state['polled_at'] ?? null,
            'error' => $state['error'] ?? null,
            'error_at' => $state['error_at'] ?? null,
            'chats' => is_array($state['chats'] ?? null) ? $state['chats'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $changes a null removes the key
     */
    public function setState(array $changes): void
    {
        TelegramSetting::set(self::STATE_KEY, array_filter(
            array_replace($this->state(), $changes),
            static fn ($value) => $value !== null,
        ));
    }

    /**
     * Switched on and asking Telegram right now: a request sent to a group
     * will be approved in a few seconds.
     */
    public function alive(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $polled = $this->state()['polled_at'];

        return $polled !== null && Carbon::parse($polled)->gt(now()->subSeconds(self::ALIVE_SECONDS));
    }

    /**
     * 123456:ABC...xyz -> 123456:••••xyz: the page shows which token is in, not the token.
     */
    public static function mask(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        [$id, $secret] = array_pad(explode(':', $token, 2), 2, '');

        return $id . ':••••' . mb_substr($secret, -4);
    }

    /**
     * The tokens of the bots this project already has (the driver check bot,
     * the alerts bot): taking their updates would cut them off.
     *
     * @return list<string>
     */
    public static function takenTokens(): array
    {
        return array_values(array_filter([
            (string) config('telegram.bots.mybot.token'),
            (string) config('services.telegram.alerts.bot_token'),
        ], static fn (string $token) => $token !== '' && $token !== 'YOUR-BOT-TOKEN'));
    }
}
