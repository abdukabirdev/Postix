<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\AccountContactsService;
use App\Application\Telegram\Services\ContactSyncSettings;
use App\Console\Commands\Telegram\TelegramAccountContactsCommand;
use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Telegram\StartAccountContactsJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContactState;
use App\Models\Telegram\TelegramAccountProcess;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The accounts page of the contacts section: for each account how many
 * contacts the database lists and how many the account holds, whether they
 * agree, and the buttons - read the account into the database, delete from
 * the account, forget in the database - each its own. Access is the
 * `role:driverCheck,superadmin` middleware of the route group.
 */
final class ContactAccountsController extends Controller
{
    public function __construct(
        private readonly AccountContactsService $contacts,
        private readonly ContactSyncSettings $settings,
    ) {}

    public function index(): JsonResponse
    {
        $held = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->where('is_available', false)
            ->get()
            ->keyBy('telegram_account_id');

        $states = TelegramAccountContactState::query()->get()->keyBy('telegram_account_id');
        $excluded = $this->settings->current()['excluded_accounts'];
        $running = $this->running();

        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($held, $states, $excluded, $running): array {
                $state = $states->get($account->id);
                $inDatabase = AccountContactsService::inDatabase($account->id);

                /* Never read, never cleaned: the account's own number is not known. */
                $known = $state !== null && ($state->synced_at !== null || $state->cleared_account_at !== null);
                $inAccount = $known ? (int) $state->in_account : null;

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'is_primary' => $account->isPrimary(),
                    'usable' => $this->contacts->usable($account),
                    'excluded' => in_array($account->id, $excluded, true),
                    'held' => $held->has($account->id),
                    'held_reason' => $held->get($account->id)?->disabled_reason,
                    'in_database' => $inDatabase,
                    'in_account' => $inAccount,
                    'agree' => match (true) {
                        $inAccount === null => 'unknown',
                        $inAccount === $inDatabase => 'match',
                        $inDatabase < $inAccount => 'database_less',
                        default => 'account_less',
                    },
                    'synced_at' => $state?->synced_at,
                    'cleared_account_at' => $state?->cleared_account_at,
                    'cleared_db_at' => $state?->cleared_db_at,
                    'status' => $this->status($state, $running),
                    'action' => $state?->action,
                    'error' => $state?->status === TelegramAccountContactState::FAILED ? $state->last_error : null,
                ];
            })
            ->values();

        return response()->json(['data' => [
            'running' => $running,
            'accounts' => $accounts,
        ]]);
    }

    /**
     * Read one account's contacts into the database.
     */
    public function import(TelegramAccount $account): JsonResponse
    {
        if ($refusal = $this->refuse($account)) {
            return $refusal;
        }

        $this->queue([$account->id], TelegramAccountContactState::IMPORT);

        StartAccountContactsJob::dispatch('import', $account->id)->onQueue('telegram');

        return response()->json(['message' => __('driver_check.accounts.messages.import_queued')]);
    }

    /**
     * Read every usable account, one after the other.
     */
    public function importAll(): JsonResponse
    {
        if ($this->busy()) {
            return $this->busyResponse();
        }

        $ids = TelegramAccount::query()
            ->where('is_authorized', true)
            ->get()
            ->filter(fn (TelegramAccount $account) => $this->contacts->usable($account))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return response()->json(['message' => __('driver_check.accounts.messages.none_usable')], 422);
        }

        $this->queue($ids, TelegramAccountContactState::IMPORT);

        StartAccountContactsJob::dispatch('import', null)->onQueue('telegram');

        return response()->json(['message' => __('driver_check.accounts.messages.import_all_queued', ['n' => count($ids)])]);
    }

    /**
     * Delete from the Telegram account the contacts the database lists for it.
     */
    public function clearAccount(Request $request, TelegramAccount $account): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        if ($refusal = $this->refuse($account)) {
            return $refusal;
        }

        $this->queue([$account->id], TelegramAccountContactState::CLEAR_ACCOUNT);

        StartAccountContactsJob::dispatch('clear', $account->id)->onQueue('telegram');

        return response()->json(['message' => __('driver_check.accounts.messages.clear_account_queued')]);
    }

    /**
     * Forget one account's contacts in the database; the account keeps them.
     */
    public function clearDatabase(Request $request, TelegramAccount $account): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        if ($this->busy()) {
            return $this->busyResponse();
        }

        $done = $this->contacts->clearDatabase($account);

        return response()->json(['message' => __('driver_check.accounts.messages.database_cleared', ['n' => $done['holders'] + $done['links']])]);
    }

    /**
     * Forget every account's contacts in the database.
     */
    public function clearDatabaseAll(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        if ($this->busy()) {
            return $this->busyResponse();
        }

        $done = $this->contacts->clearDatabase();

        return response()->json(['message' => __('driver_check.accounts.messages.database_cleared', ['n' => $done['holders'] + $done['links']])]);
    }

    /**
     * Whether an account contacts run is going (the lock the command holds).
     */
    private function running(): bool
    {
        return TelegramProcessLock::isHeld(TelegramAccountContactsCommand::LOCK_NAME);
    }

    /**
     * Anything that works on the contacts is going: a read, a clean-up or the
     * sync itself. The database is not cleaned from under it.
     */
    private function busy(): bool
    {
        return $this->running()
            || TelegramProcessLock::isHeld(TelegramSyncContactsCommand::LOCK_NAME);
    }

    private function busyResponse(): JsonResponse
    {
        return response()->json(['message' => __('driver_check.accounts.messages.busy')], 409);
    }

    /**
     * The reason an account cannot be started on, as a response - or null.
     */
    private function refuse(TelegramAccount $account): ?JsonResponse
    {
        if ($account->isPrimary()) {
            return response()->json(['message' => __('driver_check.accounts.messages.listener')], 422);
        }

        if (! $this->contacts->usable($account)) {
            return response()->json(['message' => __('driver_check.accounts.messages.unusable')], 422);
        }

        return $this->busy() ? $this->busyResponse() : null;
    }

    /**
     * Show them as waiting at once, before the command has started.
     *
     * @param list<int> $ids
     */
    private function queue(array $ids, string $action): void
    {
        foreach ($ids as $id) {
            TelegramAccountContactState::of($id)->update([
                'status' => TelegramAccountContactState::QUEUED,
                'action' => $action,
                'last_error' => null,
            ]);
        }
    }

    /**
     * What the page shows: a read or a clean-up that says it is waiting or
     * running while nothing holds the lock was cut short.
     */
    private function status(?TelegramAccountContactState $state, bool $running): string
    {
        if ($state === null) {
            return TelegramAccountContactState::IDLE;
        }

        if (in_array($state->status, [TelegramAccountContactState::QUEUED, TelegramAccountContactState::RUNNING], true) && ! $running) {
            /* A freshly queued one has a moment before the command takes the lock. */
            return $state->updated_at !== null && $state->updated_at->gt(now()->subMinute())
                ? $state->status
                : TelegramAccountContactState::FAILED;
        }

        return $state->status;
    }
}
