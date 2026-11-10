<?php

namespace App\Jobs\Telegram;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

/**
 * The "Ask @SpamBot" buttons of the accounts page: nohups
 * telegram:spambot-check for one account or for all of them, so the queue
 * worker is not held while the sessions start one after another.
 */
class StartSpamBotCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public ?int $accountId = null) {}

    public function handle(): void
    {
        $command = sprintf(
            'cd %s && nohup %s %s telegram:spambot-check %s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $this->accountId !== null ? escapeshellarg((string) $this->accountId) : '--all',
            escapeshellarg(storage_path('logs/spambot-check.log')),
        );

        Process::fromShellCommandline($command)->setTimeout(10)->run();
    }
}
