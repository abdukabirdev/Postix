<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\MadelineService;
use App\Application\Telegram\Support\SessionProbe;
use App\Models\Telegram\TelegramAccount;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Started by a run (SessionProbe), killed by it when it takes too long:
 * starts the account's session, makes one request, says how it went. The
 * session is not put to sleep - the run starts it next.
 */
final class TelegramSessionProbeCommand extends Command
{
    protected $signature = 'telegram:session-probe {account : The account id}';

    protected $description = '(started by a run) Whether the account\'s session starts and answers';

    protected $hidden = true;

    public function handle(MadelineService $madeline): int
    {
        $account = TelegramAccount::query()->find((int) $this->argument('account'));

        if (! $account) {
            $this->line(SessionProbe::line(false, 'No such account'));

            return self::FAILURE;
        }

        try {
            $api = $madeline->for($account);

            if (! $api) {
                throw $madeline->lastError ?? new RuntimeException('Failed to start MadelineProto.');
            }

            /*
             * A started session may still not answer: one real request.
             * Not users.getUsers by hand - MadelineProto refuses that
             * ("use getPwrChat, getInfo, getFullInfo"); fullGetSelf() makes
             * it for us. It says false on Telegram's refusal: that is the
             * run's to meet and tell apart, the session did answer.
             */
            $self = $api->fullGetSelf();
        } catch (Throwable $e) {
            $this->line(SessionProbe::line(false, mb_substr(self::code($e), 0, 500)));

            return self::FAILURE;
        }

        /* Who the account is, while we have it: its Telegram id tells how old it is (the accounts page). */
        if (is_array($self) && ! empty($self['id'])) {
            try {
                $account->update(TelegramAccount::profileFrom($self));
            } catch (Throwable) {
                // the answer is what matters here
            }
        }

        $this->line(SessionProbe::line(true));

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
