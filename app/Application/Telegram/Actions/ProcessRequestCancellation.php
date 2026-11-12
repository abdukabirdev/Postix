<?php

declare(strict_types=1);

namespace App\Application\Telegram\Actions;

use App\Application\Telegram\Services\RequestCancellationAsker;
use App\Application\Telegram\Services\RequestCancellationParser;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramRequestCancellation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * "❌ Запрос отменён (EGS21300)" from a watched chat.
 *
 * Recorded, and the one asked why is always the operator: the person of the
 * "Operation:" line - whoever cancelled the request, sales included. When
 * that operator got a penalty for the request, the question replies to the
 * last of them; with none, it is a plain message. The question itself goes
 * from the account that delivers their penalties, on its own tick
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

        $operator = $this->operator($parsed['operation_name']);

        /* Their own last penalty for the request: what the question replies to. */
        $penalty = $operator === null ? null : TelegramClientCheck::query()
            ->where('request_number', $request)
            ->where('operation_user_id', $operator->id)
            ->whereNotNull('forwarded_at')
            ->whereNotNull('peer')
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
                'operation_user_id' => $operator?->id,
                'status' => $operator === null
                    ? TelegramRequestCancellation::STATUS_SKIPPED
                    : TelegramRequestCancellation::STATUS_PENDING,
                'reason' => $operator === null ? TelegramRequestCancellation::REASON_NO_OPERATOR : null,
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
            'operation_user_id' => $operator?->id,
            'status' => $cancellation->status,
        ]);

        return $cancellation;
    }

    /**
     * The person of the "Operation:" line, when we know them and can write to
     * them. Never created from here: a name with no Telegram behind it is
     * nobody to ask.
     */
    private function operator(?string $name): ?OperationUser
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $person = OperationUser::query()
            ->where('name_normalized', OperationUser::normalizeName($name))
            ->first();

        return $person instanceof OperationUser && $person->hasTelegramPeer() ? $person : null;
    }
}
