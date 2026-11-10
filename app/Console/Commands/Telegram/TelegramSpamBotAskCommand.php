<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\MadelineService;
use App\Application\Telegram\Services\SpamBotCheck;
use App\Models\Telegram\TelegramAccount;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Started by SpamBotCheck, killed by it when it takes too long: the
 * account writes /start to @SpamBot, waits for the answer and says it.
 */
final class TelegramSpamBotAskCommand extends Command
{
    /** Telegram's bot that tells an account about its limits. */
    public const BOT = '@SpamBot';

    /** How long the answer is waited for. Seconds. */
    private const ANSWER_WAIT = 30;

    protected $signature = 'telegram:spambot-ask {account : The account id}';

    protected $description = '(started by the check) Ask @SpamBot whether the account is limited and say the answer';

    protected $hidden = true;

    public function handle(MadelineService $madeline): int
    {
        $account = TelegramAccount::query()->find((int) $this->argument('account'));

        if (! $account) {
            $this->line(SpamBotCheck::line(false, error: 'No such account'));

            return self::FAILURE;
        }

        try {
            $api = $madeline->for($account);

            if (! $api) {
                throw $madeline->lastError ?? new RuntimeException('Failed to start MadelineProto.');
            }

            $sentAt = time();

            $api->messages->sendMessage(peer: self::BOT, message: '/start');

            $answer = null;
            $until = time() + self::ANSWER_WAIT;

            while ($answer === null && time() < $until) {
                sleep(2);

                $history = $api->messages->getHistory(peer: self::BOT, limit: 5);

                foreach ((array) ($history['messages'] ?? []) as $message) {
                    if (! is_array($message) || ($message['_'] ?? null) !== 'message' || ! empty($message['out'])) {
                        continue;
                    }

                    /* The bot's reply to this /start, not an old one. */
                    if ((int) ($message['date'] ?? 0) >= $sentAt - 5 && trim((string) ($message['message'] ?? '')) !== '') {
                        $answer = trim((string) $message['message']);

                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->line(SpamBotCheck::line(false, error: mb_substr(self::code($e), 0, 500)));

            return self::FAILURE;
        }

        if ($answer === null) {
            $this->line(SpamBotCheck::line(false, error: 'No answer from @SpamBot in ' . self::ANSWER_WAIT . ' s'));

            return self::FAILURE;
        }

        $this->line(SpamBotCheck::line(true, $answer));

        return self::SUCCESS;
    }

    /**
     * Telegram's own code where there is one (MadelineProto often wraps it),
     * the message otherwise.
     */
    private static function code(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RPCErrorException) {
                return $current->rpc;
            }
        }

        return $e->getMessage();
    }
}
