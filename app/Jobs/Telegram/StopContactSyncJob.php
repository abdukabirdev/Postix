<?php

namespace App\Jobs\Telegram;

use App\Application\Telegram\Services\ContactSyncService;
use App\Console\Commands\Telegram\TelegramSyncContactsCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Models\Telegram\TelegramSetting;
use App\Telegram\TelegramProcessLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The second half of the contact sync's Stop button. The button asks the
 * run to stop; a run still going ContactSyncService::STOP_GRACE later
 * answers nothing - it hangs on a session - and is killed here.
 *
 * The worker runs as the same user as the scheduled run (root), so its
 * signal gets through; the web server's would not.
 *
 * A killed run cleans nothing up after itself, so it is done here: the
 * account it held is given back, the person it was adding goes back to the
 * others, and its end is written into the log - the report says "stopped",
 * not "interrupted".
 */
class StopContactSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Only about the run of this moment: later it would hit whatever runs by then. */
    public int $tries = 1;

    /**
     * @param string|null $runId the run the button was pressed for; null for one that does not write its id down
     */
    public function __construct(public readonly ?string $runId) {}

    public function handle(): void
    {
        $lock = TelegramSyncContactsCommand::LOCK_NAME;

        /* Stopped by itself. */
        if (! TelegramProcessLock::isHeld($lock)) {
            return;
        }

        $now = ContactSyncService::current();

        /* Another run began since the button: not this one's to kill. */
        if ($this->runId !== null && ($now['run_id'] ?? null) !== $this->runId) {
            return;
        }

        $pid = TelegramProcessLock::holderPid($lock);

        if ($pid === null || ! $this->isSync($pid)) {
            Log::error('Contact sync stop: the stuck run is not found', ['pid' => $pid, 'run_id' => $this->runId]);

            return;
        }

        $this->signal($pid, 15);

        if (! $this->released($lock, 15)) {
            $this->signal($pid, 9);
            $this->released($lock, 10);
        }

        if (TelegramProcessLock::isHeld($lock)) {
            Log::error('Contact sync stop: the stuck run could not be killed', ['pid' => $pid, 'run_id' => $this->runId]);

            return;
        }

        $this->cleanUp($now, $pid);
    }

    /**
     * What the run would have done on its way out.
     *
     * @param array<string, mixed> $now
     */
    private function cleanUp(array $now, int $pid): void
    {
        $accountId = isset($now['account_id']) ? (int) $now['account_id'] : null;

        /* A group's "fetch people" may hold an account right now: then only the run's own one is given back. */
        $fetching = TelegramScrapeGroup::query()
            ->pluck('id')
            ->contains(fn ($id) => TelegramProcessLock::isHeld(TelegramSyncContactsCommand::membersLock((int) $id)));

        TelegramAccountProcess::query()
            ->where('process', ProcessEnum::ContactSync->value)
            ->where('is_busy', true)
            ->when($fetching, fn ($q) => $q->where('telegram_account_id', $accountId ?? 0))
            ->update(['is_busy' => false, 'busy_at' => null]);

        /* Only the sync takes people for an account: with it gone, whoever is still "taking" is nobody's. */
        TelegramAccountContact::query()
            ->where('status', TelegramAccountContact::STATUS_PENDING)
            ->delete();

        $start = TelegramContactSyncLog::query()->where('event', 'started')->whereNull('group_id')->latest('id')->first();

        if ($start !== null && ! TelegramContactSyncLog::query()->where('run_id', $start->run_id)->where('event', 'finished')->exists()) {
            $logs = fn () => TelegramContactSyncLog::query()->where('run_id', $start->run_id);

            TelegramContactSyncLog::query()->create([
                'run_id' => $start->run_id,
                'event' => 'finished',
                'message' => 'Stopped from the panel: the run did not answer and was killed',
                'context' => [
                    'accounts' => $logs()->whereNotNull('telegram_account_id')->distinct()->count('telegram_account_id'),
                    'added' => $logs()->where('event', 'added')->count(),
                    'failed' => $logs()->where('event', 'failed')->count(),
                    'members' => 0,
                    'new' => 0,
                    'stopped' => true,
                    'killed' => true,
                    'pid' => $pid,
                ],
            ]);
        }

        TelegramSetting::set(ContactSyncService::NOW_KEY, null);
        TelegramSetting::set(ContactSyncService::STOP_KEY, null);

        Log::warning('Contact sync stop: the stuck run was killed', ['pid' => $pid, 'account_id' => $accountId]);
    }

    /** The pid in the lock file is the sync's still, not a process that got the number since. */
    private function isSync(int $pid): bool
    {
        $path = "/proc/{$pid}/cmdline";

        if (! is_readable($path)) {
            return false;
        }

        $cmdline = str_replace("\0", ' ', (string) @file_get_contents($path));

        return str_contains($cmdline, 'artisan') && str_contains($cmdline, 'telegram:sync-contacts');
    }

    private function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, $signal);

            return;
        }

        try {
            (new Process(['kill', '-' . $signal, (string) $pid]))->setTimeout(5)->run();
        } catch (Throwable) {
            // checked by the lock right after
        }
    }

    /** Waits up to $seconds for the lock to come free. */
    private function released(string $lock, int $seconds): bool
    {
        for ($i = 0; $i < $seconds; $i++) {
            if (! TelegramProcessLock::isHeld($lock)) {
                return true;
            }

            sleep(1);
        }

        return ! TelegramProcessLock::isHeld($lock);
    }
}
