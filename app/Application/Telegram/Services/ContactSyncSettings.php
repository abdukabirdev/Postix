<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSetting;

/**
 * The contact sync's knobs, kept in the panel (Contact sync -> Settings)
 * instead of .env, read fresh by every run.
 *
 * What was never saved falls back to config('services.telegram.contact_sync'),
 * so a server that has only ever had .env keeps behaving as it did.
 */
final class ContactSyncSettings
{
    public const KEY = 'contact_sync.settings';

    /**
     * What the panel may set, with the bounds it is held to.
     */
    public const LIMITS = [
        'daily_limit' => [1, 1000],
        'delay_min' => [0, 300],
        'delay_max' => [0, 300],
    ];

    /**
     * @return array{enabled: bool, schedule_at: string, daily_limit: int, skip_usernames: bool, delay_min: int, delay_max: int, nick_base: string, excluded_accounts: list<int>}
     */
    public function defaults(): array
    {
        $config = (array) config('services.telegram.contact_sync');

        return [
            'enabled' => true,
            'schedule_at' => (string) ($config['schedule_at'] ?? '03:30'),
            'daily_limit' => (int) ($config['daily_limit'] ?? 80),
            /* People with a public @username stay in the database only: reached by the link. */
            'skip_usernames' => true,
            'delay_min' => (int) ($config['delay_min'] ?? 3),
            'delay_max' => (int) ($config['delay_max'] ?? 5),
            'nick_base' => '',
            /* The Driver Check account out of the box; see excluded(). */
            'excluded_accounts' => self::listenerIds(),
        ];
    }

    /**
     * The saved values over the defaults, each one made safe again: a hand
     * edited row must not be able to start a run with a negative limit.
     *
     * @return array{enabled: bool, schedule_at: string, daily_limit: int, skip_usernames: bool, delay_min: int, delay_max: int, nick_base: string, excluded_accounts: list<int>}
     */
    public function current(): array
    {
        $saved = TelegramSetting::get(self::KEY);

        return $this->normalise(is_array($saved) ? $saved : []);
    }

    public function save(array $input): array
    {
        $values = $this->normalise($input);

        TelegramSetting::set(self::KEY, $values);

        return $values;
    }

    /**
     * Forget the panel's values: back to .env and the defaults.
     */
    public function reset(): array
    {
        TelegramSetting::query()->where('key', self::KEY)->delete();

        return $this->current();
    }

    public function isSaved(): bool
    {
        return is_array(TelegramSetting::get(self::KEY));
    }

    /**
     * Whether the daily run is due this very minute: the scheduler asks
     * every minute (routes/console.php), so the time and the switch can be
     * changed in the panel without touching cron.
     */
    public function dueNow(): bool
    {
        $values = $this->current();

        return $values['enabled'] && now()->format('H:i') === $values['schedule_at'];
    }

    /**
     * The contact name of the person saved $index-th by an account:
     * "base", "base-1", "base-2" ... An empty base keeps their own name.
     */
    public static function nick(string $base, int $index): string
    {
        return $index <= 0 ? $base : $base . '-' . $index;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{enabled: bool, schedule_at: string, daily_limit: int, skip_usernames: bool, delay_min: int, delay_max: int, nick_base: string, excluded_accounts: list<int>}
     */
    private function normalise(array $input): array
    {
        $values = array_replace($this->defaults(), array_intersect_key($input, $this->defaults()));

        foreach (self::LIMITS as $key => [$min, $max]) {
            $values[$key] = max($min, min($max, (int) $values[$key]));
        }

        $values['delay_max'] = max($values['delay_min'], $values['delay_max']);
        $values['enabled'] = filter_var($values['enabled'], FILTER_VALIDATE_BOOLEAN);
        $values['skip_usernames'] = filter_var($values['skip_usernames'], FILTER_VALIDATE_BOOLEAN);

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $values['schedule_at']) !== 1) {
            $values['schedule_at'] = $this->defaults()['schedule_at'];
        }

        /* One line, no control characters, a sane length for a contact name. */
        $nick = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $values['nick_base']));
        $values['nick_base'] = mb_substr($nick, 0, 60);

        /*
         * Accounts that stay out of the sync. The listener account is always
         * among them: the account pool never hands it to a process other than
         * the listener, so it cannot be let in from here either.
         */
        $values['excluded_accounts'] = collect((array) $values['excluded_accounts'])
            ->merge(self::listenerIds())
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $values;
    }

    /**
     * The Driver Check account (TELEGRAM_DRIVER_CHECK_ACCOUNT_ID), the one
     * the listener runs on.
     *
     * @return list<int>
     */
    public static function listenerIds(): array
    {
        return ListenerAccounts::ids();
    }
}
