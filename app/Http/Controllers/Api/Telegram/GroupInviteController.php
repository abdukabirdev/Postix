<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\AccountContactsService;
use App\Application\Telegram\Services\GroupInviteSettings;
use App\Console\Commands\Telegram\TelegramGroupInviteCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartGroupInviteJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The page that invites the accounts' contacts into chosen groups.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteController extends Controller
{
    public function __construct(private readonly GroupInviteSettings $settings) {}

    public function show(): JsonResponse
    {
        $byStatus = TelegramGroupInvite::query()
            ->selectRaw('invite_group_id, status, COUNT(*) as total')
            ->groupBy('invite_group_id', 'status')
            ->get()
            ->groupBy('invite_group_id');

        $groups = TelegramInviteGroup::query()
            ->orderBy('id')
            ->get()
            ->map(function (TelegramInviteGroup $group) use ($byStatus) {
                $counts = $byStatus->get($group->id, collect())->pluck('total', 'status');

                return [
                    'id' => $group->id,
                    'title' => $group->title,
                    'link' => $group->link,
                    'is_active' => $group->is_active,
                    'last_error' => $group->last_error,
                    'invited' => (int) ($counts[TelegramGroupInvite::STATUS_INVITED] ?? 0),
                    'not_added' => (int) ($counts[TelegramGroupInvite::STATUS_NOT_ADDED] ?? 0),
                    'already' => (int) ($counts[TelegramGroupInvite::STATUS_ALREADY] ?? 0),
                    'sent' => (int) ($counts[TelegramGroupInvite::STATUS_SENT] ?? 0),
                    'failed' => (int) ($counts[TelegramGroupInvite::STATUS_FAILED] ?? 0),
                    'pending' => (int) ($counts[TelegramGroupInvite::STATUS_PENDING] ?? 0),
                    /* The member count when the last run came to the group and when it was done with it. */
                    'members_before' => $group->members_before,
                    'members_after' => $group->members_after,
                    'members_checked_at' => $group->members_checked_at,
                ];
            });

        $today = now()->startOfDay();

        $accountCounts = TelegramGroupInvite::query()
            ->selectRaw('telegram_account_id, status, COUNT(*) as total')
            ->groupBy('telegram_account_id', 'status')
            ->get()
            ->groupBy('telegram_account_id');

        $batchesToday = TelegramGroupInviteLog::callsToday();

        $usernamesToday = TelegramGroupInvite::query()
            ->where('via', TelegramGroupInvite::VIA_USERNAME)
            ->whereIn('status', TelegramGroupInvite::SETTLED)
            ->where('attempted_at', '>=', $today)
            ->selectRaw('telegram_account_id, COUNT(*) as total')
            ->groupBy('telegram_account_id')
            ->pluck('total', 'telegram_account_id');

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->keyBy('telegram_account_id');

        $config = $this->settings->current();
        $excluded = $config['excluded_accounts'];
        $limit = (int) $config['batches_per_day'];

        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($accountCounts, $batchesToday, $usernamesToday, $states, $excluded, $limit) {
                $counts = $accountCounts->get($account->id, collect())->pluck('total', 'status');
                $state = $states->get($account->id);
                $meta = (array) ($state?->meta ?? []);
                $restUntil = self::future($meta['rest_until'] ?? null);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'is_primary' => $account->isPrimary(),
                    'excluded' => in_array($account->id, $excluded, true),
                    'contacts' => AccountContactsService::inDatabase($account->id),
                    'batches_today' => (int) ($batchesToday[$account->id] ?? 0),
                    /* Lowered after a PEER_FLOOD, grows back by itself. */
                    'cap' => isset($meta['cap']) ? min($limit, (int) $meta['cap']) : $limit,
                    'cap_lowered' => isset($meta['cap']) && (int) $meta['cap'] < $limit,
                    'usernames_today' => (int) ($usernamesToday[$account->id] ?? 0),
                    'invited' => (int) ($counts[TelegramGroupInvite::STATUS_INVITED] ?? 0),
                    'not_added' => (int) ($counts[TelegramGroupInvite::STATUS_NOT_ADDED] ?? 0),
                    'sent' => (int) ($counts[TelegramGroupInvite::STATUS_SENT] ?? 0),
                    'failed' => (int) ($counts[TelegramGroupInvite::STATUS_FAILED] ?? 0),
                    'held' => $state !== null && ! $state->is_available,
                    'held_reason' => $state !== null && ! $state->is_available ? $state->disabled_reason : null,
                    'rest_until' => $restUntil,
                    'rest_kind' => $restUntil !== null ? ($meta['rest_kind'] ?? null) : null,
                    'rest_reason' => $restUntil !== null ? ($meta['rest_reason'] ?? null) : null,
                ];
            });

        $totals = [
            /* In the group: Telegram named them or the check found them. */
            'invited' => (int) $groups->sum('invited'),
            /* Could be added, are not in the group. */
            'not_added' => (int) $groups->sum('not_added'),
            'already' => (int) $groups->sum('already'),
            /* The call went through, not checked yet. */
            'sent' => (int) $groups->sum('sent'),
            /* Cannot be added. */
            'failed' => (int) $groups->sum('failed'),
            'pending' => (int) $groups->sum('pending'),
            'today' => TelegramGroupInvite::query()
                ->where('status', TelegramGroupInvite::STATUS_INVITED)
                ->where('attempted_at', '>=', $today)
                ->count(),
            'batches_today' => (int) $accounts->sum('batches_today'),
            'by_username' => TelegramGroupInvite::query()
                ->where('status', TelegramGroupInvite::STATUS_INVITED)
                ->where('via', TelegramGroupInvite::VIA_USERNAME)
                ->count(),
            /* People Telegram refuses to let anybody add: no group tries them again. */
            'refused' => TelegramScrapedUser::query()->where('can_add_to_group', false)->count(),
        ];

        return response()->json([
            'running' => TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME),
            'settings' => $config,
            'saved' => $this->settings->isSaved(),
            'limits' => GroupInviteSettings::LIMITS,
            'defaults' => $this->settings->defaults(),
            'groups' => $groups,
            'accounts' => $accounts,
            'totals' => $totals,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        [$batchMin, $batchMax] = GroupInviteSettings::LIMITS['batch_size'];
        [$timesMin, $timesMax] = GroupInviteSettings::LIMITS['batches_per_day'];
        [$delayMin, $delayMax] = GroupInviteSettings::LIMITS['delay_min'];
        [$usernamesMin, $usernamesMax] = GroupInviteSettings::LIMITS['usernames_per_day'];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'batch_size' => ['required', 'integer', "min:{$batchMin}", "max:{$batchMax}"],
            'batches_per_day' => ['required', 'integer', "min:{$timesMin}", "max:{$timesMax}"],
            'delay_min' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}"],
            'delay_max' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}", 'gte:delay_min'],
            'use_usernames' => ['required', 'boolean'],
            'usernames_per_day' => ['required', 'integer', "min:{$usernamesMin}", "max:{$usernamesMax}"],
            'excluded_accounts' => ['nullable', 'array'],
            'excluded_accounts.*' => ['integer', 'exists:telegram_accounts,id'],
        ]);

        $data['excluded_accounts'] = $data['excluded_accounts'] ?? [];

        return response()->json([
            'settings' => $this->settings->save($data),
            'saved' => true,
            'message' => __('driver_check.invites.messages.settings_saved'),
        ]);
    }

    public function run(): JsonResponse
    {
        if (TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME)) {
            return response()->json([
                'message' => __('driver_check.invites.messages.already_running'),
            ], 409);
        }

        StartGroupInviteJob::dispatch()->onQueue('telegram');

        return response()->json([
            'message' => __('driver_check.invites.messages.run_queued'),
        ]);
    }

    /**
     * Look the people already sent up in their groups, nobody invited.
     */
    public function check(): JsonResponse
    {
        if (TelegramProcessLock::isHeld(TelegramGroupInviteCommand::LOCK_NAME)) {
            return response()->json([
                'message' => __('driver_check.invites.messages.already_running'),
            ], 409);
        }

        StartGroupInviteJob::dispatch(check: true)->onQueue('telegram');

        return response()->json([
            'message' => __('driver_check.invites.messages.check_queued'),
        ]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:255'],
        ]);

        $link = TelegramInviteGroup::normalizeLink($data['link']);

        if ($link === '') {
            return response()->json([
                'message' => __('driver_check.invites.messages.bad_link'),
            ], 422);
        }

        $group = TelegramInviteGroup::query()->firstOrCreate(
            ['link' => $link],
            ['is_active' => true],
        );

        return response()->json(
            ['data' => $group, 'message' => __('driver_check.invites.messages.group_added')],
            $group->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function updateGroup(Request $request, TelegramInviteGroup $inviteGroup): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $inviteGroup->update($data);

        return response()->json(['data' => $inviteGroup]);
    }

    public function destroyGroup(TelegramInviteGroup $inviteGroup): JsonResponse
    {
        $inviteGroup->delete();

        return response()->json([
            'message' => __('driver_check.invites.messages.group_deleted'),
        ]);
    }

    /**
     * Back into the invites after an error, a flood or a PEER_FLOOD put it
     * aside; its lowered daily cap goes with it.
     */
    public function enableAccount(TelegramAccount $account): JsonResponse
    {
        TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::GroupInvite->value)
            ->get()
            ->each(function (TelegramAccountProcess $state): void {
                $state->enable();

                $meta = (array) $state->meta;
                unset($meta['rest_until'], $meta['rest_kind'], $meta['rest_reason'], $meta['cap'], $meta['peer_floods'], $meta['clean_day']);

                $state->update(['meta' => $meta === [] ? null : $meta]);
            });

        return response()->json([
            'message' => __('driver_check.invites.messages.account_enabled'),
        ]);
    }

    /**
     * Forget the failures so the next run tries those people again: the ones
     * that cannot be added (those Telegram refused for everybody included),
     * the ones that could be but are not in the group, or both. Who was
     * invited stays invited.
     */
    public function retryFailed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:telegram_invite_groups,id'],
            'status' => ['nullable', 'in:failed,not_added'],
        ]);

        $failed = TelegramGroupInvite::query()
            ->whereIn('status', filled($data['status'] ?? null)
                ? [$data['status']]
                : [TelegramGroupInvite::STATUS_FAILED, TelegramGroupInvite::STATUS_NOT_ADDED])
            ->when(filled($data['group_id'] ?? null), fn ($q) => $q->where('invite_group_id', (int) $data['group_id']));

        TelegramScrapedUser::query()
            ->where('can_add_to_group', false)
            ->whereIn('id', (clone $failed)->select('scraped_user_id'))
            ->update(['can_add_to_group' => null, 'add_to_group_error' => null]);

        $deleted = $failed->delete();

        return response()->json([
            'deleted' => $deleted,
            'message' => __('driver_check.invites.messages.retry_queued', ['n' => $deleted]),
        ]);
    }

    public function results(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:invited,not_added,already,sent,failed,pending'],
            'via' => ['nullable', 'in:contact,username'],
            'group_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = TelegramGroupInvite::query()
            ->with([
                'user:id,telegram_user_id,username,first_name,last_name,phone,can_add_to_group',
                'account:id,phone',
                'group:id,title,link',
            ])
            ->when(filled($data['status'] ?? null), fn ($q) => $q->where('status', $data['status']))
            ->when(filled($data['group_id'] ?? null), fn ($q) => $q->where('invite_group_id', (int) $data['group_id']))
            ->when(filled($data['via'] ?? null), fn ($q) => $q->where('via', $data['via']))
            ->when(filled($data['account_id'] ?? null), fn ($q) => $q->where('telegram_account_id', (int) $data['account_id']))
            ->when(filled($data['search'] ?? null), function ($q) use ($data) {
                $term = trim((string) $data['search']);
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], ltrim($term, '@')) . '%';

                $q->whereHas('user', function ($q) use ($like, $term) {
                    $q->where('username', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like);

                    if (ctype_digit($term)) {
                        $q->orWhere('telegram_user_id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', max(1, $request->integer('page', 1)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (TelegramGroupInvite $row) => [
                'id' => $row->id,
                'status' => $row->status,
                'via' => $row->via,
                'error' => $row->error,
                'attempted_at' => $row->attempted_at,
                'checked_at' => $row->checked_at,
                'user' => $row->user ? [
                    'id' => $row->user->id,
                    'telegram_user_id' => $row->user->telegram_user_id,
                    'username' => $row->user->username,
                    'name' => trim(($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '')),
                    'phone' => $row->user->phone,
                    'can_add_to_group' => $row->user->can_add_to_group,
                ] : null,
                'account' => $row->account ? [
                    'id' => $row->account->id,
                    'phone' => $row->account->phone,
                ] : null,
                'group' => $row->group ? [
                    'id' => $row->group->id,
                    'title' => $row->group->title ?: $row->group->link,
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * An ISO time still ahead, or null.
     */
    private static function future(mixed $at): ?string
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        $time = strtotime($at);

        return $time !== false && $time > now()->timestamp ? $at : null;
    }
}
