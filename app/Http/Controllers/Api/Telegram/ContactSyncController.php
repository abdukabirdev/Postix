<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartContactSyncJob;
use App\Models\Telegram\TelegramAccount;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Behind the contact sync page: is a sync running, the groups it reads, how
 * many contacts each account holds, and the log. Access is the
 * `role:driverCheck,superadmin` middleware of the route group.
 */
final class ContactSyncController extends Controller
{
    public function show(): JsonResponse
    {
        $perAccount = TelegramAccountContact::query()
            ->selectRaw(
                'telegram_account_id,
                SUM(status = ?) as added,
                SUM(status = ?) as failed,
                SUM(status = ?) as skipped,
                SUM(status = ? AND added_at >= ?) as today,
                MAX(added_at) as last_added_at',
                [
                    TelegramAccountContact::STATUS_ADDED,
                    TelegramAccountContact::STATUS_FAILED,
                    TelegramAccountContact::STATUS_SKIPPED,
                    TelegramAccountContact::STATUS_ADDED,
                    now()->startOfDay(),
                ]
            )
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        $held = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->where('is_available', false)
            ->get()
            ->keyBy('telegram_account_id');

        $excluded = app(\App\Application\Telegram\Services\ContactSyncSettings::class)->current()['excluded_accounts'];

        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($perAccount, $held, $excluded): array {
                $stats = $perAccount->get($account->id);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'status' => $account->status,
                    'is_primary' => $account->isPrimary(),
                    'excluded' => in_array($account->id, $excluded, true),
                    'held' => $held->has($account->id),
                    'held_reason' => $held->get($account->id)?->disabled_reason,
                    'held_at' => $held->get($account->id)?->disabled_at,
                    'added' => (int) ($stats->added ?? 0),
                    'failed' => (int) ($stats->failed ?? 0),
                    'skipped' => (int) ($stats->skipped ?? 0),
                    'today' => (int) ($stats->today ?? 0),
                    'last_added_at' => $stats->last_added_at ?? null,
                ];
            })
            ->values();

        $groups = TelegramScrapeGroup::query()
            ->forContacts()
            ->withCount('users')
            ->orderBy('id')
            ->get();

        /*
         * The people of the contact sync's own groups: the people table is
         * shared with Join group and holds far more. Counted once every
         * half a minute, not on every poll of the page.
         */
        $skipUsernames = (bool) app(\App\Application\Telegram\Services\ContactSyncSettings::class)->current()['skip_usernames'];

        $people = Cache::remember('contact-sync:people-totals:' . (int) $skipUsernames, 30, fn (): array => [
            'users' => TelegramScrapedUser::query()->forContacts()->count(),
            /* Those with a public @username stay in the database: they wait for nobody. */
            'unassigned' => TelegramScrapedUser::query()
                ->where('is_bot', false)
                ->forContacts()
                ->whereDoesntHave('contact')
                ->when($skipUsernames, fn ($q) => $q->withoutUsername())
                ->count(),
        ]);

        $lastRun = TelegramContactSyncLog::query()
            ->whereIn('event', ['started', 'finished'])
            ->latest('id')
            ->first();

        return response()->json(['data' => [
            'running' => TelegramProcessLock::isHeld(TelegramSyncContactsCommand::LOCK_NAME),
            'pid' => TelegramProcessLock::holderPid(TelegramSyncContactsCommand::LOCK_NAME),
            'daily_limit' => app(\App\Application\Telegram\Services\ContactSyncSettings::class)->current()['daily_limit'],
            'last_event' => $lastRun ? [
                'event' => $lastRun->event,
                'at' => $lastRun->created_at,
                'context' => $lastRun->context,
            ] : null,
            'totals' => [
                'users' => $people['users'],
                'added' => (int) $perAccount->sum('added'),
                'failed' => (int) $perAccount->sum('failed'),
                'unassigned' => $people['unassigned'],
            ],
            'accounts' => $accounts,
            'groups' => $groups,
        ]]);
    }

    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:telegram_scrape_groups,id'],
        ]);

        if (TelegramProcessLock::isHeld(TelegramSyncContactsCommand::LOCK_NAME)) {
            return response()->json([
                'message' => __('telegram.contact_sync.messages.already_running'),
            ], 409);
        }

        StartContactSyncJob::dispatch($request->integer('group_id') ?: null)
            ->onQueue('telegram');

        return response()->json([
            'message' => __('telegram.contact_sync.messages.run_queued'),
        ]);
    }

    /**
     * Put an account back into the sync after a flood wait or an error put it
     * on hold. Nothing else switches it back on.
     */
    public function enableAccount(TelegramAccount $account): JsonResponse
    {
        TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::ContactSync->value)
            ->get()
            ->each->enable();

        return response()->json([
            'message' => __('telegram.contact_sync.messages.account_enabled'),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'event' => ['nullable', 'string', 'max:24'],
            'account_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'run_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $logs = TelegramContactSyncLog::query()
            ->with(['account:id,phone', 'group:id,title,link'])
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('account_id'), fn ($q) => $q->where('telegram_account_id', $request->integer('account_id')))
            ->when($request->filled('group_id'), fn ($q) => $q->where('group_id', $request->integer('group_id')))
            ->when($request->filled('run_id'), fn ($q) => $q->where('run_id', $request->input('run_id')))
            /* One run reads from its start; the rest of the log is newest first. */
            ->when(
                $request->filled('run_id'),
                fn ($q) => $q->oldest('id'),
                fn ($q) => $q->latest('id'),
            )
            ->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:255'],
        ]);

        $link = TelegramScrapeGroup::normalizeLink($data['link']);

        if ($link === '') {
            return response()->json([
                'message' => __('telegram.contact_sync.messages.bad_link'),
            ], 422);
        }

        $group = TelegramScrapeGroup::query()->firstOrCreate(['link' => $link]);

        /* Join group found it already: from now on it is read for contacts too. */
        if ($group->type !== TelegramScrapeGroup::TYPE_CONTACTS) {
            $group->update(['type' => TelegramScrapeGroup::TYPE_CONTACTS, 'is_active' => true]);
        }

        return response()->json(
            ['data' => $group],
            $group->wasRecentlyCreated ? 201 : 200
        );
    }

    public function updateGroup(Request $request, TelegramScrapeGroup $group): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $group->update($data);

        return response()->json(['data' => $group]);
    }

    public function destroyGroup(TelegramScrapeGroup $group): JsonResponse
    {
        /*
         * The people stay: they may be somebody's contact already, and the
         * pivot rows go with the group.
         */
        $group->delete();

        return response()->json([
            'message' => __('telegram.contact_sync.messages.group_deleted'),
        ]);
    }
}
