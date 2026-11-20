<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use App\Models\Telegram\TelegramAiCall;

/**
 * The outcome of one AiClient::ask(): the JSON answer when there is one,
 * else why not; the journal row either way (null only when the AI was not
 * asked at all).
 */
final class AiAnswer
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly ?array $data,
        public readonly ?string $error,
        public readonly ?TelegramAiCall $call,
    ) {}

    public function ok(): bool
    {
        return $this->data !== null;
    }
}
