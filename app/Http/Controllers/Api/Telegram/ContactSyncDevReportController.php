<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\ContactSyncReport;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The contact sync's report for the developer, read from outside the
 * panel: GET /api/telegram/report/contact-sync, the same token as the
 * group invites' report. ?days=1..14 (3), ?lines=0..1000 (200), ?run_id=.
 */
final class ContactSyncDevReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, ContactSyncReport $report): JsonResponse
    {
        $this->checkReportToken($request);

        $query = $this->reportQuery($request);

        return response()->json(
            $report->build($query['days'], $query['lines'], $query['run_id']),
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
