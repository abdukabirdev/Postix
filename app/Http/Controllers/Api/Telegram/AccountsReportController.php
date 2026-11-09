<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\AccountsReport;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where every account stands and the drivers into a group, for the
 * developer: GET /api/telegram/report/accounts with the token of
 * TELEGRAM_REPORT_TOKEN as a bearer token (or X-Report-Token); a missing or
 * wrong token gets a 404.
 *
 * ?days=1..14 (3): the drivers' days and errors; ?people=1: each account's
 * contacts the current invite group has not tried (one heavier query).
 */
final class AccountsReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, AccountsReport $report): JsonResponse
    {
        $this->checkReportToken($request);

        $query = $this->reportQuery($request);

        return response()->json(
            $report->build($query['days'], $request->boolean('people')),
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
