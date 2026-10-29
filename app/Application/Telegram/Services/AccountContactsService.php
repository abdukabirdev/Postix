<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\DeadSession;
use App\Application\Telegram\Support\SessionSleep;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContactState;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramScrapedUser;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * What the accounts really hold in their Telegram contacts, and the database
 * kept in step with it - read from the account, cleaned from the account,
 * cleaned from the database - each on its own.
 *
 * "The database has 0, the accounts have hundreds": import() reads an
 * account's contacts into the database (people, and who holds them).
 * clearAccount() deletes the contacts the database knows of from the Telegram
 * account. clearDatabase() forgets them in the database and touches no
 * account. Telegram and the database are two places; each has its own button
 * and its own count (TelegramAccountContactState keeps what the account had).
 *
 * The listener's account (Driver Check) is left out of the two that need a
 * session: it is open in the listener, and a second process cannot open the
 * same session.
 */
final class AccountContactsService
{
    /** How many contacts one deleteContacts call carries. */
    private const CHUNK = 100;

    /** A busy flag older than this is a crashed process, not a running one. */
    private const STALE_BUSY_MINUTES = 180;

    public function __construct(private readonly MadelineService $madeline) {}

    /**
     * Whether import() and clearAccount() may use this account's session.
     */
    public function usable(TelegramAccount $account): bool
    {
        return ! $account->isPrimary()
            && $account->is_authorized
            && filled($account->session_path);
    }

    /**
     * Read the account's contacts into the database.
     *
     * @param callable(string): void|null $say
     *
     * @return array{contacts: int, new_people: int, removed: int}
     */
    public function import(TelegramAccount $account, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};

        return $this->withSession($account, TelegramAccountContactState::IMPORT, function ($api, TelegramAccountContactState $state) use ($account, $say): array {
            $result = $api->contacts->getContacts();

            $users = collect($result['users'] ?? [])->keyBy(fn ($user) => (int) ($user['id'] ?? 0));

            /* Our own accounts are not somebody's lead to collect. */
            $own = TelegramAccount::query()
                ->whereNotNull('telegram_user_id')
                ->pluck('telegram_user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $token = (string) Str::uuid();
            $now = now();
            $count = 0;
            $new = 0;

            foreach (array_chunk((array) ($result['contacts'] ?? []), 200) as $chunk) {
                DB::transaction(function () use ($chunk, $users, $own, $account, $token, $now, &$count, &$new): void {
                    foreach ($chunk as $contact) {
                        $id = (int) ($contact['user_id'] ?? 0);

                        if ($id <= 0 || in_array($id, $own, true)) {
                            continue;
                        }

                        $person = TelegramScrapedUser::query()->firstOrNew(['telegram_user_id' => $id]);
                        $known = $users->get($id) ?? [];

                        /* Only what Telegram sent: a bare contact must not blank what a group gave. */
                        foreach (['username', 'first_name', 'last_name', 'phone'] as $field) {
                            if (isset($known[$field]) && $known[$field] !== '') {
                                $person->{$field} = $known[$field];
                            }
                        }

                        $person->is_bot = ($known['bot'] ?? false) || ($known['type'] ?? 'user') === 'bot' || $person->is_bot;

                        if (! $person->exists) {
                            $new++;
                        }

                        $person->save();

                        DB::table('telegram_account_contact_links')->upsert(
                            [[
                                'telegram_account_id' => $account->id,
                                'scraped_user_id' => $person->id,
                                'sync_token' => $token,
                                'synced_at' => $now,
                            ]],
                            ['telegram_account_id', 'scraped_user_id'],
                            ['sync_token', 'synced_at'],
                        );

                        /* Nobody holds them in the database yet: this account does, as far as it knows. */
                        DB::table('telegram_account_contacts')->insertOrIgnore([
                            'telegram_account_id' => $account->id,
                            'scraped_user_id' => $person->id,
                            'status' => 'added',
                            'added_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $count++;
                    }
                });
            }

            /* What this read did not carry is no longer in the account. */
            $removed = DB::table('telegram_account_contact_links')
                ->where('telegram_account_id', $account->id)
                ->where(fn ($q) => $q->whereNull('sync_token')->orWhere('sync_token', '!=', $token))
                ->delete();

            $state->update(['in_account' => $count, 'synced_at' => now()]);

            $say("  {$count} contacts, {$new} new people, {$removed} gone from the account");

            return ['contacts' => $count, 'new_people' => $new, 'removed' => $removed];
        });
    }

    /**
     * Delete from the Telegram account the contacts the database knows of
     * (the ones it holds for the account, as read or as saved by the sync),
     * and only those that are in the account now. The database keeps its
     * records: that is clearDatabase().
     *
     * @param callable(string): void|null $say
     *
     * @return array{deleted: int, left: int}
     */
    public function clearAccount(TelegramAccount $account, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};

        return $this->withSession($account, TelegramAccountContactState::CLEAR_ACCOUNT, function ($api, TelegramAccountContactState $state) use ($account, $say): array {
            $present = collect($api->contacts->getContacts()['contacts'] ?? [])
                ->map(fn ($contact) => (int) ($contact['user_id'] ?? 0))
                ->filter()
                ->values();

            $known = $this->knownIds($account);

            $target = $present->intersect($known)->values();
            $deleted = 0;

            foreach ($target->chunk(self::CHUNK) as $chunk) {
                $ids = $chunk->values()->all();

                $api->contacts->deleteContacts(id: $ids);

                $deleted += count($ids);

                DB::table('telegram_account_contact_links')
                    ->where('telegram_account_id', $account->id)
                    ->whereIn('scraped_user_id', TelegramScrapedUser::query()->whereIn('telegram_user_id', $ids)->select('id'))
                    ->delete();

                $say("  deleted {$deleted} of {$target->count()}");

                sleep(random_int(1, 2));
            }

            $left = max(0, $present->count() - $deleted);

            $state->update(['in_account' => $left, 'cleared_account_at' => now()]);

            return ['deleted' => $deleted, 'left' => $left];
        });
    }

    /**
     * Forget the contacts in the database - one account's, or everybody's.
     * No account is touched. People who are in a watched group stay (they
     * come from the group); people known only from an account's contacts go.
     *
     * @return array{links: int, holders: int, people: int}
     */
    public function clearDatabase(?TelegramAccount $account = null): array
    {
        return DB::transaction(function () use ($account): array {
            $links = DB::table('telegram_account_contact_links')
                ->when($account, fn ($q) => $q->where('telegram_account_id', $account->id))
                ->delete();

            $holders = DB::table('telegram_account_contacts')
                ->when($account, fn ($q) => $q->where('telegram_account_id', $account->id))
                ->delete();

            $people = TelegramScrapedUser::query()
                ->whereDoesntHave('contact')
                ->whereDoesntHave('accountLinks')
                ->whereDoesntHave('groups')
                ->delete();

            TelegramAccountContactState::query()
                ->when($account, fn ($q) => $q->where('telegram_account_id', $account->id))
                ->update(['cleared_db_at' => now()]);

            return ['links' => $links, 'holders' => $holders, 'people' => $people];
        });
    }

    /**
     * How many people the database lists for an account: the ones it holds
     * (saved by the sync, or read from the account) and the ones it saw in
     * the account's contacts.
     */
    public static function inDatabase(int $accountId): int
    {
        $held = DB::table('telegram_account_contacts')
            ->where('telegram_account_id', $accountId)
            ->whereIn('status', ['added', 'skipped'])
            ->select('scraped_user_id');

        return DB::query()
            ->fromSub(
                DB::table('telegram_account_contact_links')
                    ->where('telegram_account_id', $accountId)
                    ->select('scraped_user_id')
                    ->union($held),
                'people',
            )
            ->count();
    }

    /**
     * Telegram ids of the people the database lists for the account.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function knownIds(TelegramAccount $account)
    {
        $held = DB::table('telegram_account_contacts')
            ->where('telegram_account_id', $account->id)
            ->whereIn('status', ['added', 'skipped'])
            ->select('scraped_user_id');

        $linked = DB::table('telegram_account_contact_links')
            ->where('telegram_account_id', $account->id)
            ->select('scraped_user_id');

        return TelegramScrapedUser::query()
            ->whereIn('id', $held)
            ->orWhereIn('id', $linked)
            ->pluck('telegram_user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * One run of $work on the account's own session: the account marked busy
     * for the sync's process meanwhile, its state row kept up to date, the
     * session stopped at the end.
     *
     * @param callable(\danog\MadelineProto\API, TelegramAccountContactState): array $work
     *
     * @return array<string, int>
     */
    private function withSession(TelegramAccount $account, string $action, callable $work): array
    {
        if (! $this->usable($account)) {
            throw new RuntimeException($account->isPrimary()
                ? 'The Driver Check account is open in the listener: its contacts cannot be read from here.'
                : 'The account is not authorized or has no session.');
        }

        if (! $this->claim($account)) {
            throw new RuntimeException('The account is busy with something else.');
        }

        $state = TelegramAccountContactState::of($account->id);
        $state->update(['status' => TelegramAccountContactState::RUNNING, 'action' => $action, 'last_error' => null]);

        $api = null;

        try {
            $api = $this->madeline->for($account);

            if (! $api) {
                throw $this->madeline->lastError ?? new RuntimeException('Failed to start MadelineProto.');
            }

            $result = $work($api, $state);

            $state->update(['status' => TelegramAccountContactState::IDLE, 'action' => null]);

            return $result;
        } catch (Throwable $e) {
            $code = $this->accountError($e);

            $state->update([
                'status' => TelegramAccountContactState::FAILED,
                'action' => null,
                'last_error' => mb_substr($code ?? ($e instanceof RPCErrorException ? $e->rpc : $e->getMessage()), 0, 500),
            ]);

            /*
             * A dead session is dead for every process: the same state
             * telegram:account-check writes, so no pool picks the account up
             * and the sessions page shows it as revoked, to log in again.
             */
            if ($code !== null) {
                $account->update([
                    'is_authorized' => false,
                    'status' => TelegramAccount::STATUS_REVOKED,
                    'last_checked_at' => now(),
                    'last_error' => $code,
                ]);
            }

            throw $e;
        } finally {
            SessionSleep::put($api, ['account_id' => $account->id]);

            $this->release($account);
        }
    }

    /**
     * The code of a dead-session error anywhere in the chain, or null
     * (see DeadSession).
     */
    public function accountError(Throwable $e): ?string
    {
        return DeadSession::code($e);
    }

    /**
     * Mark the account busy for the contact sync's process, unless something
     * is already working on it.
     */
    private function claim(TelegramAccount $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            $process = TelegramAccountProcess::query()
                ->where('telegram_account_id', $account->id)
                ->where('process', ProcessEnum::ContactSync->value)
                ->lockForUpdate()
                ->first();

            $process ??= TelegramAccountProcess::query()->create([
                'telegram_account_id' => $account->id,
                'process' => ProcessEnum::ContactSync->value,
                'successes' => 0,
                'failures' => 0,
                'consecutive_failures' => 0,
                'is_available' => true,
                'is_busy' => false,
            ]);

            if ($process->is_busy && $process->busy_at !== null && $process->busy_at->gt(now()->subMinutes(self::STALE_BUSY_MINUTES))) {
                return false;
            }

            $process->update(['is_busy' => true, 'busy_at' => now()]);

            return true;
        });
    }

    private function release(TelegramAccount $account): void
    {
        TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::ContactSync->value)
            ->update(['is_busy' => false, 'busy_at' => null]);
    }
}
