<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Ai\AiOverview;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI on prod, for the developer: GET /api/telegram/report/ai with the
 * token of TELEGRAM_REPORT_TOKEN as a bearer token (or X-Report-Token); a
 * missing or wrong token gets a 404.
 *
 * ?calls=0..200 (30): the latest calls with what was asked and answered;
 * ?area=driver_check|penalties|replies|profile|test; ?days=1..30 (1) for the counts.
 */
final class AiReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, AiOverview $overview): JsonResponse
    {
        $this->checkReportToken($request);

        $calls = max(0, min(200, (int) $request->query('calls', 30)));
        $days = max(1, min(30, (int) $request->query('days', 1)));
        $area = $request->query('area');

        return response()->json([
            'state' => $overview->state(),
            'counts' => $overview->counts(now()->subDays($days - 1)->startOfDay()),
            'spend_period' => $overview->spendSince(now()->subDays($days - 1)->startOfDay()),
            'calls' => $calls > 0 ? $overview->calls(is_string($area) ? $area : null, null, $calls) : [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
