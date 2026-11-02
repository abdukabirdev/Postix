<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use RuntimeException;

/**
 * What the Bot API refused, with whether a later try may go through.
 */
final class BotApiError extends RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        public readonly bool $transient = false,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * "Bad Request: USER_ALREADY_PARTICIPANT" -> USER_ALREADY_PARTICIPANT;
     * a description in words stays as it is, cut short.
     */
    public function reason(): string
    {
        $text = trim((string) preg_replace('/^[A-Za-z ]+:\s*/', '', $this->getMessage()));

        if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $text) === 1) {
            return $text;
        }

        return mb_substr($text !== '' ? $text : $this->getMessage(), 0, 120);
    }
}
