<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The group invites' report for the developer, read from outside the
 * panel: GET /api/telegram/report/group-invites with the token of
 * TELEGRAM_REPORT_TOKEN as a bearer token (or X-Report-Token). Without a
 * token set in .env, or one shorter than 32 characters, the address does
 * not exist; a wrong token gets the same 404.
 *
 * ?days=1..14 (3), ?lines=0..1000 (200): the log lines, the latest or,
 * with ?run_id=, those of one run.
 */
final class GroupInviteReportController extends Controller
{
    private const MIN_TOKEN = 32;

    public function __invoke(Request $request, GroupInviteReport $report): JsonResponse
    {
        $token = (string) config('services.telegram.report_token');
        $given = (string) ($request->bearerToken() ?? $request->header('X-Report-Token', ''));

        abort_if(strlen($token) < self::MIN_TOKEN || ! hash_equals($token, $given), 404);

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'lines' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'run_id' => ['nullable', 'string', 'max:36'],
        ]);

        return response()->json(
            $report->build((int) ($data['days'] ?? 3), (int) ($data['lines'] ?? 200), $data['run_id'] ?? null),
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
