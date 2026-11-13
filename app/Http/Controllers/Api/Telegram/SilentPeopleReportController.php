<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\SilentPeople;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReportToken;
use App\Http\Controllers\Controller;
use App\Models\Telegram\OperationUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who never answers the penalties, for the developer: GET
 * /api/telegram/report/silent-people with the token of TELEGRAM_REPORT_TOKEN
 * as a bearer token (or X-Report-Token); a missing or wrong token gets a 404.
 * No phones.
 *
 * ?days=1..60 (14) and ?min=1..1000 (30) change what "never" means.
 */
final class SilentPeopleReportController extends Controller
{
    use ChecksReportToken;

    public function __invoke(Request $request, SilentPeople $silent): JsonResponse
    {
        $this->checkReportToken($request);

        $days = max(1, min(60, $request->integer('days', SilentPeople::DEFAULT_DAYS)));
        $min = max(1, min(1000, $request->integer('min', SilentPeople::DEFAULT_MIN_PENALTIES)));

        $people = $silent->find($days, $min)->map(fn (array $row): array => [
            'id' => $row['person']->id,
            'name' => $row['person']->name,
            'role' => $row['person']->roleOrDefault(),
            'username' => $row['person']->telegramUsername(),
            'dm_enabled' => (bool) $row['person']->dm_enabled,
            'penalties' => $row['penalties'],
            'replies' => $row['replies'],
            'last_private_message_at' => $row['person']->last_private_message_at?->toDateTimeString(),
        ])->all();

        return response()->json(
            [
                'days' => $days,
                'min_penalties' => $min,
                'count' => count($people),
                'people' => $people,
                'people_total' => OperationUser::query()->count(),
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
