<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

/**
 * The model behind AiClient: one request, one JSON answer. The real one is
 * AnthropicAiModel; tests put a fake in its place.
 *
 * Throws the SDK's own exceptions on API trouble - AiClient tells money and
 * key trouble from a passing failure by them.
 */
interface AiModel
{
    /**
     * @param list<string>          $system  the system prompt in parts; the last part is cached
     * @param array<string, mixed>  $schema  the JSON schema of the answer
     */
    public function complete(array $system, string $user, array $schema): AiReply;
}
