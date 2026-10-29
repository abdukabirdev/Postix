<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use danog\MadelineProto\API;
use danog\MadelineProto\Ipc\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Putting a session to sleep once the work with it is done.
 *
 * Outside an event handler, new API() on Linux does not hold the session
 * itself: MadelineProto starts a "MadelineProto worker <session>" process
 * that does, and talks to it over IPC. That worker has no idle timeout - it
 * outlives the command and keeps reading the updates of every group the
 * account is in, for weeks. $api->stop() does not end it: it stops an event
 * handler's loop and throws when there is none.
 *
 * Ending it is safe: the worker saves the session on the way out, and the
 * next new API() starts a fresh one in a few seconds. A process still
 * talking to the same worker reconnects by itself (its client starts the
 * worker again).
 *
 * A session served by an event handler - a listener, driver check - is left
 * alone: that is a process the watchdog keeps running, not a worker.
 */
final class SessionSleep
{
    /**
     * Never throws: it is called on the way out, often of an error, and
     * must not hide it.
     *
     * @param array<string, mixed> $context
     */
    public static function put(?API $api, array $context = []): void
    {
        if ($api === null) {
            return;
        }

        try {
            /* The IPC client sits in a protected property; MadelineProto does the same to end its worker. */
            $client = (fn () => $this->wrapper->getAPI())->call($api);

            if (! $client instanceof Client) {
                /* The session lives in this very process (Windows, tests): it ends with it. */
                return;
            }

            if ($api->hasEventHandler()) {
                return;
            }

            $client->stopIpcServer();
        } catch (Throwable $e) {
            Log::warning('MadelineProto session did not go to sleep', [
                ...$context,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
