<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteSettings;
use App\Application\Telegram\Support\AccountAge;
use App\Application\Telegram\Support\PhoneCountry;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The accounts side by side over a period: who added the most, day by day,
 * how old each account is (by its Telegram id) and where its number is
 * from, how often Telegram stopped it. People, not batches - counted the
 * same way as the analysis page: by the person's last try in a group.
 */
final class GroupInviteRankingController extends Controller
{
    private const PERIODS = [7, 14, 30, 90];

    public function __construct(private readonly GroupInviteSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 7);
        $days = in_array($days, self::PERIODS, true) ? $days : 7;
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $dates = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $index = array_flip($dates);
        $people = [];

        /* Where each person tried in the period ended, day by day, per account. */
        TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('status', '!=', TelegramGroupInvite::STATUS_SENT)
            ->whereNotNull('telegram_account_id')
            ->whereBetween('attempted_at', [$from, $to])
            ->selectRaw('DATE(attempted_at) as day, telegram_account_id, status, COUNT(*) as total')
            ->groupByRaw('DATE(attempted_at), telegram_account_id, status')
            ->get()
            ->each(function ($line) use (&$people, $index, $days): void {
                $id = (int) $line->telegram_account_id;
                $at = $index[(string) $line->day] ?? null;
                $total = (int) $line->total;

                $people[$id] ??= [
                    'tried' => 0,
                    'added' => 0,
                    'not_added' => 0,
                    'failed' => 0,
                    'already' => 0,
                    'added_by_day' => array_fill(0, $days, 0),
                    'tried_by_day' => array_fill(0, $days, 0),
                ];

                $field = match ($line->status) {
                    TelegramGroupInvite::STATUS_INVITED, TelegramGroupInvite::STATUS_LEFT => 'added',
                    TelegramGroupInvite::STATUS_NOT_ADDED => 'not_added',
                    TelegramGroupInvite::STATUS_FAILED => 'failed',
                    TelegramGroupInvite::STATUS_ALREADY => 'already',
                    default => null,
                };

                if ($field === null) {
                    return;
                }

                $people[$id][$field] += $total;
                $people[$id]['tried'] += $total;

                if ($at !== null) {
                    $people[$id]['tried_by_day'][$at] += $total;

                    if ($field === 'added') {
                        $people[$id]['added_by_day'][$at] += $total;
                    }
                }
            });

        /* How often Telegram stopped each account in the period: flood waits (and the longest), PEER_FLOOD. */
        $stops = [];

        TelegramGroupInviteLog::query()
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->whereNotNull('telegram_account_id')
            ->whereBetween('created_at', [$from, $to])
            ->get(['telegram_account_id', 'event', 'context'])
            ->each(function (TelegramGroupInviteLog $line) use (&$stops): void {
                $id = (int) $line->telegram_account_id;
                $context = (array) $line->context;
                $kind = $context['kind'] ?? null;

                $stops[$id] ??= ['floods' => 0, 'flood_max' => 0, 'peer_floods' => 0];

                if ($line->event === 'flood' && $kind === 'wait') {
                    $stops[$id]['floods']++;
                    $stops[$id]['flood_max'] = max($stops[$id]['flood_max'], (int) ($context['seconds'] ?? 0));
                } elseif ($kind === 'spam') {
                    $stops[$id]['peer_floods']++;
                }
            });

        $config = $this->settings->current();
        $listeners = GroupInviteSettings::listenerIds();
        $firstDays = TelegramGroupInviteLog::firstCallDays();
        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->keyBy('telegram_account_id');

        $empty = [
            'tried' => 0,
            'added' => 0,
            'not_added' => 0,
            'failed' => 0,
            'already' => 0,
            'added_by_day' => array_fill(0, $days, 0),
            'tried_by_day' => array_fill(0, $days, 0),
        ];

        /* Every connected account, and any other that tried somebody in the period. */
        $accounts = TelegramAccount::query()
            ->where(fn ($query) => $query
                ->where('is_authorized', true)
                ->orWhereIn('id', array_keys($people)))
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($people, $stops, $config, $listeners, $firstDays, $states, $empty) {
                $state = $states->get($account->id);
                $meta = (array) ($state?->meta ?? []);
                $restUntil = GroupInviteController::future($meta['rest_until'] ?? null);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'country' => PhoneCountry::of($account->phone),
                    'telegram_user_id' => $account->telegram_user_id !== null ? (int) $account->telegram_user_id : null,
                    'age' => AccountAge::band($account->telegram_user_id),
                    'first_day' => $meta['first_day'] ?? $firstDays[$account->id] ?? null,
                    'excluded' => in_array($account->id, $config['excluded_accounts'], true),
                    'listener' => in_array($account->id, $listeners, true),
                    'held' => $state !== null && ! $state->is_available,
                    'held_reason' => $state !== null && ! $state->is_available ? $state->disabled_reason : null,
                    'rest_until' => $restUntil,
                    'rest_kind' => $restUntil !== null ? ($meta['rest_kind'] ?? null) : null,
                    'rest_reason' => $restUntil !== null ? ($meta['rest_reason'] ?? null) : null,
                    ...($people[$account->id] ?? $empty),
                    ...($stops[$account->id] ?? ['floods' => 0, 'flood_max' => 0, 'peer_floods' => 0]),
                ];
            });

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'periods' => self::PERIODS,
            'dates' => $dates,
            'age_bands' => AccountAge::bands(),
            'accounts' => $accounts->values(),
        ]);
    }
}
