<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram\Concerns;

use Illuminate\Http\Request;

/**
 * The chat reader's own token (TELEGRAM_READER_TOKEN), apart from the report
 * token: what it opens is the content of chats. As a bearer token or
 * X-Reader-Token. Without a token set in .env, or one shorter than 32
 * characters, the address does not exist; a wrong token gets the same 404.
 */
trait ChecksReaderToken
{
    private function checkReaderToken(Request $request): void
    {
        $token = (string) config('services.telegram.reader_token');
        $given = (string) ($request->bearerToken() ?? $request->header('X-Reader-Token', ''));

        abort_if(strlen($token) < 32 || ! hash_equals($token, $given), 404);
    }
}
