<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

/**
 * What the model answered: the parsed JSON (null when there was none), why
 * it stopped and what it cost in tokens.
 */
final class AiReply
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly ?array $data,
        public readonly string $stopReason,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly ?string $refusal = null,
    ) {}
}
