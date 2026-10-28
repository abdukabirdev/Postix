<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\ContactSyncService;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Throwable;

final class TelegramSyncContactsCommand extends Command
{
    /**
     * Held for as long as a sync runs: the panel reads it to say "running",
     * and a second run (cron plus the Run button) is refused.
     */
    public const LOCK_NAME = 'contact-sync';

    protected $signature = 'telegram:sync-contacts {--group= : Only this group id} {--members-only : Only fetch the group\'s people into the database, no contacts (needs --group)} {--trigger=manual : Who started it: manual or schedule}';

    protected $description = 'Save the members of the watched groups into the accounts\' contacts';

    public function handle(ContactSyncService $sync): int
    {
        /*
         * An exception thrown in a MadelineProto callback ("The endpoint does
         * not exist!" from its IPC client) would take the whole run down in the
         * middle of an account. It is logged, and the account's own call fails
         * or times out on its own.
         */
        EventLoop::setErrorHandler(static function (Throwable $e): void {
            Log::error('Contact sync: an exception in the event loop', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        });

        $groupId = $this->option('group') ? (int) $this->option('group') : null;
        $membersOnly = (bool) $this->option('members-only');

        if ($membersOnly && $groupId === null) {
            $this->error('--members-only needs --group.');

            return self::FAILURE;
        }

        /* A group's people are fetched beside the sync and beside other groups: the accounts are claimed one by one anyway. */
        $lock = $membersOnly ? self::membersLock($groupId) : self::LOCK_NAME;

        if (TelegramProcessLock::attempt($lock) === TelegramProcessLock::HELD) {
            $this->warn($membersOnly ? 'This group\'s people are being fetched already.' : 'Another contact sync is already running.');

            return self::SUCCESS;
        }

        if ($membersOnly) {
            Cache::forget(self::membersQueuedKey($groupId));
        }

        try {
            $say = fn (string $line) => $this->line($line);
            $trigger = $this->option('trigger') === 'schedule' ? 'schedule' : 'manual';

            $totals = $membersOnly
                ? $sync->fetchMembers($groupId, $say, $trigger)
                : $sync->run($groupId, $say, $trigger);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release($lock);
        }

        $this->info(sprintf(
            'Accounts: %d, members read: %d, new in the database: %d, added: %d, failed: %d',
            $totals['accounts'],
            $totals['members'],
            $totals['new'],
            $totals['added'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }

    /**
     * Held while one group's people are fetched: the page shows the group as
     * busy, and the button pressed twice starts it once.
     */
    public static function membersLock(int $groupId): string
    {
        return 'contact-sync-members-' . $groupId;
    }

    /**
     * Set by the button until the process takes the lock: the few seconds
     * between the two are "busy" too.
     */
    public static function membersQueuedKey(int $groupId): string
    {
        return 'contact-sync:members-queued:' . $groupId;
    }
}
