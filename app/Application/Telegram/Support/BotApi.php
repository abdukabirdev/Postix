<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A plain Bot API call for a bot whose token is not in the config (the
 * join-request bot, set in the panel). Throws BotApiError on anything but
 * {ok: true}.
 */
final class BotApi
{
    public function __construct(private readonly string $token) {}

    /**
     * @param array<string, mixed> $params
     */
    public function call(string $method, array $params = [], int $timeout = 15): mixed
    {
        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$this->token}/{$method}", $params);
        } catch (ConnectionException $e) {
            throw new BotApiError('NETWORK: ' . mb_substr($e->getMessage(), 0, 200), 0, true);
        }

        $json = $response->json();

        if (is_array($json) && ($json['ok'] ?? false) === true) {
            return $json['result'] ?? null;
        }

        $code = is_array($json) ? (int) ($json['error_code'] ?? $response->status()) : $response->status();
        $description = is_array($json) ? (string) ($json['description'] ?? '') : '';

        throw new BotApiError(
            $description !== '' ? $description : 'HTTP ' . $response->status(),
            $code,
            $code === 429 || $code >= 500 || $code === 0,
            is_array($json) ? (int) ($json['parameters']['retry_after'] ?? 0) : 0,
        );
    }
}
