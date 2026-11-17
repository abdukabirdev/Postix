<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\SenderBotReport;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The sender bot on prod, for the developer: GET /api/telegram/report/sender-bot
 * with the token of TELEGRAM_REPORT_TOKEN as a bearer token (or
 * X-Report-Token); a missing or wrong token gets a 404.
 *
 * ?lines=0..200 (40): how many log lines about the bot.
 */
final class SenderBotReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, SenderBotReport $report): JsonResponse
    {
        $this->checkReportToken($request);

        $lines = max(0, min(200, (int) $request->query('lines', 40)));

        return response()->json($report->build($lines), 200, [], JSON_UNESCAPED_UNICODE);
    }
}
