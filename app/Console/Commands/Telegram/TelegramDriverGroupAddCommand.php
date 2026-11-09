<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\DriverGroupService;
use App\Application\Telegram\Services\DriverGroupSettings;
use App\Application\Telegram\Support\SignalStop;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Throwable;

/**
 * Drivers into the group picked on the page (Group invites -> Drivers into
 * a group). The scheduler asks every minute: the drivers checked meanwhile
 * go into the queue, and a run starts when somebody may add now. The
 * panel's buttons start it at once.
 */
final class TelegramDriverGroupAddCommand extends Command
{
    /** Held for as long as a run lasts: the panel reads it to say "running", a second run is refused. */
    public const LOCK_NAME = 'driver-group-add';

    protected $signature = 'telegram:driver-group-add
        {--trigger=manual : Who started it: manual or schedule}';

    protected $description = 'Add the drivers found in Telegram into the group picked in the panel';

    public function handle(DriverGroupService $drivers, DriverGroupSettings $settings): int
    {
        EventLoop::setErrorHandler(static function (Throwable $e): void {
            if (SignalStop::handle($e, fn () => DriverGroupService::requestStop(null))) {
                return;
            }

            Log::error('Driver group: an exception in the event loop', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        });

        $trigger = $this->option('trigger') === 'schedule' ? 'schedule' : 'manual';

        /* The drivers checked since the last minute: queued even while a run goes on, it picks them up. */
        $drivers->queueResolved();

        /* Stopped from the panel: only a start button starts it again. */
        if ($trigger === 'schedule' && ($settings->paused() || ! $drivers->hasPending())) {
            return self::SUCCESS;
        }

        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            if ($trigger === 'manual') {
                $this->warn('Another driver group run is already running.');
            }

            return self::SUCCESS;
        }

        try {
            $totals = $drivers->run(fn (string $line) => $this->line($line), $trigger);
        } catch (Throwable $e) {
            Log::error('Driver group: the run failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        if ($totals['accounts'] > 0 || $trigger === 'manual') {
            $this->info(sprintf(
                'Accounts: %d, added: %d, already in: %d, not added: %d, refused: %d, unchecked: %d, found again by another account: %d',
                $totals['accounts'],
                $totals['invited'],
                $totals['already'],
                $totals['not_added'],
                $totals['failed'],
                $totals['sent'],
                $totals['re_resolved'],
            ));
        }

        return self::SUCCESS;
    }
}
