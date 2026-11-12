<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Our account added somebody": the service line Telegram writes into the
 * group ("A added B") is deleted by our join request bot at once, so the
 * chat is not spoiled by a line per invited person.
 *
 * Only a line where one of OUR accounts is the one who added (the sender of
 * the service message) and at least one of the added is somebody else. Our
 * account joining by itself, people joining or being added by anybody else,
 * and every other service line are left alone.
 *
 * An add-on: the bot needs the "Delete messages" right in the group; a
 * refusal or a database trouble is logged and never stops the bot letting
 * people in. A line that could not be deleted now is not tried again.
 */
final class AddedNotices
{
    /**
     * A message the bot got. If it is such a line, it is deleted.
     *
     * @param array<string, mixed>   $message
     * @param callable(string): void $say
     */
    public function take(BotApi $api, array $message, callable $say): void
    {
        try {
            $chat = (array) ($message['chat'] ?? []);
            $adder = (array) ($message['from'] ?? []);

            if (! in_array($chat['type'] ?? null, ['group', 'supergroup'], true) || empty($chat['id']) || empty($message['message_id']) || empty($adder['id'])) {
                return;
            }

            if (! self::addedOthers($message, (int) $adder['id']) || ! $this->isOurs((int) $adder['id'])) {
                return;
            }

            try {
                $api->call('deleteMessage', ['chat_id' => (int) $chat['id'], 'message_id' => (int) $message['message_id']]);
                $say('added line deleted: account ' . JoinRequestService::who($adder) . ' in ' . ($chat['title'] ?? $chat['id']));
            } catch (BotApiError $e) {
                /* Already gone is what we wanted; anything else is only worth a line in the log. */
                if (! str_contains(mb_strtolower($e->getMessage()), 'message to delete not found')) {
                    Log::warning('Join bot: the "added" line could not be deleted', ['chat_id' => $chat['id'], 'error' => $e->getMessage()]);
                    $say('added line could not be deleted: ' . ($chat['title'] ?? $chat['id']) . ' (' . $e->getMessage() . ')');
                }
            }
        } catch (Throwable $e) {
            Log::warning('Join bot: an "added" line could not be handled', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Does the service line name somebody who is not the sender (the sender
     * "adding" themselves is a person joining).
     *
     * @param array<string, mixed> $message
     */
    private static function addedOthers(array $message, int $adderId): bool
    {
        foreach ((array) ($message['new_chat_members'] ?? []) as $member) {
            if (is_array($member) && ! empty($member['id']) && (int) $member['id'] !== $adderId) {
                return true;
            }
        }

        return false;
    }

    private function isOurs(int $userId): bool
    {
        return TelegramAccount::query()->where('telegram_user_id', $userId)->exists();
    }
}
