<?php

namespace App\Jobs\Telegram;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

/**
 * The Run button of the contact sync page: nohups telegram:sync-contacts
 * so the queue worker is not held for the hour a full run takes. The same
 * for a group's "fetch people" button, with --members-only.
 */
class StartContactSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $groupId = null,
        public readonly bool $membersOnly = false,
    ) {}

    public function handle(): void
    {
        $command = sprintf(
            'cd %s && nohup %s %s telegram:sync-contacts --trigger=manual%s%s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $this->groupId ? ' --group=' . (int) $this->groupId : '',
            $this->membersOnly ? ' --members-only' : '',
            escapeshellarg(storage_path('logs/contact-sync.log')),
        );

        Process::fromShellCommandline($command)->setTimeout(10)->run();
    }
}
