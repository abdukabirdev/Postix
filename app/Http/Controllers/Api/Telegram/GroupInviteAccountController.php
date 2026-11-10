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
use Illuminate\Support\Facades\DB;

/**
 * The accounts that invite: who takes part, how far each is into its day,
 * how it rests after a flood, what its contacts are worth for the invite
 * and what it got.
 */
final class GroupInviteAccountController extends Controller
{
    /**
     * How an account adds lately: the page tells the ones that add from
     * the ones Telegram quietly lets add nobody by these days, not by all
     * time - an account can go quiet after a good start.
     */
    public const RECENT_DAYS = 3;

    public function __construct(private readonly GroupInviteSettings $settings) {}

    public function index(): JsonResponse
    {
        $config = $this->settings->current();
        $limit = (int) $config['batches_per_day'];
        $today = now()->startOfDay();

        $byStatus = TelegramGroupInvite::query()
            ->selectRaw('telegram_account_id, status, COUNT(*) as total')
            ->groupBy('telegram_account_id', 'status')
            ->get()
            ->groupBy('telegram_account_id');

        /* Tried in the last days, whichever group: not yet looked up (sent) is no answer either way. */
        $recent = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('status', '!=', TelegramGroupInvite::STATUS_SENT)
            ->where('attempted_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->selectRaw('telegram_account_id, status, COUNT(*) as total')
            ->groupBy('telegram_account_id', 'status')
            ->get()
            ->groupBy('telegram_account_id');

        $callsToday = TelegramGroupInviteLog::callsToday();
        $firstDays = TelegramGroupInviteLog::firstCallDays();

        $lookupsToday = TelegramGroupInvite::query()
            ->where('via', TelegramGroupInvite::VIA_USERNAME)
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('attempted_at', '>=', $today)
            ->selectRaw('telegram_account_id, COUNT(*) as total')
            ->groupBy('telegram_account_id')
            ->pluck('total', 'telegram_account_id');

        $addedToday = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::ADDED)
            ->where('attempted_at', '>=', $today)
            ->selectRaw('telegram_account_id, COUNT(*) as total')
            ->groupBy('telegram_account_id')
            ->pluck('total', 'telegram_account_id');

        $contacts = self::contacts();

        $lastStops = TelegramGroupInviteLog::query()
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->whereIn('id', TelegramGroupInviteLog::query()
                ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
                ->whereNotNull('telegram_account_id')
                ->selectRaw('MAX(id)')
                ->groupBy('telegram_account_id'))
            ->get()
            ->keyBy('telegram_account_id');

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->keyBy('telegram_account_id');

        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($byStatus, $recent, $callsToday, $firstDays, $lookupsToday, $addedToday, $contacts, $lastStops, $states, $config, $limit) {
                $counts = GroupInviteGroupController::counts($byStatus->get($account->id, collect())->pluck('total', 'status')->all());
                $lately = GroupInviteGroupController::counts($recent->get($account->id, collect())->pluck('total', 'status')->all());
                $state = $states->get($account->id);
                $meta = (array) ($state?->meta ?? []);
                $restUntil = GroupInviteController::future($meta['rest_until'] ?? null);
                $stop = $lastStops->get($account->id);
                $own = $contacts[$account->id] ?? ['total' => 0, 'can' => 0, 'cannot' => 0];
                /* A new account makes a share of the calls on its first days. */
                $day = TelegramGroupInviteLog::inviteDay($meta['first_day'] ?? $firstDays[$account->id] ?? null);
                $share = GroupInviteSettings::warmShare($day, $config);
                $warmCap = GroupInviteSettings::warmCap($day, $config);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    /* By the number's calling code; how old by the Telegram id (AccountAge): for the page's tables by country and by age. */
                    'country' => PhoneCountry::of($account->phone),
                    'telegram_user_id' => $account->telegram_user_id !== null ? (int) $account->telegram_user_id : null,
                    'age' => AccountAge::band($account->telegram_user_id),
                    'is_primary' => $account->isPrimary(),
                    'excluded' => in_array($account->id, $config['excluded_accounts'], true),
                    'listener' => in_array($account->id, GroupInviteSettings::listenerIds(), true),
                    /* Its contacts in the base, and what the invite knows of them. */
                    'contacts' => $own['total'],
                    'contacts_can' => $own['can'],
                    'contacts_cannot' => $own['cannot'],
                    'calls_today' => (int) ($callsToday[$account->id] ?? 0),
                    'added_today' => (int) ($addedToday[$account->id] ?? 0),
                    'lookups_today' => (int) ($lookupsToday[$account->id] ?? 0),
                    /* Lowered after a PEER_FLOOD, grows back by itself. */
                    'cap' => isset($meta['cap']) ? min($warmCap, (int) $meta['cap']) : $warmCap,
                    'cap_lowered' => isset($meta['cap']) && (int) $meta['cap'] < $warmCap,
                    'warm_day' => $share < 1 ? $day : null,
                    'warm_percent' => (int) round($share * 100),
                    'peer_floods' => (int) ($meta['peer_floods'] ?? 0),
                    ...$counts,
                    /* The last RECENT_DAYS: people tried, how many of them got in, how many Telegram quietly left out. */
                    'recent' => [
                        'tried' => $lately['added'] + $lately['not_added'] + $lately['already'] + $lately['failed'],
                        'added' => $lately['added'],
                        'not_added' => $lately['not_added'],
                    ],
                    'held' => $state !== null && ! $state->is_available,
                    'held_reason' => $state !== null && ! $state->is_available ? $state->disabled_reason : null,
                    'rest_until' => $restUntil,
                    'rest_kind' => $restUntil !== null ? ($meta['rest_kind'] ?? null) : null,
                    'rest_reason' => $restUntil !== null ? ($meta['rest_reason'] ?? null) : null,
                    'last_stop' => $stop ? [
                        'at' => $stop->created_at,
                        'event' => $stop->event,
                        'kind' => $stop->context['kind'] ?? null,
                        'rpc' => $stop->context['rpc'] ?? null,
                        'calls' => $stop->context['counters']['calls'] ?? null,
                    ] : null,
                ];
            });

        return response()->json([
            'accounts' => $accounts->values(),
            'recent_days' => self::RECENT_DAYS,
            'age_bands' => AccountAge::bands(),
            'limits' => [
                'batches_per_day' => $limit,
                'batch_size' => (int) $config['batch_size'],
                'usernames_per_day' => $config['use_usernames'] ? (int) $config['usernames_per_day'] : 0,
            ],
        ]);
    }

    /**
     * In or out of the invites, saved at once. The listener stays out.
     */
    public function update(Request $request, TelegramAccount $account): JsonResponse
    {
        $data = $request->validate([
            'excluded' => ['required', 'boolean'],
        ]);

        $config = $this->settings->current();

        $excluded = collect($config['excluded_accounts'])
            ->reject(fn (int $id) => $id === $account->id)
            ->when($data['excluded'], fn ($ids) => $ids->push($account->id))
            ->values()
            ->all();

        $saved = $this->settings->save([...$config, 'excluded_accounts' => $excluded]);

        return response()->json([
            'excluded' => in_array($account->id, $saved['excluded_accounts'], true),
            'message' => __(in_array($account->id, $saved['excluded_accounts'], true)
                ? 'driver_check.invites.messages.account_excluded'
                : 'driver_check.invites.messages.account_included'),
        ]);
    }

    /**
     * Back into the invites after an error, a flood or a PEER_FLOOD put it
     * aside; its lowered daily cap goes with it.
     */
    public function enable(TelegramAccount $account): JsonResponse
    {
        TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->each(function (TelegramAccountProcess $state): void {
                $state->enable();

                $meta = (array) $state->meta;
                unset($meta['rest_until'], $meta['rest_kind'], $meta['rest_reason'], $meta['cap'], $meta['peer_floods'], $meta['clean_day'], $meta['add_limits'], $meta['add_limited_at']);

                $state->update(['meta' => $meta === [] ? null : $meta]);
            });

        return response()->json([
            'message' => __('driver_check.invites.messages.account_enabled'),
        ]);
    }

    /**
     * Each account's contacts in the base: all of them, those already added
     * somewhere, those Telegram refused.
     *
     * @return array<int, array{total: int, can: int, cannot: int}>
     */
    private static function contacts(): array
    {
        $held = DB::table('telegram_account_contacts')
            ->whereIn('status', ['added', 'skipped'])
            ->select('telegram_account_id', 'scraped_user_id');

        $pairs = DB::table('telegram_account_contact_links')
            ->select('telegram_account_id', 'scraped_user_id')
            ->union($held);

        $out = [];

        DB::query()
            ->fromSub($pairs, 'p')
            ->join('telegram_scraped_users as u', 'u.id', '=', 'p.scraped_user_id')
            ->selectRaw('p.telegram_account_id as account_id, u.can_add_to_group as flag, COUNT(*) as total')
            ->groupBy('p.telegram_account_id', 'u.can_add_to_group')
            ->get()
            ->each(function (object $row) use (&$out): void {
                $id = (int) $row->account_id;
                $out[$id] ??= ['total' => 0, 'can' => 0, 'cannot' => 0];
                $out[$id]['total'] += (int) $row->total;

                if ($row->flag !== null) {
                    $out[$id][(bool) $row->flag ? 'can' : 'cannot'] += (int) $row->total;
                }
            });

        return $out;
    }
}
