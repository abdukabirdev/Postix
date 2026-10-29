<?php

namespace App\Jobs\Telegram;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

/**
 * The Run button: nohups telegram:group-invites so the queue worker is not
 * held for the hours a full day of batches takes.
 */
class StartGroupInviteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $command = sprintf(
            'cd %s && nohup %s %s telegram:group-invites --trigger=manual >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg(storage_path('logs/group-invite.log')),
        );

        Process::fromShellCommandline($command)->setTimeout(10)->run();
    }
}
