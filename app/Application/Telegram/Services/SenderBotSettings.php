<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramSetting;
use Illuminate\Support\Carbon;

/**
 * The sender bot: its token and switch, who is told of a new request and
 * how, the limits of a post, and what the poll last said.
 *
 * The panel writes KEY, NOTIFY_KEY and LIMITS_KEY, the poll writes
 * STATE_KEY - neither overwrites the other's.
 */
final class SenderBotSettings
{
    public const KEY = 'sender_bot';

    public const STATE_KEY = 'sender_bot.state';

    public const NOTIFY_KEY = 'sender_bot.notify';

    public const LIMITS_KEY = 'sender_bot.limits';

    /** What drivers get: see options(). */
    public const OPTIONS_KEY = 'sender_bot.options';

    public const DEFAULT_OPTIONS = ['driver_contacts' => true, 'contact_button' => false];

    /** The owner is told until the panel says otherwise. */
    public const DEFAULT_ADMIN_CHAT = JoinBotSettings::DEFAULT_REPORT_CHAT;

    public const DEFAULT_LIMITS = ['max_times' => 20, 'min_interval' => 1, 'max_interval' => 1440, 'max_active' => 3, 'per_day' => 10];

    /** The most posts one person may have running, and start in a day, the panel takes. */
    public const MAX_ACTIVE = 50;

    public const MAX_PER_DAY = 500;

    /** The hardest limits the panel takes. */
    public const MAX_TIMES = 500;

    public const MAX_INTERVAL = 10080;

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

    public function enabled(): bool
    {
        return $this->current()['enabled'];
    }

    /** The Bot API of the bot in now, or null without a token. */
    public function api(): ?BotApi
    {
        $token = $this->token();

        return $token !== null ? new BotApi($token) : null;
    }

    /**
     * @param array{id: int, username: string, name: string} $bot
     */
    public function saveToken(string $token, array $bot): void
    {
        TelegramSetting::set(self::KEY, ['token' => $token, 'enabled' => true, 'bot' => $bot]);
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
     * Who is told of a new request: the admin's chat id, by which listener
     * account (null: the first one switched on), and whether the bot also
     * brings the request to the admin with the two buttons (once the admin
     * has pressed Start in it).
     *
     * new_posts: the bot brings the admin every new post of somebody else,
     * with Stop / Delete / Block under it.
     *
     * @return array{chat_id: string|null, listener_key: string|null, via_bot: bool, new_posts: bool}
     */
    public function notify(): array
    {
        $saved = TelegramSetting::get(self::NOTIFY_KEY);

        if (! is_array($saved)) {
            return ['chat_id' => self::DEFAULT_ADMIN_CHAT, 'listener_key' => null, 'via_bot' => true, 'new_posts' => true];
        }

        $chat = trim((string) ($saved['chat_id'] ?? ''));
        $key = trim((string) ($saved['listener_key'] ?? ''));

        return [
            'chat_id' => $chat === '' ? null : $chat,
            'listener_key' => $key === '' ? null : $key,
            'via_bot' => filter_var($saved['via_bot'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'new_posts' => filter_var($saved['new_posts'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function saveNotify(?string $chatId, ?string $listenerKey, bool $viaBot, bool $newPosts = true): void
    {
        TelegramSetting::set(self::NOTIFY_KEY, [
            'chat_id' => trim((string) $chatId),
            'listener_key' => trim((string) $listenerKey),
            'via_bot' => $viaBot,
            'new_posts' => $newPosts,
        ]);
    }

    /**
     * driver_contacts: a driver who forwards a post to the bot gets the
     * operator's chat (or is asked for a contact to pass on).
     * contact_button: a "Write to the author" button under every copy in
     * the groups, opening the bot with that post.
     *
     * @return array{driver_contacts: bool, contact_button: bool}
     */
    public function options(): array
    {
        $saved = TelegramSetting::get(self::OPTIONS_KEY);
        $saved = is_array($saved) ? $saved : [];

        return [
            'driver_contacts' => filter_var($saved['driver_contacts'] ?? self::DEFAULT_OPTIONS['driver_contacts'], FILTER_VALIDATE_BOOLEAN),
            'contact_button' => filter_var($saved['contact_button'] ?? self::DEFAULT_OPTIONS['contact_button'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function saveOptions(bool $driverContacts, bool $contactButton): void
    {
        TelegramSetting::set(self::OPTIONS_KEY, ['driver_contacts' => $driverContacts, 'contact_button' => $contactButton]);
    }

    /** This Telegram user is the admin of the bot. */
    public function isAdmin(int|string|null $telegramId): bool
    {
        $admin = $this->adminId();

        return $admin !== null && $telegramId !== null && (int) $telegramId === $admin;
    }

    /**
     * The listener that tells the admin: the one chosen, or the first one
     * switched on with an account. Null: nobody can.
     */
    public function notifyListener(): ?TelegramListener
    {
        $chosen = $this->notify()['listener_key'];

        return TelegramListener::query()
            ->where('is_enabled', true)
            ->whereNotNull('telegram_account_id')
            ->when($chosen !== null, fn ($q) => $q->where('key', $chosen))
            ->orderBy('id')
            ->first();
    }

    /** The admin's Telegram id as a number, or null. */
    public function adminId(): ?int
    {
        $chat = $this->notify()['chat_id'];

        return $chat !== null && preg_match('/^-?\d+$/', $chat) === 1 ? (int) $chat : null;
    }

    /**
     * What one post and one person may: rounds, the pause, posts running at
     * once, posts started in a day; and the quiet hours (HH:MM, both or
     * neither) when no round goes - those are put off till the end.
     *
     * @return array{max_times: int, min_interval: int, max_interval: int, max_active: int, per_day: int, quiet_from: string|null, quiet_to: string|null}
     */
    public function limits(): array
    {
        $saved = TelegramSetting::get(self::LIMITS_KEY);
        $saved = is_array($saved) ? $saved : [];

        $int = static fn (string $key, int $min, int $max): int => max($min, min($max, (int) ($saved[$key] ?? self::DEFAULT_LIMITS[$key])));

        $minInterval = $int('min_interval', 1, self::MAX_INTERVAL);
        $from = self::clock($saved['quiet_from'] ?? null);
        $to = self::clock($saved['quiet_to'] ?? null);
        $quiet = $from !== null && $to !== null && $from !== $to;

        return [
            'max_times' => $int('max_times', 1, self::MAX_TIMES),
            'min_interval' => $minInterval,
            'max_interval' => max($minInterval, $int('max_interval', 1, self::MAX_INTERVAL)),
            'max_active' => $int('max_active', 1, self::MAX_ACTIVE),
            'per_day' => $int('per_day', 1, self::MAX_PER_DAY),
            'quiet_from' => $quiet ? $from : null,
            'quiet_to' => $quiet ? $to : null,
        ];
    }

    public function saveLimits(
        int $maxTimes,
        int $minInterval,
        int $maxInterval,
        ?int $maxActive = null,
        ?int $perDay = null,
        ?string $quietFrom = null,
        ?string $quietTo = null,
    ): void {
        TelegramSetting::set(self::LIMITS_KEY, [
            'max_times' => $maxTimes,
            'min_interval' => $minInterval,
            'max_interval' => $maxInterval,
            'max_active' => $maxActive ?? self::DEFAULT_LIMITS['max_active'],
            'per_day' => $perDay ?? self::DEFAULT_LIMITS['per_day'],
            'quiet_from' => self::clock($quietFrom),
            'quiet_to' => self::clock($quietTo),
        ]);
    }

    /**
     * When the quiet hours that $at falls into end, or null when $at is not
     * quiet (or there are no quiet hours). In the app's time zone.
     */
    public function quietUntil(?Carbon $at = null): ?Carbon
    {
        $limits = $this->limits();

        if ($limits['quiet_from'] === null) {
            return null;
        }

        $at = ($at ?? now())->copy()->timezone(config('app.timezone'));
        $now = $at->format('H:i');
        $from = $limits['quiet_from'];
        $to = $limits['quiet_to'];

        /* 23:00-07:00 crosses midnight; 13:00-14:00 does not. */
        $quiet = $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);

        if (! $quiet) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $to));
        $end = $at->copy()->setTime($h, $m);

        return $end->lte($at) ? $end->addDay() : $end;
    }

    /** "7:05", "07.05" -> "07:05"; anything else -> null. */
    public static function clock(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*$/', $value, $m) !== 1) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];

        return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : null;
    }

    /**
     * @return array{offset: int, polled_at: string|null, error: string|null, error_at: string|null, ran_at: string|null}
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
            /* The last time the posting command was alive (not the webhook). */
            'ran_at' => $state['ran_at'] ?? null,
        ];
    }

    public function setState(array $changes): void
    {
        TelegramSetting::set(self::STATE_KEY, array_filter(
            array_replace($this->state(), $changes),
            static fn ($value) => $value !== null,
        ));
    }

    public function alive(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $polled = $this->state()['polled_at'];

        return $polled !== null && Carbon::parse($polled)->gt(now()->subSeconds(self::ALIVE_SECONDS));
    }

    /** The tokens of every other bot of this project. */
    public static function takenTokens(): array
    {
        return array_values(array_filter([
            (string) config('telegram.bots.mybot.token'),
            (string) config('services.telegram.alerts.bot_token'),
            (string) app(JoinBotSettings::class)->token(),
        ], static fn (string $token) => $token !== '' && $token !== 'YOUR-BOT-TOKEN'));
    }
}
