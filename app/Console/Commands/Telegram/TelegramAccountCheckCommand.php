<?php

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Support\SessionSleep;
use App\Application\Telegram\Support\VendorNoticeShield;
use App\Console\Commands\Telegram\Concerns\OpensTelegramAccountSession;
use App\Models\Telegram\TelegramAccount;
use danog\MadelineProto\API;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Telegram whether an authorized session still works, and refreshes
 * the profile shown on the sessions page while at it.
 *
 * A session the user killed from "Active sessions" on their phone looks
 * perfectly fine locally until the first request comes back with
 * AUTH_KEY_UNREGISTERED - this is that first request, made on purpose.
 *
 * --all goes through every authorized account, which is also how the
 * profile of accounts logged in before it was stored gets filled in.
 */
class TelegramAccountCheckCommand extends Command
{
    use OpensTelegramAccountSession;

    protected $signature = 'telegram:account-check
                            {accountId? : Account to check}
                            {--all : Check every authorized account that is free right now}';

    protected $description = 'Check that a TelegramAccount session is still alive';

    /**
     * Errors that mean the session is gone for good. Anything else
     * (flood wait, a network hiccup) says nothing about the session.
     */
    private const REVOKED_ERRORS = [
        'AUTH_KEY_UNREGISTERED',
        'AUTH_KEY_INVALID',
        'AUTH_KEY_DUPLICATED',
        'SESSION_REVOKED',
        'SESSION_EXPIRED',
        'USER_DEACTIVATED',
        'USER_DEACTIVATED_BAN',
    ];

    /**
     * Statuses a live session does not keep after a check. Everything
     * else - running / stopped from the listener, success - stays.
     */
    private const REPLACED_STATUSES = [
        null,
        '',
        TelegramAccount::STATUS_CREATED,
        TelegramAccount::STATUS_PROCESSING,
        TelegramAccount::STATUS_CODE_SENT,
        TelegramAccount::STATUS_CODE_INVALID,
        TelegramAccount::STATUS_VERIFYING,
        TelegramAccount::STATUS_NEED_PASSWORD,
        TelegramAccount::STATUS_PASSWORD_INVALID,
        TelegramAccount::STATUS_FAILED,
        TelegramAccount::STATUS_CHECKING,
        TelegramAccount::STATUS_REVOKED,
        TelegramAccount::STATUS_LOGGING_OUT,
        TelegramAccount::STATUS_LOGGED_OUT,
    ];

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->checkAll();
        }

        $account = TelegramAccount::find((int) $this->argument('accountId'));

        if (! $account) {
            $this->error('Account not found (pass an id, or --all)');
            return self::FAILURE;
        }

        return $this->check($account) ? self::SUCCESS : self::FAILURE;
    }

    private function checkAll(): int
    {
        $accounts = TelegramAccount::query()
            ->where('is_authorized', true)
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($accounts as $account) {
            /*
             * Same rules as the panel (TelegramAccountSessionManager::check):
             * a session somebody else holds right now is left alone.
             */
            $skip = match (true) {
                $account->isListening() => 'listener is running',
                $account->hasBusyProcess() => 'a process is using it',
                $account->isInFlight() && ! $account->isStale() => 'another command is working on it',
                default => null,
            };

            if ($skip !== null) {
                $this->line("⏭  #{$account->id} {$account->phone}: skipped, {$skip}");
                continue;
            }

            if (! $this->check($account)) {
                $failed++;
            }
        }

        $this->info("Checked {$accounts->count()} account(s), {$failed} failed");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(TelegramAccount $account): bool
    {
        $statusBefore = Cache::pull(
            TelegramAccount::statusBeforeCheckCacheKey($account->id),
            $account->status,
        );

        if (! $account->hasSessionFile()) {
            $this->markRevoked($account, 'SESSION_NOT_FOUND');

            $this->error("❌ #{$account->id} {$account->phone}: session not found");
            return false;
        }

        try {
            $self = VendorNoticeShield::guard(
                'telegram.account-check',
                function () use ($account): array|false {
                    $Madeline = $this->openSession($account);

                    try {
                        if ($Madeline->getAuthorization() !== API::LOGGED_IN) {
                            return false;
                        }

                        /*
                         * getSelf() only reads the cached copy; this goes to
                         * the server, which is the whole point.
                         */
                        return $Madeline->users->getUsers(
                            id: [['_' => 'inputUserSelf']]
                        )[0] ?? false;
                    } finally {
                        SessionSleep::put($Madeline, ['account_id' => $account->id]);
                    }
                },
                ['account_id' => $account->id],
            );

            if (! is_array($self)) {
                $this->markRevoked($account, 'NOT_LOGGED_IN');

                $this->error("❌ #{$account->id} {$account->phone}: not logged in");
                return false;
            }

            $account->update([
                ...TelegramAccount::profileFrom($self),
                'is_authorized' => true,
                'status' => $this->settledStatus($statusBefore),
                'last_checked_at' => now(),
                'last_error' => null,
            ]);

            $this->info("✅ #{$account->id} {$account->phone}: alive");
            return true;
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);

            if ($this->isRevocation($message)) {
                $this->markRevoked($account, $message);
            } else {
                $account->update([
                    'status' => $account->is_authorized
                        ? $this->settledStatus($statusBefore)
                        : TelegramAccount::STATUS_FAILED,
                    'last_checked_at' => now(),
                    'last_error' => $message,
                ]);
            }

            Log::warning('telegram:account-check failed', [
                'account_id' => $account->id,
                'phone' => $account->phone,
                'error' => $message,
            ]);

            $this->error("❌ #{$account->id} {$account->phone}: {$message}");
            return false;
        }
    }

    private function settledStatus(?string $statusBefore): string
    {
        return in_array($statusBefore, self::REPLACED_STATUSES, true)
            ? TelegramAccount::STATUS_SUCCESS
            : $statusBefore;
    }

    private function isRevocation(string $message): bool
    {
        foreach (self::REVOKED_ERRORS as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Out of every pool at once: the process picker only takes authorized
     * accounts, so nothing will try this session again.
     */
    private function markRevoked(TelegramAccount $account, string $reason): void
    {
        $account->update([
            'is_authorized' => false,
            'status' => TelegramAccount::STATUS_REVOKED,
            'last_checked_at' => now(),
            'last_error' => $reason,
        ]);
    }
}
