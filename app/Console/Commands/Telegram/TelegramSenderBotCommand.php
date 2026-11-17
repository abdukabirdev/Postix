<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\SenderBotService;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;
use Throwable;

/**
 * The sender bot's posts: what is due goes into the groups. Started by the
 * scheduler every minute while the bot is on in the panel; each start runs
 * for under a minute and ends. The people's messages come by the webhook
 * (izicrm.uz forwards them), never asked for here.
 */
final class TelegramSenderBotCommand extends Command
{
    public const LOCK_NAME = 'sender-bot';

    protected $signature = 'telegram:sender-bot
        {--seconds=55 : How long to keep posting before ending}';

    protected $description = 'Post the messages of the sender bot that are due into the groups';

    public function handle(SenderBotService $bot): int
    {
        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            $this->warn('The sender bot is already being run by another process.');

            return self::SUCCESS;
        }

        try {
            $totals = $bot->run(
                max(5, min(3600, (int) $this->option('seconds'))),
                fn (string $line) => $this->line(now()->format('H:i:s') . ' ' . $line),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        if ($totals['copied'] > 0) {
            $this->info(sprintf('Copies posted: %d', $totals['copied']));
        }

        return self::SUCCESS;
    }
}
