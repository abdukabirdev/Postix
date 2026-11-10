<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\SpamBotCheck;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountSpamCheck;
use App\Telegram\TelegramProcessLock;
use Illuminate\Console\Command;

/**
 * Asks @SpamBot about our accounts, one after another: whether Telegram
 * limits each and until when. The accounts page starts it for one account
 * or for all of them (StartSpamBotCheckJob); every answer is kept and shown
 * on the account's card.
 */
final class TelegramSpamBotCheckCommand extends Command
{
    public const LOCK_NAME = 'spambot-check';

    protected $signature = 'telegram:spambot-check
        {account? : One account id}
        {--all : Every authorized account with a session}';

    protected $description = 'Ask @SpamBot whether our accounts are limited by Telegram, and until when';

    public function handle(SpamBotCheck $check): int
    {
        if (TelegramProcessLock::attempt(self::LOCK_NAME) === TelegramProcessLock::HELD) {
            $this->warn('@SpamBot is already being asked by another process.');

            return self::SUCCESS;
        }

        try {
            $accounts = $this->accounts();

            if ($accounts->isEmpty()) {
                $this->warn('No account to ask about.');

                return self::SUCCESS;
            }

            foreach ($accounts as $account) {
                $this->line(now()->format('H:i:s') . " #{$account->id} {$account->phone}: asking ...");

                $answer = $check->ask($account);

                $this->line(now()->format('H:i:s') . " #{$account->id}: " . self::words($answer));
            }
        } finally {
            TelegramProcessLock::release(self::LOCK_NAME);
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, TelegramAccount>
     */
    private function accounts(): \Illuminate\Support\Collection
    {
        $id = $this->argument('account');

        if ($id !== null) {
            return TelegramAccount::query()->whereKey((int) $id)->get();
        }

        if (! $this->option('all')) {
            $this->error('Give an account id or --all.');

            return collect();
        }

        return TelegramAccount::query()
            ->where('is_authorized', true)
            ->whereNotNull('session_path')
            ->orderBy('id')
            ->get();
    }

    private static function words(TelegramAccountSpamCheck $answer): string
    {
        return match ($answer->status) {
            TelegramAccountSpamCheck::FREE => 'free',
            TelegramAccountSpamCheck::LIMITED => 'limited' . ($answer->limited_until !== null ? ' until ' . $answer->limited_until->toDateTimeString() : ''),
            TelegramAccountSpamCheck::UNKNOWN => 'answered, not understood: ' . mb_substr((string) $answer->text, 0, 120),
            default => 'failed: ' . $answer->error,
        };
    }
}
