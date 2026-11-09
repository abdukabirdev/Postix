<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteReport;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
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
 * with ?run_id=, those of one run. ?full=1: the heavy parts too (the
 * history of people tried in several groups, each account's contacts).
 */
final class GroupInviteReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, GroupInviteReport $report): JsonResponse
    {
        $this->checkReportToken($request);

        $query = $this->reportQuery($request);

        return response()->json(
            $report->build($query['days'], $query['lines'], $query['run_id'], $request->boolean('full')),
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
