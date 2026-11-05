<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSpamMessage;
use App\Models\Telegram\TelegramSpamSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The spam list: our join request bot deletes what the people of the list
 * write in our groups, a few seconds after they wrote it (15 until the
 * panel says otherwise), and tells the owner without a sound. They stay in
 * the group: nobody is removed or muted.
 *
 * The bot is an admin of the group, so it gets every message there. A
 * message of someone on the list is written down with the time it goes
 * (take()); the poll of the bot deletes the ones whose time has come
 * between its asks of Telegram (sweep()) and asks no longer than the next
 * one is due (dueIn()). Written down, a message is deleted after a restart
 * of the poll as well; one sent while the poll was down goes as soon as it
 * is back.
 *
 * Someone on the list is matched by their id, or by their @username until
 * their first message tells the id; a channel writing in the group too.
 * Service lines (joined, left) are left alone.
 *
 * An add-on: a trouble of its own (a table not migrated yet, a database
 * error) is logged and never stops the bot letting people in.
 */
final class SpamCleaner
{
    /** The Bot API deletes at most this many messages of a chat in one call. */
    private const BATCH = 100;

    /** What the list and the reports keep of a message's text, characters. */
    private const TEXT = 1000;

    private const REPORT_TEXT = 300;

    /** A trouble of the database: the deleting rests this long, seconds, the poll goes on. */
    private const REST = 60;

    /**
     * What a message carries; the first one found is its kind. A message
     * with none of them is a service line (joined, left, a new title).
     */
    private const KINDS = [
        'text', 'photo', 'video', 'animation', 'document', 'sticker', 'voice', 'video_note', 'audio',
        'poll', 'contact', 'venue', 'location', 'dice', 'story', 'game', 'paid_media', 'invoice',
    ];

    /** Telegram asked to wait, or the database failed: no deleting before this time(). */
    private int $restUntil = 0;

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly JoinBotReporter $reporter,
    ) {}

    /**
     * A message the bot got. Someone on the list wrote it in a group: it is
     * written down to go `delay` seconds after it was sent.
     *
     * @param array<string, mixed>   $message
     * @param callable(string): void $say
     */
    public function take(array $message, callable $say): void
    {
        try {
            $chat = (array) ($message['chat'] ?? []);

            if (! in_array($chat['type'] ?? null, ['group', 'supergroup'], true) || empty($chat['id']) || empty($message['message_id'])) {
                return;
            }

            $kind = self::kind($message);

            if ($kind === null) {
                return;
            }

            $found = $this->find($message);

            if ($found === null) {
                return;
            }

            [$sender, $actor] = $found;
            $this->learn($sender, $actor);

            $sentAt = isset($message['date']) ? now()->setTimestamp((int) $message['date']) : now();
            $deleteAt = $sentAt->copy()->addSeconds($this->settings->spam()['delay']);

            $row = TelegramSpamMessage::query()->firstOrCreate(
                ['chat_id' => (int) $chat['id'], 'message_id' => (int) $message['message_id']],
                [
                    'spam_sender_id' => $sender->id,
                    'chat_title' => self::cut($chat['title'] ?? null, 255),
                    'telegram_user_id' => (int) $actor['id'],
                    'username' => self::username($actor['username'] ?? null),
                    'name' => self::cut(self::nameOf($actor), 255),
                    'kind' => $kind,
                    'text' => self::cut(self::textOf($message), self::TEXT),
                    'sent_at' => $sentAt,
                    'delete_at' => $deleteAt,
                    'status' => TelegramSpamMessage::STATUS_PENDING,
                ],
            );

            if ($row->wasRecentlyCreated) {
                $say('spam: ' . JoinRequestService::who($actor) . ' in ' . ($chat['title'] ?? $chat['id']) . ", goes at {$deleteAt->format('H:i:s')}");
            }
        } catch (Throwable $e) {
            Log::warning('Join bot: a message of the spam list could not be written down', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Deletes the messages whose time has come, a chat at a time, and tells
     * the owner. Telegram out of reach or asking to wait: they stay and go
     * on a later sweep.
     *
     * @param callable(string): void $say
     */
    public function sweep(BotApi $api, callable $say): void
    {
        if (time() < $this->restUntil) {
            return;
        }

        try {
            $due = TelegramSpamMessage::query()
                ->where('status', TelegramSpamMessage::STATUS_PENDING)
                ->where('delete_at', '<=', now())
                ->orderBy('id')
                ->limit(self::BATCH * 3)
                ->get();

            foreach ($due->groupBy('chat_id') as $chatId => $rows) {
                foreach ($rows->chunk(self::BATCH) as $chunk) {
                    $this->delete($api, (int) $chatId, $chunk->values(), $say);
                }
            }
        } catch (BotApiError $e) {
            $this->restUntil = time() + max(3, $e->retryAfter);
            $say('spam: Bot API ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->restUntil = time() + self::REST;
            Log::warning('Join bot: the messages of the spam list could not be deleted', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Seconds until the next message is due (at least 1): the poll asks
     * Telegram no longer than that. PHP_INT_MAX: nothing waits.
     */
    public function dueIn(): int
    {
        try {
            $next = TelegramSpamMessage::query()
                ->where('status', TelegramSpamMessage::STATUS_PENDING)
                ->min('delete_at');
        } catch (Throwable) {
            return PHP_INT_MAX;
        }

        if ($next === null) {
            return PHP_INT_MAX;
        }

        return max(1, Carbon::parse($next)->getTimestamp() - time(), $this->restUntil - time());
    }

    /**
     * What was typed in the panel: ids, @usernames, t.me links, one per
     * line or apart by commas or spaces. Anything else comes back as not
     * understood.
     *
     * @return array{entries: list<array{telegram_user_id: int|null, username: string|null}>, invalid: list<string>}
     */
    public static function parse(string $input): array
    {
        $entries = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $entry = self::entry($word);

            if ($entry === null) {
                $invalid[] = mb_substr($word, 0, 64);

                continue;
            }

            $entries[($entry['telegram_user_id'] ?? '') . '@' . ($entry['username'] ?? '')] = $entry;
        }

        return ['entries' => array_values($entries), 'invalid' => array_values(array_unique($invalid))];
    }

    /**
     * Puts on the list whoever is not on it yet. Someone typed by id gets
     * the name the base of people has for them, until they write.
     *
     * @param list<array{telegram_user_id: int|null, username: string|null}> $entries
     *
     * @return array{added: int, known: int}
     */
    public function add(array $entries, ?string $note): array
    {
        $added = 0;
        $known = 0;
        $note = self::cut($note, 255);

        foreach ($entries as $entry) {
            $exists = TelegramSpamSender::query()
                ->when(
                    $entry['telegram_user_id'] !== null,
                    fn ($q) => $q->where('telegram_user_id', $entry['telegram_user_id']),
                    fn ($q) => $q->where('username', $entry['username']),
                )
                ->exists();

            if ($exists) {
                $known++;

                continue;
            }

            $person = $entry['telegram_user_id'] !== null
                ? TelegramScrapedUser::query()->where('telegram_user_id', $entry['telegram_user_id'])->first()
                : null;

            TelegramSpamSender::query()->create([
                'telegram_user_id' => $entry['telegram_user_id'],
                'username' => $entry['username'] ?? self::username($person?->username),
                'name' => $person !== null ? self::cut(trim($person->first_name . ' ' . $person->last_name), 255) : null,
                'note' => $note,
            ]);

            $added++;
        }

        return ['added' => $added, 'known' => $known];
    }

    /**
     * Off the list: their messages still waiting stay in the group.
     */
    public function remove(TelegramSpamSender $sender): void
    {
        DB::transaction(function () use ($sender) {
            $sender->messages()->where('status', TelegramSpamMessage::STATUS_PENDING)->delete();
            $sender->delete();
        });
    }

    /**
     * One call for the messages of one chat; each is marked deleted or
     * failed, and the owner is told, a line per person.
     *
     * @param Collection<int, TelegramSpamMessage> $rows
     * @param callable(string): void               $say
     *
     * @throws BotApiError Telegram out of reach or asking to wait: the messages stay for a later sweep
     */
    private function delete(BotApi $api, int $chatId, Collection $rows, callable $say): void
    {
        $ids = $rows->pluck('message_id')->map(static fn ($id) => (int) $id)->all();
        $error = null;

        try {
            count($ids) === 1
                ? $api->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $ids[0]])
                : $api->call('deleteMessages', ['chat_id' => $chatId, 'message_ids' => $ids]);
        } catch (BotApiError $e) {
            if ($e->transient) {
                throw $e;
            }

            $error = self::code($e->reason());
        }

        /* Somebody deleted it before the bot did: gone all the same, nothing to tell. */
        $gone = $error === 'MESSAGE_NOT_FOUND';
        $deleted = $error === null || $gone;

        TelegramSpamMessage::query()->whereKey($rows->pluck('id')->all())->update([
            'status' => $deleted ? TelegramSpamMessage::STATUS_DELETED : TelegramSpamMessage::STATUS_FAILED,
            'error' => $error !== null ? mb_substr($error, 0, 120) : null,
            'removed_at' => $deleted ? now() : null,
            'updated_at' => now(),
        ]);

        $title = $rows->first()->chat_title ?? (string) $chatId;
        $say('spam: ' . ($deleted ? 'deleted' : "could not delete ({$error})") . ' ' . count($ids) . " in {$title}");

        if ($gone) {
            return;
        }

        foreach ($rows->groupBy(static fn (TelegramSpamMessage $row) => $row->telegram_user_id ?? 0) as $theirs) {
            if ($deleted && $theirs->first()->spam_sender_id !== null) {
                TelegramSpamSender::query()
                    ->whereKey($theirs->first()->spam_sender_id)
                    ->increment('deleted_count', $theirs->count(), ['last_deleted_at' => now()]);
            }

            $this->report($theirs, $error);
        }
    }

    /**
     * The owner is told without a sound: whose message, where, what it said.
     *
     * @param Collection<int, TelegramSpamMessage> $theirs one person's messages in one chat
     */
    private function report(Collection $theirs, ?string $error): void
    {
        if (! $this->settings->spam()['notify']) {
            return;
        }

        $first = $theirs->first();
        $locale = $this->settings->reports()['locale'];
        $shown = $theirs->first(static fn (TelegramSpamMessage $row) => $row->text !== null) ?? $first;

        $this->reporter->send(
            $error !== null ? 'spam_failed' : ($theirs->count() > 1 ? 'spam_deleted_many' : 'spam_deleted'),
            [
                'who' => JoinRequestService::who(['first_name' => $first->name, 'username' => $first->username, 'id' => $first->telegram_user_id]),
                'group' => $first->chat_title ?: (string) $first->chat_id,
                'n' => $theirs->count(),
                'what' => self::what($shown, $locale),
                'reason' => $error,
            ],
            silent: true,
        );
    }

    /**
     * [Фото] «the caption» - the message in the owner's language.
     */
    private static function what(TelegramSpamMessage $row, string $locale): string
    {
        $text = $row->text !== null ? '«' . mb_substr($row->text, 0, self::REPORT_TEXT) . (mb_strlen($row->text) > self::REPORT_TEXT ? '…' : '') . '»' : null;

        if ($row->kind === 'text') {
            return (string) $text;
        }

        $label = __("driver_check.invites.spam.kinds.{$row->kind}", [], $locale);
        $label = is_string($label) && $label !== "driver_check.invites.spam.kinds.{$row->kind}" ? $label : $row->kind;

        return trim("[{$label}] " . $text);
    }

    /**
     * Who on the list wrote it: the person, or the channel it was written
     * as. An id counts first; a @username only while the id is not known.
     *
     * @param array<string, mixed> $message
     *
     * @return array{0: TelegramSpamSender, 1: array<string, mixed>}|null the line of the list and who matched it
     */
    private function find(array $message): ?array
    {
        $actors = array_filter(
            [$message['from'] ?? null, $message['sender_chat'] ?? null],
            static fn ($actor) => is_array($actor) && ! empty($actor['id']),
        );

        foreach ($actors as $actor) {
            $sender = TelegramSpamSender::query()->where('telegram_user_id', (int) $actor['id'])->first();
            $username = self::username($actor['username'] ?? null);

            if ($sender === null && $username !== null) {
                $sender = TelegramSpamSender::query()->whereNull('telegram_user_id')->where('username', $username)->first();
            }

            if ($sender !== null) {
                return [$sender, $actor];
            }
        }

        return null;
    }

    /**
     * The line of the list as they are now: their id (from now on it
     * counts), @username and name. Typed twice - by id and by @username -
     * the two lines become one.
     *
     * @param array<string, mixed> $actor
     */
    private function learn(TelegramSpamSender $sender, array $actor): void
    {
        $sender->telegram_user_id ??= (int) $actor['id'];
        $sender->username = self::username($actor['username'] ?? null);
        $sender->name = self::cut(self::nameOf($actor), 255) ?? $sender->name;

        if ($sender->username !== null) {
            $twins = TelegramSpamSender::query()
                ->whereNull('telegram_user_id')
                ->where('username', $sender->username)
                ->whereKeyNot($sender->id)
                ->get();

            foreach ($twins as $twin) {
                $sender->note ??= $twin->note;
                $twin->delete();
            }
        }

        if ($sender->isDirty()) {
            $sender->save();
        }
    }

    /**
     * 123456789, -1001234567890, @name, https://t.me/name. A bare word is
     * not taken for a @username: a pasted name ("Ali Valiyev") must not
     * put strangers on the list.
     *
     * @return array{telegram_user_id: int|null, username: string|null}|null
     */
    private static function entry(string $word): ?array
    {
        $word = trim($word);

        if (preg_match('/^-?\d{3,16}$/', $word) === 1) {
            return ['telegram_user_id' => (int) $word, 'username' => null];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/@?([A-Za-z][A-Za-z0-9_]{3,31})/?(?:\?.*)?$~i', $word, $m) === 1) {
            return ['telegram_user_id' => null, 'username' => mb_strtolower($m[1])];
        }

        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $word, $m) === 1) {
            return ['telegram_user_id' => null, 'username' => mb_strtolower($m[1])];
        }

        return null;
    }

    /**
     * Telegram's words for the usual refusals as a code the page and the
     * reports name.
     */
    private static function code(string $reason): string
    {
        $lower = mb_strtolower($reason);

        return match (true) {
            str_contains($lower, 'message to delete not found'), $reason === 'MESSAGE_ID_INVALID' => 'MESSAGE_NOT_FOUND',
            str_contains($lower, "can't be deleted"), str_contains($lower, 'not enough rights'), $reason === 'MESSAGE_DELETE_FORBIDDEN' => 'MESSAGE_DELETE_FORBIDDEN',
            str_contains($lower, 'kicked'), str_contains($lower, 'chat not found'), str_contains($lower, 'not a member') => 'BOT_NOT_IN_CHAT',
            default => $reason,
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function kind(array $message): ?string
    {
        foreach (self::KINDS as $kind) {
            if (array_key_exists($kind, $message)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function textOf(array $message): ?string
    {
        foreach ([$message['text'] ?? null, $message['caption'] ?? null, $message['poll']['question'] ?? null] as $text) {
            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * A person's first and last name, a channel's title.
     *
     * @param array<string, mixed> $actor
     */
    public static function nameOf(array $actor): ?string
    {
        $name = trim(((string) ($actor['first_name'] ?? $actor['title'] ?? '')) . ' ' . ((string) ($actor['last_name'] ?? '')));

        return $name === '' ? null : $name;
    }

    /**
     * A @username as the list keeps it: lowercase, without the @.
     */
    public static function username(mixed $value): ?string
    {
        $value = is_string($value) ? ltrim(trim($value), '@') : '';

        return $value === '' ? null : mb_strtolower(mb_substr($value, 0, 64));
    }

    public static function cut(mixed $value, int $length): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
