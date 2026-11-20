<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Application\Telegram\Ai\DriverNameJudge;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Models\Driver\TelegramDriverCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The AI's look at a driver check resolved from the cache.
 *
 * The cached path runs inside a Telegram process (or a webhook request),
 * where a call of seconds would stall everything; so the check waits in
 * "processing" with the code's result in name_match, and this job (the
 * telegram queue) asks Claude and writes the final verdict. The listener's
 * report cron then posts it like any other.
 *
 * Whatever goes wrong, the check is finished with the code's verdict -
 * it never stays in "processing".
 */
class JudgeDriverNameJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly int $checkId,
    ) {}

    public function handle(DriverNameJudge $judge): void
    {
        $check = TelegramDriverCheck::query()->find($this->checkId);

        if (! $check instanceof TelegramDriverCheck || $check->status !== TelegramDriverCheckStatus::Processing) {
            return;
        }

        $match = data_get($check->telegram_raw, 'name_match');
        $match = is_array($match) ? $match : [];

        try {
            $match = $judge->judge(
                $check->id,
                $match,
                $check->telegram_first_name,
                $check->telegram_last_name,
                $check->telegram_username,
            );
        } catch (Throwable $e) {
            Log::warning('AI driver verdict failed, the code decides', ['check_id' => $check->id, 'error' => $e->getMessage()]);
        }

        $judge->finish($check, $match);
    }

    public function failed(?Throwable $e): void
    {
        $check = TelegramDriverCheck::query()->find($this->checkId);

        if ($check instanceof TelegramDriverCheck && $check->status === TelegramDriverCheckStatus::Processing) {
            $match = data_get($check->telegram_raw, 'name_match');

            app(DriverNameJudge::class)->finish($check, is_array($match) ? $match : []);
        }
    }
}
