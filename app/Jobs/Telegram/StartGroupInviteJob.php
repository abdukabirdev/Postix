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
 * held for the hours a full day of batches takes. The Check button: the
 * same command, only looking up the people already sent. "Who left": the
 * same command, looking everybody added up again.
 */
class StartGroupInviteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public bool $check = false, public bool $left = false) {}

    public function handle(): void
    {
        $command = sprintf(
            'cd %s && nohup %s %s telegram:group-invites --trigger=manual%s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $this->left ? ' --left' : ($this->check ? ' --check' : ''),
            escapeshellarg(storage_path('logs/group-invite.log')),
        );

        Process::fromShellCommandline($command)->setTimeout(10)->run();
    }
}
