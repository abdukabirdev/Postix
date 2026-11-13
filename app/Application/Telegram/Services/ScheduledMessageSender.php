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
        $skipped = 0;

        foreach ($targets as $chatId) {
            /*
             * The evening "where are the reports": not in a group where one
             * was already posted today. Only on its own time - "Send now" is
             * somebody asking for it.
             */
            if ($scheduled && $message->skip_if_file_today && $this->fileToday($telegram, $chatId)) {
                $skipped++;

                continue;
            }

            /* Each group gets its own pick among the main text and the variants. */
            $error = $this->deliver($telegram, $chatId, $message->pickText());

            if ($error !== null) {
                $errors[] = $chatId . ': ' . $error;

                Log::warning('Scheduled message was not delivered', [
                    'message_id' => $message->id,
                    'chat_id' => $chatId,
                    'error' => $error,
                ]);
            }
        }

        $attempted = count($targets) - $skipped;

        $status = match (true) {
            $targets === [] => TelegramScheduledMessage::STATUS_FAILED,
            $attempted === 0 => TelegramScheduledMessage::STATUS_SKIPPED,
            $errors === [] => TelegramScheduledMessage::STATUS_SENT,
            count($errors) < $attempted => TelegramScheduledMessage::STATUS_PARTIAL,
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

        if (! in_array($status, [TelegramScheduledMessage::STATUS_SENT, TelegramScheduledMessage::STATUS_SKIPPED], true)) {
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
     * Whether somebody (not us) posted a file into the group since midnight:
     * a document that is no voice message, music, video, sticker or GIF, and
     * whose caption carries no request's hashtag (a CMR). A group that cannot
     * be read counts as "no", so the reminder still goes.
     */
    public function fileToday(SimpleEventHandler $telegram, int|string $peer): bool
    {
        try {
            $page = (array) $telegram->messages->getHistory([
                'peer' => $peer,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => 60,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);
        } catch (Throwable $e) {
            Log::warning('Scheduled message: the group was not read for a file', ['chat_id' => $peer, 'error' => $e->getMessage()]);

            return false;
        }

        $midnight = now()->startOfDay()->getTimestamp();

        foreach ((array) ($page['messages'] ?? []) as $message) {
            if (! is_array($message) || ! empty($message['out']) || (int) ($message['date'] ?? 0) < $midnight) {
                continue;
            }

            $media = $message['media'] ?? null;

            if (! is_array($media) || ($media['_'] ?? '') !== 'messageMediaDocument') {
                continue;
            }

            /* A CMR or a request's document ("#EGS26092200045 ... выгрузили") is no report. */
            if (str_contains((string) ($message['message'] ?? ''), '#')) {
                continue;
            }

            $plain = true;

            foreach ((array) ($media['document']['attributes'] ?? []) as $attribute) {
                if (in_array($attribute['_'] ?? '', ['documentAttributeAudio', 'documentAttributeVideo', 'documentAttributeAnimated', 'documentAttributeSticker'], true)) {
                    $plain = false;
                }
            }

            if ($plain) {
                return true;
            }
        }

        return false;
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
