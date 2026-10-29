<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSetting;

/**
 * How the group invite runs: how many contacts one call adds, how many
 * such calls an account may make in a day, and the pause between them.
 * Kept in the panel, read fresh by every run.
 */
final class GroupInviteSettings
{
    public const KEY = 'group_invite.settings';

    /**
     * What the panel may set, with the bounds it is held to.
     */
    public const LIMITS = [
        'batch_size' => [1, 100],
        'batches_per_day' => [1, 200],
        'delay_min' => [0, 300],
        'delay_max' => [0, 300],
        'usernames_per_day' => [0, 500],
    ];

    /**
     * @return array{enabled: bool, schedule_at: string, batch_size: int, batches_per_day: int, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, excluded_accounts: list<int>}
     */
    public function defaults(): array
    {
        return [
            'enabled' => true,
            'schedule_at' => '05:00',
            /* One inviteToChannel call. */
            'batch_size' => 20,
            /* How many of those calls each account may make today. */
            'batches_per_day' => 80,
            'delay_min' => 10,
            'delay_max' => 20,
            /*
             * Once an account's own contacts are all tried, it goes on with
             * people of the common base who have a public @username. Each
             * one is looked up first (contacts.resolveUsername), and Telegram
             * holds that call much tighter than an invite, hence its own cap.
             */
            'use_usernames' => true,
            'usernames_per_day' => 100,
            'excluded_accounts' => self::listenerIds(),
        ];
    }

    /**
     * @return array{enabled: bool, schedule_at: string, batch_size: int, batches_per_day: int, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, excluded_accounts: list<int>}
     */
    public function current(): array
    {
        $saved = TelegramSetting::get(self::KEY);

        return $this->normalise(is_array($saved) ? $saved : []);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{enabled: bool, schedule_at: string, batch_size: int, batches_per_day: int, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, excluded_accounts: list<int>}
     */
    public function save(array $input): array
    {
        $values = $this->normalise($input);

        TelegramSetting::set(self::KEY, $values);

        return $values;
    }

    /**
     * @return array{enabled: bool, schedule_at: string, batch_size: int, batches_per_day: int, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, excluded_accounts: list<int>}
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
     * Whether the daily run is due this very minute. The scheduler asks
     * every minute, so the time and the switch live in the panel.
     */
    public function dueNow(): bool
    {
        $values = $this->current();

        return $values['enabled'] && now()->format('H:i') === $values['schedule_at'];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{enabled: bool, schedule_at: string, batch_size: int, batches_per_day: int, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, excluded_accounts: list<int>}
     */
    private function normalise(array $input): array
    {
        $values = array_replace($this->defaults(), array_intersect_key($input, $this->defaults()));

        foreach (self::LIMITS as $key => [$min, $max]) {
            $values[$key] = max($min, min($max, (int) $values[$key]));
        }

        $values['delay_max'] = max($values['delay_min'], $values['delay_max']);
        $values['enabled'] = filter_var($values['enabled'], FILTER_VALIDATE_BOOLEAN);
        $values['use_usernames'] = filter_var($values['use_usernames'], FILTER_VALIDATE_BOOLEAN);

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $values['schedule_at']) !== 1) {
            $values['schedule_at'] = $this->defaults()['schedule_at'];
        }

        /*
         * The listener account is always out: its session is already open,
         * and a second process cannot open it.
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
     * @return list<int>
     */
    public static function listenerIds(): array
    {
        return ListenerAccounts::ids();
    }
}
