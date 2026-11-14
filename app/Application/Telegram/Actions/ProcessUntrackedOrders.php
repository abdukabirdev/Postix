<?php

declare(strict_types=1);

namespace App\Application\Telegram\Actions;

use App\Application\Telegram\Services\UntrackedOrdersParser;
use App\Application\Telegram\Services\UntrackedOrdersReminder;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramUntrackedReminder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * "📍 Не назначенные слежения" from a watched chat.
 *
 * Every operator of the "По оперейшнам" list gets a row: hurried in their
 * private chat to give their orders a tracking, harder each time they are
 * in the list again that day (the level). A person is looked up by name,
 * never created: a name with no Telegram behind it is nobody to write to.
 * A reminder of an earlier report that has not gone yet is dropped - the
 * new one carries the fresh count. The reminder itself goes from the
 * account that delivers their penalties, on its own tick
 * ({@see UntrackedOrdersReminder::flush()}).
 */
final class ProcessUntrackedOrders
{
    public function __construct(
        private readonly UntrackedOrdersParser $parser,
    ) {
    }

    /**
     * @return list<TelegramUntrackedReminder> the rows made now
     */
    public function execute(int $chatId, int $messageId, string $text): array
    {
        if ($chatId === 0 || $messageId <= 0) {
            return [];
        }

        $parsed = $this->parser->parse($text);

        /*
         * Every message is a report of its own, the same text sent again
         * too: the owner tests by posting copies into a watched group.
         */
        $key = "{$chatId}:{$messageId}";
        $min = max(1, (int) config('client_checks.untracked.min_count', 1));
        $rows = [];

        foreach ($parsed['operations'] as $line) {
            $normalized = OperationUser::normalizeName($line['name']);

            $exists = TelegramUntrackedReminder::query()
                ->where('report_key', $key)
                ->where('name_normalized', $normalized)
                ->exists();

            if ($exists) {
                continue;
            }

            $person = OperationUser::query()->where('name_normalized', $normalized)->first();
            $person = $person instanceof OperationUser && $person->hasTelegramPeer() ? $person : null;

            /* The reports of today that named them before this one. */
            $before = TelegramUntrackedReminder::query()
                ->where('name_normalized', $normalized)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            [$status, $reason] = match (true) {
                $person === null => [TelegramUntrackedReminder::STATUS_SKIPPED, TelegramUntrackedReminder::REASON_NO_OPERATOR],
                $line['count'] < $min => [TelegramUntrackedReminder::STATUS_SKIPPED, TelegramUntrackedReminder::REASON_FEW],
                default => [TelegramUntrackedReminder::STATUS_PENDING, null],
            };

            TelegramUntrackedReminder::query()
                ->where('name_normalized', $normalized)
                ->where('status', TelegramUntrackedReminder::STATUS_PENDING)
                ->update([
                    'status' => TelegramUntrackedReminder::STATUS_SKIPPED,
                    'reason' => TelegramUntrackedReminder::REASON_SUPERSEDED,
                ]);

            try {
                $rows[] = TelegramUntrackedReminder::query()->create([
                    'telegram_chat_id' => $chatId,
                    'telegram_message_id' => $messageId,
                    'report_key' => $key,
                    'message_text' => $text,
                    'name' => $line['name'],
                    'name_normalized' => $normalized,
                    'orders_count' => $line['count'],
                    'level' => min(255, $before + 1),
                    'operation_user_id' => $person?->id,
                    'status' => $status,
                    'reason' => $reason,
                    'attempts' => 0,
                ]);
            } catch (QueryException $e) {
                /* The unique index caught a duplicate the check above missed. */
                if ($e->getCode() === '23000') {
                    continue;
                }

                throw $e;
            }
        }

        Log::info('Untracked orders: recorded', [
            'report' => $key,
            'total' => $parsed['total'],
            'people' => count($parsed['operations']),
            'rows' => count($rows),
            'pending' => count(array_filter($rows, static fn (TelegramUntrackedReminder $row): bool => $row->status === TelegramUntrackedReminder::STATUS_PENDING)),
        ]);

        return $rows;
    }
}
