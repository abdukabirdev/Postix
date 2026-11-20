<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use Anthropic\Client;
use GuzzleHttp\Client as Guzzle;
use RuntimeException;

/**
 * Claude through the official SDK: Haiku 5.5, low effort, the answer
 * constrained to the given JSON schema, the system prompt cached.
 *
 * The SDK leaves the timeout to the HTTP client, so a Guzzle client with
 * our own timeout is handed to it: a slow API must not hold the caller
 * longer than config('ai.timeout').
 */
final class AnthropicAiModel implements AiModel
{
    private ?Client $client = null;

    public function complete(array $system, string $user, array $schema): AiReply
    {
        $blocks = [];
        $last = count($system) - 1;

        foreach (array_values($system) as $i => $text) {
            $block = ['type' => 'text', 'text' => $text];

            if ($i === $last) {
                $block['cacheControl'] = ['type' => 'ephemeral'];
            }

            $blocks[] = $block;
        }

        $message = $this->client()->messages->create(
            model: (string) config('ai.model', 'claude-haiku-5-5'),
            maxTokens: (int) config('ai.max_tokens', 2000),
            system: $blocks,
            messages: [['role' => 'user', 'content' => $user]],
            outputConfig: [
                'effort' => (string) config('ai.effort', 'low'),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        );

        $stop = $message->stopReason;
        $stop = is_object($stop) && property_exists($stop, 'value') ? (string) $stop->value : (string) $stop;

        $data = null;

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $decoded = json_decode((string) $block->text, true);
                $data = is_array($decoded) ? $decoded : null;
                break;
            }
        }

        $usage = $message->usage;

        return new AiReply(
            data: $data,
            stopReason: $stop,
            inputTokens: (int) $usage->inputTokens,
            outputTokens: (int) $usage->outputTokens,
            cacheReadTokens: (int) ($usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($usage->cacheCreationInputTokens ?? 0),
            refusal: $stop === 'refusal'
                ? trim(($message->stopDetails->category ?? '') . ' ' . ($message->stopDetails->explanation ?? ''))
                : null,
        );
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $key = (string) config('ai.api_key');

        if ($key === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set');
        }

        return $this->client = new Client(
            apiKey: $key,
            requestOptions: [
                'maxRetries' => 1,
                'transporter' => new Guzzle([
                    'timeout' => (float) config('ai.timeout', 20),
                    'connect_timeout' => (float) config('ai.connect_timeout', 5),
                    'http_errors' => false,
                ]),
            ],
        );
    }
}
