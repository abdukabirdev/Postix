<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use Amp\SignalException;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Throwable;

/**
 * `kill` for a process that runs MadelineProto.
 *
 * MadelineProto turns SIGTERM and SIGINT into an Amp\SignalException thrown
 * into the event loop. The commands' error handlers are there so that one
 * failed callback does not end a whole run - and they logged the signal
 * and went on: "SIGTERM received" in the log, the process alive, only
 * kill -9 ended it.
 *
 * Now the signal asks the run to stop the way the panel's Stop button does
 * (the step in hand is finished, the account given back), and a process
 * still alive GRACE seconds later - stuck on a session - ends hard.
 */
final class SignalStop
{
    /** Seconds a signalled process has to end by itself. */
    public const GRACE = 30;

    private static bool $asked = false;

    /**
     * For an event loop error handler: whether $e was the signal (then it
     * is taken care of here).
     *
     * @param callable(): void $stop asks the run to stop
     */
    public static function handle(Throwable $e, callable $stop): bool
    {
        if (! $e instanceof SignalException) {
            return false;
        }

        /* A second kill while it is on its way out. */
        if (self::$asked) {
            return true;
        }

        self::$asked = true;

        Log::warning('Stopping on a signal', ['message' => $e->getMessage(), 'pid' => getmypid()]);

        try {
            $stop();
        } catch (Throwable) {
            // the hard end below still comes
        }

        /* Unreferenced: a process that ends by itself does not wait for it. */
        EventLoop::unreference(EventLoop::delay(self::GRACE, static function (): void {
            Log::warning('No end ' . self::GRACE . ' s after the signal: the process ends hard', ['pid' => getmypid()]);

            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), 9);
            }

            exit(1);
        }));

        return true;
    }
}
