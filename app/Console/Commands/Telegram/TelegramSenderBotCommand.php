<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\SenderBotService;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * The sender bot: answers people in its chat and posts what is due into
 * the groups. Started by the scheduler every minute while it is on in the
 * panel; each start asks Telegram for under a minute and ends.
 */
final class TelegramSenderBotCommand extends Command
{
    public const LOCK_NAME = 'sender-bot';

    protected $signature = 'telegram:sender-bot
        {--seconds=55 : How long to keep asking Telegram before ending}';

    protected $description = 'Run the sender bot: its chats, and the posts into the groups';

    public function handle(SenderBotService $bot): int
    {
        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            $this->warn('The sender bot is already being run by another process.');

            return self::SUCCESS;
        }

        try {
            $totals = $bot->poll(
                max(5, min(3600, (int) $this->option('seconds'))),
                fn (string $line) => $this->line(now()->format('H:i:s') . ' ' . $line),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        if (array_sum($totals) > 0) {
            $this->info(sprintf('Updates: %d, copies posted: %d', $totals['updates'], $totals['copied']));
        }

        return self::SUCCESS;
    }
}
