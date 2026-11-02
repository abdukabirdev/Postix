<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\JoinRequestService;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * Our bot approves the requests to join our groups. Started by the
 * scheduler every minute while it is switched on in the panel; each start
 * asks Telegram for a minute and ends, so it is back by itself after a
 * reboot or a crash.
 */
final class TelegramJoinRequestsCommand extends Command
{
    public const LOCK_NAME = 'join-requests';

    protected $signature = 'telegram:join-requests
        {--seconds=55 : How long to keep asking Telegram before ending}';

    protected $description = 'Approve the requests to join our groups with our own bot and write who asked into the base';

    public function handle(JoinRequestService $requests): int
    {
        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            $this->warn('The join request bot is already being asked by another process.');

            return self::SUCCESS;
        }

        try {
            $totals = $requests->poll(
                max(5, min(3600, (int) $this->option('seconds'))),
                fn (string $line) => $this->line(now()->format('H:i:s') . ' ' . $line),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        if (array_sum($totals) > 0) {
            $this->info(sprintf(
                'Approved: %d, not approved: %d, our accounts: %d, new in the base: %d',
                $totals['approved'],
                $totals['failed'],
                $totals['ours'],
                $totals['new'],
            ));
        }

        return self::SUCCESS;
    }
}
