<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PaymentRequiredException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Models\Telegram\TelegramAiCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Claude one question and writes the call down.
 *
 * Never throws: every failure comes back as an AiAnswer without data, and
 * the caller goes on with the free rules. Money and key trouble (no
 * credit, spend limit, a wrong or revoked key) also pause the AI for a
 * while (AiSettings::pause), so the next messages do not wait on a call
 * bound to fail.
 *
 * Blocks for the length of the call: never call it from the event loop of
 * a Telegram process - from a queue job or a CLI process only.
 */
final class AiClient
{
    public function __construct(
        private readonly AiModel $model,
        private readonly AiSettings $settings,
    ) {}

    /**
     * @param list<string>         $system
     * @param array<string, mixed> $schema
     */
    public function ask(
        string $area,
        ?string $kind,
        string $mode,
        array $system,
        string $user,
        array $schema,
        ?int $personId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $freeText = null,
    ): AiAnswer {
        $blocker = $this->settings->blocker();

        if ($blocker !== null) {
            return new AiAnswer(null, 'blocked:' . $blocker, null);
        }

        $started = microtime(true);
        $reply = null;
        $error = null;
        $status = TelegramAiCall::STATUS_OK;

        try {
            $reply = $this->model->complete($system, $user, $schema);

            if ($reply->stopReason === 'refusal') {
                $status = TelegramAiCall::STATUS_REFUSED;
                $error = 'Claude отказался: ' . ($reply->refusal ?: 'без объяснения');
            } elseif ($reply->data === null) {
                $status = TelegramAiCall::STATUS_FAILED;
                $error = 'Нет JSON в ответе (stop: ' . $reply->stopReason . ')';
            }
        } catch (Throwable $e) {
            $status = TelegramAiCall::STATUS_FAILED;
            $error = mb_substr($e::class . ': ' . $e->getMessage(), 0, 2000);

            $pause = $this->pauseReason($e);

            if ($pause !== null) {
                $this->settings->pause($pause);
            }

            Log::warning('AI call failed', ['area' => $area, 'kind' => $kind, 'error' => $error]);
        }

        $call = $this->record([
            'area' => $area,
            'kind' => $kind,
            'mode' => $mode,
            'status' => $status,
            'operation_user_id' => $personId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'request' => mb_substr($user, 0, 20000),
            'answer' => $reply?->data,
            'free_text' => $freeText,
            'error' => $error,
            'input_tokens' => $reply?->inputTokens ?? 0,
            'output_tokens' => $reply?->outputTokens ?? 0,
            'cache_read_tokens' => $reply?->cacheReadTokens ?? 0,
            'cache_write_tokens' => $reply?->cacheWriteTokens ?? 0,
            'cost_usd' => $reply !== null ? $this->cost($reply) : 0,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return new AiAnswer(
            $status === TelegramAiCall::STATUS_OK ? $reply->data : null,
            $error,
            $call,
        );
    }

    public function cost(AiReply $reply): float
    {
        $prices = (array) config('ai.prices', []);

        return round((
            $reply->inputTokens * (float) ($prices['input'] ?? 0.10)
            + $reply->outputTokens * (float) ($prices['output'] ?? 0.50)
            + $reply->cacheReadTokens * (float) ($prices['cache_read'] ?? 0.01)
            + $reply->cacheWriteTokens * (float) ($prices['cache_write'] ?? 0.125)
        ) / 1_000_000, 6);
    }

    /**
     * Why the AI has to stop for a while, or null when it was a passing
     * failure (timeout, overload, a bad answer) the next call may not have.
     */
    private function pauseReason(Throwable $e): ?string
    {
        $message = mb_strtolower($e->getMessage());

        if ($e instanceof AuthenticationException || $e instanceof PermissionDeniedException) {
            return 'Ключ API не принят: ' . $e->getMessage();
        }

        if ($e instanceof PaymentRequiredException
            || str_contains($e::class, 'SpendLimit')
            || str_contains($e::class, 'Billing')
            || str_contains($message, 'credit balance')
            || str_contains($message, 'spend limit')
            || str_contains($message, 'billing')
        ) {
            return 'Закончились деньги или достигнут лимит расходов: ' . $e->getMessage();
        }

        if ($e instanceof APIStatusException && in_array($e->type?->value ?? null, ['billing_error', 'authentication_error', 'permission_error'], true)) {
            return 'Ошибка доступа к API (' . $e->type->value . '): ' . $e->getMessage();
        }

        if (str_contains($message, 'anthropic_api_key is not set')) {
            return 'Нет ключа ANTHROPIC_API_KEY в .env';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row): ?TelegramAiCall
    {
        try {
            return TelegramAiCall::query()->create($row);
        } catch (Throwable $e) {
            Log::warning('AI call could not be written down', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
