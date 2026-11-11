<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteSettings;
use App\Application\Telegram\Services\SpamBotCheck;
use App\Application\Telegram\Support\AccountAge;
use App\Application\Telegram\Support\PhoneCountry;
use App\Console\Commands\Telegram\TelegramSpamBotCheckCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartSpamBotCheckJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramAccountSpamCheck;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Telegram\TelegramProcessLock;
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

    /**
     * The page asks in stages (`part`), so that the list is on the screen
     * before the heavy numbers are counted:
     *
     *   state     who they are, in or out, resting or held, the day's calls,
     *             what @SpamBot said - a few small queries
     *   numbers   what each got: all time, the last days, today, its last stop
     *   contacts  what its contacts are worth for the invite - the heaviest
     *
     * Without `part` everything comes at once, as it used to.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(match ($request->query('part')) {
            'state' => $this->statePart(),
            'numbers' => ['numbers' => (object) $this->numbersPart()],
            'contacts' => ['contacts' => (object) self::contacts()],
            default => $this->whole(),
        });
    }

    /**
     * Everything in one answer: each account with its numbers and contacts.
     *
     * @return array<string, mixed>
     */
    private function whole(): array
    {
        $state = $this->statePart();
        $numbers = $this->numbersPart();
        $contacts = self::contacts();

        $state['accounts'] = collect($state['accounts'])
            ->map(function (array $account) use ($numbers, $contacts): array {
                $own = $contacts[$account['id']] ?? ['total' => 0, 'can' => 0, 'cannot' => 0];

                return [
                    ...$account,
                    ...($numbers[$account['id']] ?? self::noNumbers()),
                    'contacts' => $own['total'],
                    'contacts_can' => $own['can'],
                    'contacts_cannot' => $own['cannot'],
                ];
            })
            ->values();

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function statePart(): array
    {
        $config = $this->settings->current();
        $limit = (int) $config['batches_per_day'];

        $callsToday = TelegramGroupInviteLog::callsToday();
        $firstDays = TelegramGroupInviteLog::firstCallDays();

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->keyBy('telegram_account_id');

        /* What @SpamBot said about each the last time it was asked. */
        $spamChecks = SpamBotCheck::latest();

        /* Asked once, not for every account. */
        $listeners = GroupInviteSettings::listenerIds();

        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($callsToday, $firstDays, $states, $spamChecks, $listeners, $config): array {
                $state = $states->get($account->id);
                $meta = (array) ($state?->meta ?? []);
                $restUntil = GroupInviteController::future($meta['rest_until'] ?? null);
                /* A new account makes a share of the calls on its first days. */
                $day = TelegramGroupInviteLog::inviteDay($meta['first_day'] ?? $firstDays[$account->id] ?? null);
                $share = GroupInviteSettings::warmShare($day, $config);
                $warmCap = GroupInviteSettings::warmCap($day, $config);
                $listener = in_array((int) $account->id, $listeners, true);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    /* By the number's calling code; how old by the Telegram id (AccountAge): for the page's tables by country and by age. */
                    'country' => PhoneCountry::of($account->phone),
                    'telegram_user_id' => $account->telegram_user_id !== null ? (int) $account->telegram_user_id : null,
                    'age' => AccountAge::band($account->telegram_user_id),
                    'is_primary' => $listener,
                    'excluded' => in_array($account->id, $config['excluded_accounts'], true),
                    'listener' => $listener,
                    'calls_today' => (int) ($callsToday[$account->id] ?? 0),
                    /* Lowered after a PEER_FLOOD, grows back by itself. */
                    'cap' => isset($meta['cap']) ? min($warmCap, (int) $meta['cap']) : $warmCap,
                    'cap_lowered' => isset($meta['cap']) && (int) $meta['cap'] < $warmCap,
                    'warm_day' => $share < 1 ? $day : null,
                    'warm_percent' => (int) round($share * 100),
                    'peer_floods' => (int) ($meta['peer_floods'] ?? 0),
                    'held' => $state !== null && ! $state->is_available,
                    'held_reason' => $state !== null && ! $state->is_available ? $state->disabled_reason : null,
                    'rest_until' => $restUntil,
                    'rest_kind' => $restUntil !== null ? ($meta['rest_kind'] ?? null) : null,
                    'rest_reason' => $restUntil !== null ? ($meta['rest_reason'] ?? null) : null,
                    'spam_check' => self::spamCheckOf($spamChecks->get($account->id)),
                ];
            });

        return [
            'accounts' => $accounts->values()->all(),
            'spam_checking' => TelegramProcessLock::isHeld(TelegramSpamBotCheckCommand::LOCK_NAME),
            'recent_days' => self::RECENT_DAYS,
            'age_bands' => AccountAge::bands(),
            'limits' => [
                'batches_per_day' => $limit,
                'batch_size' => (int) $config['batch_size'],
                'usernames_per_day' => $config['use_usernames'] ? (int) $config['usernames_per_day'] : 0,
            ],
        ];
    }

    /**
     * What each account got, by its id. An account that never invited is
     * not in the list: noNumbers() stands for it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function numbersPart(): array
    {
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

        $lastStops = TelegramGroupInviteLog::query()
            ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
            ->whereIn('id', TelegramGroupInviteLog::query()
                ->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)
                ->whereNotNull('telegram_account_id')
                ->selectRaw('MAX(id)')
                ->groupBy('telegram_account_id'))
            ->get()
            ->keyBy('telegram_account_id');

        $ids = collect([$byStatus->keys(), $lastStops->keys()])
            ->flatten()
            ->filter()
            ->unique();

        $out = [];

        foreach ($ids as $id) {
            $counts = GroupInviteGroupController::counts($byStatus->get($id, collect())->pluck('total', 'status')->all());
            $lately = GroupInviteGroupController::counts($recent->get($id, collect())->pluck('total', 'status')->all());
            $stop = $lastStops->get($id);

            $out[(int) $id] = [
                'added_today' => (int) ($addedToday[$id] ?? 0),
                'lookups_today' => (int) ($lookupsToday[$id] ?? 0),
                ...$counts,
                /* The last RECENT_DAYS: people tried, how many of them got in, how many Telegram quietly left out. */
                'recent' => [
                    'tried' => $lately['added'] + $lately['not_added'] + $lately['already'] + $lately['failed'],
                    'added' => $lately['added'],
                    'not_added' => $lately['not_added'],
                ],
                'last_stop' => $stop ? [
                    'at' => $stop->created_at,
                    'event' => $stop->event,
                    'kind' => $stop->context['kind'] ?? null,
                    'rpc' => $stop->context['rpc'] ?? null,
                    'calls' => $stop->context['counters']['calls'] ?? null,
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function noNumbers(): array
    {
        return [
            'added_today' => 0,
            'lookups_today' => 0,
            ...GroupInviteGroupController::counts([]),
            'recent' => ['tried' => 0, 'added' => 0, 'not_added' => 0],
            'last_stop' => null,
        ];
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
     * Ask @SpamBot about this account: is it limited by Telegram, until
     * when. The answer comes to the card in a minute.
     */
    public function spamCheck(TelegramAccount $account): JsonResponse
    {
        return $this->askSpamBot(new StartSpamBotCheckJob($account->id), 'spam_check_queued');
    }

    /**
     * Ask @SpamBot about every account, one after another.
     */
    public function spamCheckAll(): JsonResponse
    {
        return $this->askSpamBot(new StartSpamBotCheckJob(), 'spam_check_all_queued');
    }

    private function askSpamBot(StartSpamBotCheckJob $job, string $message): JsonResponse
    {
        if (TelegramProcessLock::isHeld(TelegramSpamBotCheckCommand::LOCK_NAME)) {
            return response()->json(['message' => __('driver_check.invites.messages.spam_check_running')], 409);
        }

        dispatch($job->onQueue('telegram'));

        return response()->json(['message' => __("driver_check.invites.messages.{$message}")]);
    }

    /**
     * @return array{status: string, until: \Illuminate\Support\Carbon|null, text: string|null, error: string|null, at: \Illuminate\Support\Carbon}|null
     */
    private static function spamCheckOf(?TelegramAccountSpamCheck $check): ?array
    {
        if ($check === null) {
            return null;
        }

        return [
            'status' => $check->status,
            'until' => $check->limited_until,
            'text' => $check->text !== null ? mb_substr($check->text, 0, 400) : null,
            'error' => $check->error,
            'at' => $check->created_at,
        ];
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
