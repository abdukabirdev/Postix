<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramReadRequest;
use Illuminate\Support\Facades\Cache;

/**
 * The web side of the chat reader: a read is left as a row, the process that
 * holds the account's session does it (ChatReader::serve()), the API waits a
 * few seconds for the answer and, when Telegram is slower, hands back the id
 * to ask again with.
 */
final class ChatReadQueue
{
    /** Nobody took a request in this long: no process holds the account (or it has not been restarted since the reader came). */
    public const STALE_PENDING_SECONDS = 120;

    /** A request running longer than this is given up: its process died with it. */
    public const STALE_RUNNING_SECONDS = 300;

    /** What was read is chat content: it is not kept longer than this. */
    public const KEEP_RESULT_MINUTES = 60;

    /** The audit trail: who asked what of which account. */
    public const KEEP_ROWS_DAYS = 14;

    /**
     * @param array<string, mixed> $params
     */
    public function ask(int $accountId, string $op, array $params): TelegramReadRequest
    {
        $this->prune();

        return TelegramReadRequest::query()->create([
            'telegram_account_id' => $accountId,
            'op' => $op,
            'params' => $params,
            'status' => TelegramReadRequest::STATUS_PENDING,
        ]);
    }

    /**
     * The request as it is now, waiting up to $seconds for it to finish.
     */
    public function wait(TelegramReadRequest $request, float $seconds): TelegramReadRequest
    {
        $until = microtime(true) + max(0.0, $seconds);

        while (true) {
            $request = $this->fresh($request);

            if ($request->isFinished() || microtime(true) >= $until) {
                return $request;
            }

            usleep(250_000);
        }
    }

    /**
     * Reloaded, and failed when nobody has picked it up for too long.
     */
    public function fresh(TelegramReadRequest $request): TelegramReadRequest
    {
        $request = $request->fresh() ?? $request;

        if ($request->status === TelegramReadRequest::STATUS_PENDING && $request->created_at->lt(now()->subSeconds(self::STALE_PENDING_SECONDS))) {
            $this->fail($request, 'Nobody picked the request up: no running process holds this account (or it was not restarted after the reader was deployed).');
        }

        if ($request->status === TelegramReadRequest::STATUS_RUNNING && ($request->started_at ?? $request->created_at)->lt(now()->subSeconds(self::STALE_RUNNING_SECONDS))) {
            $this->fail($request, 'The request ran too long and was given up.');
        }

        return $request;
    }

    /**
     * Chat content is not kept; the audit rows go after two weeks. At most
     * once a minute.
     */
    public function prune(): void
    {
        if (! Cache::add('chat_reader:pruned', 1, 60)) {
            return;
        }

        TelegramReadRequest::query()
            ->whereNotNull('result')
            ->where('finished_at', '<', now()->subMinutes(self::KEEP_RESULT_MINUTES))
            ->update(['result' => null]);

        TelegramReadRequest::query()
            ->where('created_at', '<', now()->subDays(self::KEEP_ROWS_DAYS))
            ->delete();
    }

    private function fail(TelegramReadRequest $request, string $error): void
    {
        $updated = TelegramReadRequest::query()
            ->whereKey($request->id)
            ->whereIn('status', [TelegramReadRequest::STATUS_PENDING, TelegramReadRequest::STATUS_RUNNING])
            ->update([
                'status' => TelegramReadRequest::STATUS_FAILED,
                'error' => $error,
                'finished_at' => now(),
            ]);

        if ($updated > 0) {
            $request->forceFill(['status' => TelegramReadRequest::STATUS_FAILED, 'error' => $error, 'finished_at' => now()])->syncOriginal();
        }
    }
}
