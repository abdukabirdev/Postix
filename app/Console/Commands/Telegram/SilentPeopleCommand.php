<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\SilentPeople;
use Illuminate\Console\Command;

/**
 * Lists the people the penalties never get an answer from (SilentPeople).
 * With --mute their private messages are switched off (dm_enabled), the
 * same switch as on their card; they stay in the list and the panel turns
 * them back on.
 */
final class SilentPeopleCommand extends Command
{
    protected $signature = 'telegram:silent-people
        {--days= : Look back this many days (config client_checks.silent_people.days)}
        {--min= : At least this many penalties forwarded (config client_checks.silent_people.min_penalties)}
        {--mute : Switch off private messages to them}';

    protected $description = 'List people who never answer the penalties; --mute stops writing to them';

    public function handle(SilentPeople $silent): int
    {
        $rows = $silent->find(
            $this->option('days') !== null ? (int) $this->option('days') : null,
            $this->option('min') !== null ? (int) $this->option('min') : null,
        );

        if ($rows->isEmpty()) {
            $this->info('Nobody: everyone answered at least once, or there are too few penalties.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Role', 'Penalties', 'Private messages on'],
            $rows->map(fn (array $row): array => [
                $row['person']->id,
                $row['person']->name,
                $row['person']->roleOrDefault(),
                $row['penalties'],
                $row['person']->dm_enabled ? 'yes' : 'no',
            ])->all(),
        );

        if ($this->option('mute')) {
            $muted = 0;

            foreach ($rows as $row) {
                if ($row['person']->dm_enabled) {
                    $row['person']->update(['dm_enabled' => false]);
                    $muted++;
                }
            }

            $this->info("Switched off: {$muted}");
        }

        return self::SUCCESS;
    }
}
