<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram\Concerns;

use Illuminate\Http\Request;

/**
 * The developer's reports, read from outside the panel: the token of
 * TELEGRAM_REPORT_TOKEN as a bearer token (or X-Report-Token). Without a
 * token set in .env, or one shorter than 32 characters, the address does
 * not exist; a wrong token gets the same 404.
 */
trait ChecksReportToken
{
    private function checkReportToken(Request $request): void
    {
        $token = (string) config('services.telegram.report_token');
        $given = (string) ($request->bearerToken() ?? $request->header('X-Report-Token', ''));

        abort_if(strlen($token) < 32 || ! hash_equals($token, $given), 404);
    }

    /**
     * ?days=1..14 (3), ?lines=0..1000 (200), ?run_id=.
     *
     * @return array{days: int, lines: int, run_id: string|null}
     */
    private function reportQuery(Request $request): array
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'lines' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'run_id' => ['nullable', 'string', 'max:36'],
        ]);

        return [
            'days' => (int) ($data['days'] ?? 3),
            'lines' => (int) ($data['lines'] ?? 200),
            'run_id' => $data['run_id'] ?? null,
        ];
    }
}
