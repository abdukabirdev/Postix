<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\GroupInviteService;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Throwable;

final class TelegramGroupInviteCommand extends Command
{
    /**
     * Held for as long as a run lasts: the panel reads it to say "running",
     * and a second run is refused.
     */
    public const LOCK_NAME = 'group-invite';

    protected $signature = 'telegram:group-invites {--trigger=manual : Who started it: manual or schedule}';

    protected $description = 'Invite the accounts\' contacts into the chosen groups';

    public function handle(GroupInviteService $invites): int
    {
        EventLoop::setErrorHandler(static function (Throwable $e): void {
            Log::error('Group invite: an exception in the event loop', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        });

        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            $this->warn('Another group invite is already running.');

            return self::SUCCESS;
        }

        try {
            $trigger = $this->option('trigger') === 'schedule' ? 'schedule' : 'manual';

            $totals = $invites->run(fn (string $line) => $this->line($line), $trigger);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        $this->info(sprintf(
            'Accounts: %d, batches: %d, invited: %d, failed: %d',
            $totals['accounts'],
            $totals['batches'],
            $totals['invited'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
