<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSetting;
use Illuminate\Support\Carbon;

/**
 * How the group invite runs: when (a few times a day at equal steps), how
 * many contacts one call adds, how many such calls an account may make in
 * a day, and the pause between them. Kept in the panel, read fresh by
 * every run.
 */
final class GroupInviteSettings
{
    public const KEY = 'group_invite.settings';

    /**
     * What the panel may set, with the bounds it is held to.
     */
    public const LIMITS = [
        'runs_per_day' => [1, 24],
        'batch_size' => [1, 100],
        'batches_per_day' => [1, 200],
        'delay_min' => [0, 300],
        'delay_max' => [0, 300],
        'usernames_per_day' => [0, 500],
    ];

    /** Everybody not refused yet, those added before first; or only those added before. */
    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_PROVEN = 'proven';

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_PROVEN];

    /** A new account's share of the daily calls on its 1st, 2nd, 3rd, 4th day of inviting; the whole after. */
    public const WARM_UP = [0.5, 0.6, 0.7, 0.85];

    /**
     * @return array{enabled: bool, schedule_at: string, runs_per_day: int, batch_size: int, batches_per_day: int, warm_up: bool, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, audience: string, excluded_accounts: list<int>}
     */
    public function defaults(): array
    {
        return [
            'enabled' => true,
            /* The first run of the day; the others follow at equal steps: 05:00, 11:00, 17:00, 23:00. */
            'schedule_at' => '05:00',
            'runs_per_day' => 4,
            /* One inviteToChannel call. */
            'batch_size' => 20,
            /*
             * How many of those calls each account may make today. A
             * scheduled run takes its share of them (a quarter at four runs
             * a day), the rest is for the runs after it.
             */
            'batches_per_day' => 80,
            /*
             * A new account is not driven to the limit from its first day:
             * WARM_UP of the daily calls on its first days of inviting,
             * the whole from the fifth.
             */
            'warm_up' => true,
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
            /*
             * Who is invited: all: people already added somewhere first,
             * then the ones never tried; proven: only people already added
             * into some group - a new group fills with sure ones, nobody
             * unknown is spent on. People Telegram refused never are.
             */
            'audience' => self::AUDIENCE_ALL,
            'excluded_accounts' => self::listenerIds(),
        ];
    }

    /**
     * @return array{enabled: bool, schedule_at: string, runs_per_day: int, batch_size: int, batches_per_day: int, warm_up: bool, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, audience: string, excluded_accounts: list<int>}
     */
    public function current(): array
    {
        $saved = TelegramSetting::get(self::KEY);

        return $this->normalise(is_array($saved) ? $saved : []);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{enabled: bool, schedule_at: string, runs_per_day: int, batch_size: int, batches_per_day: int, warm_up: bool, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, audience: string, excluded_accounts: list<int>}
     */
    public function save(array $input): array
    {
        $values = $this->normalise($input);

        TelegramSetting::set(self::KEY, $values);

        return $values;
    }

    /**
     * @return array{enabled: bool, schedule_at: string, runs_per_day: int, batch_size: int, batches_per_day: int, warm_up: bool, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, audience: string, excluded_accounts: list<int>}
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
     * Whether a run is due this very minute. The scheduler asks every
     * minute, so the times and the switch live in the panel.
     */
    public function dueNow(): bool
    {
        $values = $this->current();

        return $values['enabled'] && in_array(now()->format('H:i'), self::times($values), true);
    }

    /**
     * The day's run times: the first one, then every 24 h / runs_per_day.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string> H:i, the earliest first
     */
    public static function times(array $values): array
    {
        [$hours, $minutes] = array_map('intval', explode(':', (string) $values['schedule_at']) + [0, 0]);
        $runs = max(1, (int) $values['runs_per_day']);
        $first = $hours * 60 + $minutes;

        $times = [];

        for ($run = 0; $run < $runs; $run++) {
            $minute = ($first + intdiv($run * 1440, $runs)) % 1440;
            $times[] = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        }

        sort($times);

        return array_values(array_unique($times));
    }

    /**
     * When the next scheduled run starts; null while the schedule is off.
     */
    public function nextRun(): ?Carbon
    {
        $values = $this->current();

        if (! $values['enabled']) {
            return null;
        }

        foreach ([now()->startOfDay(), now()->addDay()->startOfDay()] as $day) {
            foreach (self::times($values) as $time) {
                [$hours, $minutes] = array_map('intval', explode(':', $time));
                $at = $day->copy()->setTime($hours, $minutes);

                if ($at->gt(now())) {
                    return $at;
                }
            }
        }

        return null;
    }

    /**
     * The calls of an account's daily cap one scheduled run may make: the
     * cap spread over the day's runs, rounded up.
     *
     * @param array<string, mixed> $values
     */
    public static function runShare(int $dailyCap, array $values): int
    {
        return max(1, (int) ceil($dailyCap / max(1, (int) $values['runs_per_day'])));
    }

    /**
     * The share of the daily calls an account makes on its $day-th day of
     * inviting (1: the first): 50%, 60%, 70%, 85%, then all of them.
     *
     * @param array<string, mixed> $values
     */
    public static function warmShare(int $day, array $values): float
    {
        return ! empty($values['warm_up']) ? (self::WARM_UP[max(1, $day) - 1] ?? 1.0) : 1.0;
    }

    /**
     * The panel's daily calls, for an account on its $day-th day of inviting.
     *
     * @param array<string, mixed> $values
     */
    public static function warmCap(int $day, array $values): int
    {
        $limit = max(1, (int) $values['batches_per_day']);

        return max(1, min($limit, (int) round($limit * self::warmShare($day, $values))));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{enabled: bool, schedule_at: string, runs_per_day: int, batch_size: int, batches_per_day: int, warm_up: bool, delay_min: int, delay_max: int, use_usernames: bool, usernames_per_day: int, audience: string, excluded_accounts: list<int>}
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
        $values['warm_up'] = filter_var($values['warm_up'], FILTER_VALIDATE_BOOLEAN);

        if (! in_array($values['audience'], self::AUDIENCES, true)) {
            $values['audience'] = self::AUDIENCE_ALL;
        }

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
