<?php

declare(strict_types=1);

namespace App\Application\Telegram\Actions;

use App\Application\Telegram\Services\RequestCancellationAsker;
use App\Application\Telegram\Services\RequestCancellationParser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramRequestCancellation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * "❌ Запрос отменён (EGS21300)" from a watched chat.
 *
 * Recorded, and tied to the request's last penalty that reached somebody:
 * that person is the one asked why. The question itself goes from the
 * account that delivers their penalties, on its own tick
 * ({@see RequestCancellationAsker::flush()}) - the account reading the
 * group may not be that one.
 */
final class ProcessRequestCancellation
{
    public function __construct(
        private readonly RequestCancellationParser $parser,
    ) {
    }

    public function execute(int $chatId, int $messageId, string $text): ?TelegramRequestCancellation
    {
        if ($chatId === 0 || $messageId <= 0) {
            return null;
        }

        $parsed = $this->parser->parse($text);

        $request = $parsed['request_number'];

        if ($request === null) {
            return null;
        }

        $exists = TelegramRequestCancellation::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $messageId)
            ->exists();

        if ($exists) {
            return null;
        }

        $penalty = TelegramClientCheck::query()
            ->where('request_number', $request)
            ->whereNotNull('forwarded_at')
            ->whereNotNull('peer')
            ->whereNotNull('operation_user_id')
            ->orderByDesc('forwarded_at')
            ->orderByDesc('id')
            ->first();

        try {
            $cancellation = TelegramRequestCancellation::query()->create([
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => $messageId,
                'message_text' => $text,
                'parsed' => $parsed,
                'request_number' => $request,
                'telegram_client_check_id' => $penalty?->id,
                'operation_user_id' => $penalty?->operation_user_id,
                'status' => $penalty === null
                    ? TelegramRequestCancellation::STATUS_SKIPPED
                    : TelegramRequestCancellation::STATUS_PENDING,
                'reason' => $penalty === null ? TelegramRequestCancellation::REASON_NO_PENALTY : null,
                'peer' => $penalty?->peer,
                'attempts' => 0,
            ]);
        } catch (QueryException $e) {
            /* The unique index caught a duplicate the check above missed. */
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }

        Log::info('Request cancellation: recorded', [
            'cancellation_id' => $cancellation->id,
            'request_number' => $request,
            'penalty_id' => $penalty?->id,
            'operation_user_id' => $penalty?->operation_user_id,
            'status' => $cancellation->status,
        ]);

        return $cancellation;
    }
}
