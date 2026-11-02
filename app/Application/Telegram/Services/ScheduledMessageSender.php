<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramScheduledMessage;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the scheduled messages into their groups with the listener's own
 * account, from inside its event loop - the same way the file forwards and
 * the penalty answers go out - and writes down how each one went.
 *
 * Two things set a message off: its time on its days (isDue), or "Send
 * now" in the panel (send_requested_at). A hand-made send leaves the
 * schedule alone; today's is still sent at its time.
 *
 * One group refusing does not stop the others.
 */
final class ScheduledMessageSender
{
    private const ERROR_LIMIT = 1000;

    /**
     * Everything that is due or was asked for, now, of the messages set to
     * be written from this listener's account ($listenerKey; null is the
     * Driver Check account). Called by the listener's periodic task.
     */
    public function flush(SimpleEventHandler $telegram, ?string $listenerKey = null): void
    {
        $now = now();

        TelegramScheduledMessage::query()
            ->where('listener_key', $listenerKey)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('send_requested_at'))
            ->orderBy('send_at')
            ->get()
            ->each(function (TelegramScheduledMessage $message) use ($telegram, $now): void {
                $requested = $message->send_requested_at !== null;

                if (! $requested && ! $message->isDue($now)) {
                    return;
                }

                /* Asked for while it is due anyway: that send is today's. */
                $this->send($telegram, $message, scheduled: $message->isDue($now));
            });
    }

    /**
     * @param bool $scheduled true for its own time: today's is then counted as
     *                        sent, even when a group refused (one try a day).
     */
    public function send(SimpleEventHandler $telegram, TelegramScheduledMessage $message, bool $scheduled): string
    {
        /* A slot whose text was not pasted in yet: nothing to write, and nothing to alert about. */
        if (! $message->hasText()) {
            $message->update([
                'send_requested_at' => null,
                'last_run_at' => now(),
                'last_status' => TelegramScheduledMessage::STATUS_FAILED,
                'last_error' => 'No text',
            ]);

            return TelegramScheduledMessage::STATUS_FAILED;
        }

        $targets = $message->targets();
        $errors = [];

        foreach ($targets as $chatId) {
            $error = $this->deliver($telegram, $chatId, $message->text);

            if ($error !== null) {
                $errors[] = $chatId . ': ' . $error;

                Log::warning('Scheduled message was not delivered', [
                    'message_id' => $message->id,
                    'chat_id' => $chatId,
                    'error' => $error,
                ]);
            }
        }

        $status = match (true) {
            $targets === [] => TelegramScheduledMessage::STATUS_FAILED,
            $errors === [] => TelegramScheduledMessage::STATUS_SENT,
            count($errors) < count($targets) => TelegramScheduledMessage::STATUS_PARTIAL,
            default => TelegramScheduledMessage::STATUS_FAILED,
        };

        $values = [
            'send_requested_at' => null,
            'last_run_at' => now(),
            'last_status' => $status,
            'last_error' => $targets === []
                ? 'No groups'
                : ($errors === [] ? null : mb_substr(implode("\n", $errors), 0, self::ERROR_LIMIT)),
        ];

        if ($scheduled) {
            $values['last_sent_on'] = now()->toDateString();
        }

        $message->update($values);

        if ($status !== TelegramScheduledMessage::STATUS_SENT) {
            app(DeveloperAlerts::class)->report(
                kind: 'scheduled_message_failed',
                title: 'Сообщение по расписанию не отправлено (#' . $message->id . ')',
                details: (string) ($values['last_error'] ?? ''),
                context: ['Статус' => $status, 'Аккаунт' => $message->listener_key ?? 'Driver Check'],
                listenerKey: $message->listener_key,
                about: (string) $message->id,
            );
        }

        return $status;
    }

    /**
     * One plain text into one group; the error as text, or null when sent.
     */
    public function deliver(SimpleEventHandler $telegram, int|string $peer, string $text): ?string
    {
        try {
            $telegram->messages->sendMessage([
                'peer' => $peer,
                'message' => $text,
            ]);

            return null;
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }
    }
}
