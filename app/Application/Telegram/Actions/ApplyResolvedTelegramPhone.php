<?php

declare(strict_types=1);

namespace App\Application\Telegram\Actions;

use App\Application\Telegram\Ai\DriverNameJudge;
use App\Application\Telegram\Services\TelegramNameMatcher;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Jobs\Telegram\JudgeDriverNameJob;
use App\Models\Driver\TelegramDriverCheck;
use App\Models\Telegram\TelegramResolvedPhone;

final class ApplyResolvedTelegramPhone
{
    public function __construct(
        private readonly TelegramNameMatcher $nameMatcher,
        private readonly DriverNameJudge $aiJudge,
    ) {
    }

    public function execute(
        TelegramDriverCheck $check,
        TelegramResolvedPhone $resolvedPhone,
    ): void {
        $telegramRaw
            = $resolvedPhone->telegram_raw ?? [];

        if (! is_array($telegramRaw)) {
            $telegramRaw = [];
        }

        $telegramRaw['resolved_from_cache'] = true;

        $telegramRaw['resolved_phone_id']
            = $resolvedPhone->id;

        /*
         * Username included: it is often the only field spelling the
         * name in Latin letters, and the cached path must reach the
         * same verdict as the live one.
         */
        $match = $this->nameMatcher->match(
            $check->driver_name,
            $resolvedPhone->telegram_first_name,
            $resolvedPhone->telegram_last_name,
            $resolvedPhone->telegram_username,
        );

        $telegramRaw['name_match'] = $match;

        /*
         * The code found the names different: the AI decides. This path
         * runs inside a Telegram process, so the call goes to the queue
         * (JudgeDriverNameJob) and the check waits in "processing".
         */
        $waitsForAi = $this->aiJudge->wanted($match);

        $matched = (bool) (
            $match['matched'] ?? false
        );

        $status = $waitsForAi
            ? TelegramDriverCheckStatus::Processing
            : ($matched
                ? TelegramDriverCheckStatus::Confirmed
                : TelegramDriverCheckStatus::NotConfirmed);

        $check->update([
            'telegram_resolved_phone_id'
                => $resolvedPhone->id,

            'telegram_user_id'
                => $resolvedPhone->telegram_user_id,

            'telegram_username'
                => $resolvedPhone->telegram_username,

            'telegram_first_name'
                => $resolvedPhone->telegram_first_name,

            'telegram_last_name'
                => $resolvedPhone->telegram_last_name,

            'telegram_raw'
                => $telegramRaw,

            'status'
                => $status,

            'error_message'
                => null,

            'checked_at'
                => now(),
        ]);

        if ($waitsForAi) {
            JudgeDriverNameJob::dispatch($check->id)->onQueue('telegram')->afterCommit();

            return;
        }

        /*
         * Current driver state.
         */
        if ($check->driver) {
            $check->driver->update([
                'status' => $matched
                    ? 'confirmed'
                    : 'not_confirmed',
            ]);
        }
    }
}
