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

        /* Another bot: its updates start from its own first one. */
        TelegramSetting::set(self::STATE_KEY, null);
    }

    public function setEnabled(bool $enabled): void
    {
        $values = $this->current();

        TelegramSetting::set(self::KEY, [...$values, 'enabled' => $values['token'] !== null && $enabled]);
    }

    public function forget(): void
    {
        TelegramSetting::query()->whereIn('key', [self::KEY, self::STATE_KEY])->delete();
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
