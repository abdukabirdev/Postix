<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use App\Models\Telegram\TelegramAccount;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Whether an account's session starts and answers, asked in a process of
 * its own (telegram:session-probe) before a run starts it in its own.
 *
 * A session can hang on its start for good: MadelineProto's IPC client
 * waits for a worker that never answers, with no time limit of its own. In
 * the run's process that stops the whole run where it stands (the group
 * invites stood 9 hours on one account on 05.11). Here the wait has a
 * limit: the probe is killed, and so is the session's MadelineProto worker,
 * which is what hangs; the next start of the session begins anew.
 *
 * A probe that answered leaves the session's worker awake: the run's own
 * start then finds it ready.
 */
class SessionProbe
{
    /** The session answered. */
    public const OK = 'ok';

    /** It said no in time: the error is in the answer. */
    public const FAILED = 'failed';

    /** No answer in the time given: the session's worker is killed. */
    public const HUNG = 'hung';

    /** Stopped from the panel while it waited. */
    public const STOPPED = 'stopped';

    /** Not asked (not Linux) or the probe itself broke: the caller starts the session the old way. */
    public const SKIPPED = 'skipped';

    /** The probe's last line: its answer, as JSON. */
    public const MARK = '@@session ';

    /** How often the probe is looked at. Microseconds. */
    private const POLL_EVERY = 250_000;

    /**
     * @param callable(): bool $stopping whether the run was asked to stop
     *
     * @return array{status: string, error: string|null}
     */
    public function check(TelegramAccount $account, int $seconds, callable $stopping): array
    {
        /* Elsewhere (Windows, tests) the session lives in the very process that starts it: nothing to wait for apart. */
        if (PHP_OS_FAMILY !== 'Linux') {
            return self::answer(self::SKIPPED);
        }

        $process = new Process([PHP_BINARY, base_path('artisan'), 'telegram:session-probe', (string) $account->id], base_path());
        $process->setTimeout($seconds);

        try {
            $process->start();
        } catch (Throwable $e) {
            Log::warning('Session probe could not run', ['account_id' => $account->id, 'message' => $e->getMessage()]);

            return self::answer(self::SKIPPED);
        }

        try {
            while ($process->isRunning()) {
                /* Kills a probe past its time. */
                $process->checkTimeout();

                if ($stopping()) {
                    $process->stop(0);

                    return self::answer(self::STOPPED);
                }

                usleep(self::POLL_EVERY);
            }
        } catch (ProcessTimedOutException) {
            SessionSleep::kill((string) $account->session_path, ['account_id' => $account->id]);

            return self::answer(self::HUNG, "No answer from the session for {$seconds} s");
        }

        $said = self::said($process->getOutput());

        if ($said === null) {
            Log::warning('Session probe ended without an answer', [
                'account_id' => $account->id,
                'exit' => $process->getExitCode(),
                'error' => mb_substr($process->getErrorOutput(), -500),
            ]);

            return self::answer(self::SKIPPED);
        }

        return $said['ok']
            ? self::answer(self::OK)
            : self::answer(self::FAILED, $said['error'] !== '' ? $said['error'] : 'Failed to start MadelineProto.');
    }

    /**
     * The line the probe ends with.
     */
    public static function line(bool $ok, ?string $error = null): string
    {
        return self::MARK . json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The probe's answer: its last MARK line.
     *
     * @return array{ok: bool, error: string}|null
     */
    public static function said(string $output): ?array
    {
        foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = rtrim($line, "\r");

            if (! str_starts_with($line, self::MARK)) {
                continue;
            }

            $said = json_decode(substr($line, strlen(self::MARK)), true);

            return is_array($said)
                ? ['ok' => (bool) ($said['ok'] ?? false), 'error' => (string) ($said['error'] ?? '')]
                : null;
        }

        return null;
    }

    /**
     * @return array{status: string, error: string|null}
     */
    private static function answer(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'error' => $error];
    }
}
