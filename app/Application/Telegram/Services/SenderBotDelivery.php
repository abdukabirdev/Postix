<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The posts whose time has come: the person's own message is copied
 * (copyMessage - text, formatting, photo, all as written, with no "forwarded
 * from") into every group of the post. When Telegram refuses to copy it
 * (deleted, "can't be copied"), the message is sent anew from what was kept
 * of it. Then the next round is set, or the post is done and the person
 * told, with the links to the copies.
 *
 * Also: the quiet hours put the rounds off, and the copies a person or the
 * admin asked to take back are deleted from the groups here.
 */
final class SenderBotDelivery
{
    /** Between two groups, microseconds: well under Telegram's 30 a second. */
    private const PAUSE = 120000;

    /** The longest Telegram may ask us to wait before one copy is tried again, seconds. */
    private const MAX_RETRY_AFTER = 30;

    /** What Telegram says when the person's message itself cannot be copied. */
    private const NOT_COPYABLE = '/can.?t be copied|to copy not found|message not found|MESSAGE_ID_INVALID|protected/i';

    public function __construct(
        private readonly SenderBotDialog $dialog,
        private readonly SenderBotSettings $settings,
        private readonly SenderBotPosts $posts,
        private readonly SenderBotCards $cards,
    ) {}

    /**
     * Seconds until the nearest round, at most $cap (no post: $cap).
     */
    public function dueIn(int $cap): int
    {
        if (TelegramSenderBotPost::query()->whereNotNull('delete_asked_at')->whereNull('copies_deleted_at')->exists()) {
            return 1;
        }

        $next = TelegramSenderBotPost::query()
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->min('next_at');

        if ($next === null) {
            return $cap;
        }

        return max(1, min($cap, (int) ceil(now()->diffInSeconds(Carbon::parse($next), false))));
    }

    /**
     * @param callable(string): void $say
     *
     * @return int copies that got there
     */
    public function dispatch(BotApi $api, callable $say): int
    {
        $this->deletions($api, $say);

        if ($this->holdForQuiet($say)) {
            return 0;
        }

        $copied = 0;

        $due = TelegramSenderBotPost::query()
            ->with('user')
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->where('next_at', '<=', now())
            ->orderBy('next_at')
            ->limit(20)
            ->get();

        foreach ($due as $post) {
            $copied += $this->round($api, $post, $say);
        }

        return $copied;
    }

    /**
     * In the quiet hours every round due before their end is put off till
     * then (the person sees the right time in the bot).
     */
    private function holdForQuiet(callable $say): bool
    {
        $until = $this->settings->quietUntil();

        if ($until === null) {
            return false;
        }

        $moved = TelegramSenderBotPost::query()
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->where('next_at', '<', $until)
            ->update(['next_at' => $until]);

        if ($moved > 0) {
            $say("quiet hours: {$moved} post(s) put off till " . $until->format('H:i'));
        }

        return true;
    }

    private function round(BotApi $api, TelegramSenderBotPost $post, callable $say): int
    {
        $round = $post->sent_times + 1;

        /* Taken by this process: a second one (or a stop pressed meanwhile) finds it changed. */
        $taken = TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->where('sent_times', $post->sent_times)
            ->update(['sent_times' => $round]);

        if ($taken === 0) {
            return 0;
        }

        $post->sent_times = $round;
        $groups = $this->posts->groups($post->chosenGroups());

        if ($groups->isEmpty()) {
            $this->finish($post, TelegramSenderBotPost::STATUS_FAILED, 'no_groups', $round - 1);
            SenderBotHistory::add('post_failed', post: $post, data: ['error' => 'no_groups']);

            if ($post->user !== null) {
                $this->dialog->tell($api, $post->user, 'failed_no_groups', ['id' => $post->id]);
            }

            $say("post #{$post->id}: no group is on");

            return 0;
        }

        $copied = 0;
        $errors = [];

        foreach ($groups as $i => $group) {
            if ($i > 0) {
                usleep(self::PAUSE);
            }

            $error = $this->copy($api, $post, $group, $round);

            if ($error === null) {
                $copied++;
            } else {
                $errors[] = $group->label() . ': ' . $error;
            }
        }

        $say(sprintf('post #%d round %d/%d: %d of %d groups', $post->id, $round, $post->times, $copied, $groups->count()));
        SenderBotHistory::add('round', post: $post, data: array_filter([
            'round' => $round,
            'times' => $post->times,
            'ok' => $copied,
            'groups' => $groups->count(),
            'errors' => $errors === [] ? null : array_slice($errors, 0, 5),
        ], static fn ($v) => $v !== null));

        /* The very first round got nowhere: the message itself is the trouble (deleted, too big...). */
        if ($copied === 0 && $round === 1) {
            $this->finish($post, TelegramSenderBotPost::STATUS_FAILED, $errors[0] ?? null, 0);
            SenderBotHistory::add('post_failed', post: $post, data: ['error' => $errors[0] ?? null]);

            if ($post->user !== null) {
                $this->dialog->tell($api, $post->user, 'failed', ['id' => $post->id, 'error' => $errors[0] ?? '']);
            }

            return 0;
        }

        $post->last_error = $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 500);

        if ($round >= $post->times) {
            $this->finish($post, TelegramSenderBotPost::STATUS_DONE, $post->last_error, $round);
            SenderBotHistory::add('post_done', post: $post, data: ['times' => $post->times, 'groups' => $groups->count()]);
            $this->tellDone($api, $post, $groups->count());

            return $copied;
        }

        /* From the time the round was due, so the pauses do not creep; never in the past. */
        $next = ($post->next_at ?? now())->copy()->addMinutes(max(1, $post->interval_minutes));

        if ($next->lte(now())) {
            $next = now()->addMinutes(max(1, $post->interval_minutes));
        }

        TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->update(['next_at' => $next, 'last_error' => $post->last_error]);

        return $copied;
    }

    /**
     * The post is done: the person gets the links to the copies of the last
     * round, with Repeat and Delete from the groups under them.
     */
    private function tellDone(BotApi $api, TelegramSenderBotPost $post, int $groups): void
    {
        $user = $post->user;

        if ($user === null) {
            return;
        }

        $locale = $user->locale();
        $post = $post->fresh(['user']) ?? $post;
        $links = $this->cards->copyLinks($post);

        $text = __('sender_bot.done', ['id' => $post->id, 'times' => $post->times, 'groups' => $groups], $locale);

        if ($links !== '') {
            $text = SenderBotCards::e($text) . "\n\n" . __('sender_bot.done_links', [], $locale) . "\n" . $links;
        } else {
            $text = SenderBotCards::e($text);
        }

        $rows = [[['text' => __('sender_bot.btn_again', [], $locale), 'callback_data' => 'p:again:' . $post->id]]];

        if ($post->deletableCopies()->exists()) {
            $rows[0][] = ['text' => __('sender_bot.btn_delete', [], $locale), 'callback_data' => 'p:del:' . $post->id];
        }

        $this->quietly(fn () => $api->call('sendMessage', [
            'chat_id' => $user->telegram_user_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => $rows],
        ]));
    }

    /**
     * @return string|null why the copy did not get there
     */
    private function copy(BotApi $api, TelegramSenderBotPost $post, TelegramSenderBotGroup $group, int $round): ?string
    {
        $button = $this->contactButton($post);

        [$messageId, $error] = $this->attempt(fn () => $api->call('copyMessage', array_filter([
            'chat_id' => $group->chat_id,
            'from_chat_id' => $post->source_chat_id,
            'message_id' => $post->source_message_id,
            'reply_markup' => $button,
        ], static fn ($v) => $v !== null)));

        /* The person's own message would not copy: sent anew from what was kept of it. */
        if ($error !== null && is_array($post->payload) && preg_match(self::NOT_COPYABLE, $error->getMessage()) === 1) {
            [$messageId, $retry] = $this->attempt(fn () => self::sendPayload($api, $group->chat_id, $post->payload, $button));
            $error = $retry;
        }

        if ($error !== null && ($error->getCode() === 403 || str_contains(mb_strtolower($error->getMessage()), 'chat not found'))) {
            $group->bot_status = str_contains(mb_strtolower($error->getMessage()), 'kicked') ? 'kicked' : 'left';
        }

        $reason = $error?->reason();

        TelegramSenderBotSend::query()->create([
            'sender_bot_post_id' => $post->id,
            'sender_bot_group_id' => $group->id,
            'round' => $round,
            'message_id' => $messageId ?: null,
            'error' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ]);

        $group->forceFill($reason === null
            ? ['last_sent_at' => now(), 'last_error' => null]
            : ['last_error' => mb_substr($reason, 0, 500)])->save();

        return $reason;
    }

    /**
     * The "Write to the author" button under a copy, when the panel turned
     * it on: it opens the bot with this post (start=p<id>).
     */
    private function contactButton(TelegramSenderBotPost $post): ?array
    {
        $username = (string) ($this->settings->current()['bot']['username'] ?? '');

        if ($username === '' || ! $this->settings->options()['contact_button']) {
            return null;
        }

        $locale = $post->user?->locale() ?? 'uz';

        return ['inline_keyboard' => [[[
            'text' => __('sender_bot.btn_contact_author', [], $locale),
            'url' => 'https://t.me/' . $username . '?start=p' . $post->id,
        ]]]];
    }

    /**
     * One call, tried again once if Telegram asks to wait a little.
     *
     * @return array{0: int|null, 1: BotApiError|null} the message id, or why not
     */
    private function attempt(callable $call): array
    {
        for ($try = 1; ; $try++) {
            try {
                return [(int) (((array) $call())['message_id'] ?? 0) ?: null, null];
            } catch (BotApiError $e) {
                if ($try === 1 && $e->getCode() === 429 && $e->retryAfter <= self::MAX_RETRY_AFTER) {
                    sleep(max(1, $e->retryAfter));

                    continue;
                }

                return [null, $e];
            }
        }
    }

    /**
     * The message sent anew from what was kept of it: the text with its
     * formatting, or the file with its caption.
     */
    public static function sendPayload(BotApi $api, int $chatId, array $payload, ?array $markup = null): mixed
    {
        $kind = (string) ($payload['kind'] ?? 'text');

        if ($kind === 'text') {
            return $api->call('sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => (string) ($payload['text'] ?? ''),
                'entities' => $payload['entities'] ?? null,
                'reply_markup' => $markup,
            ], static fn ($v) => $v !== null && $v !== []));
        }

        $method = [
            'photo' => 'sendPhoto',
            'video' => 'sendVideo',
            'document' => 'sendDocument',
            'animation' => 'sendAnimation',
            'audio' => 'sendAudio',
            'voice' => 'sendVoice',
        ][$kind] ?? throw new BotApiError('Bad Request: nothing kept to send', 400);

        return $api->call($method, array_filter([
            'chat_id' => $chatId,
            $kind => (string) ($payload['file_id'] ?? ''),
            'caption' => $payload['caption'] ?? null,
            'caption_entities' => $payload['caption_entities'] ?? null,
            'reply_markup' => $markup,
        ], static fn ($v) => $v !== null && $v !== [] && $v !== ''));
    }

    /**
     * The copies someone asked to take back: deleted from the groups (the
     * ones Telegram lets a bot delete), and the author told.
     */
    private function deletions(BotApi $api, callable $say): void
    {
        $asked = TelegramSenderBotPost::query()
            ->with('user')
            ->whereNotNull('delete_asked_at')
            ->whereNull('copies_deleted_at')
            ->orderBy('delete_asked_at')
            ->limit(5)
            ->get();

        foreach ($asked as $post) {
            $deleted = 0;
            $failed = 0;

            foreach ($post->deletableCopies()->with('group')->orderBy('id')->get() as $i => $send) {
                if ($i > 0) {
                    usleep(self::PAUSE);
                }

                try {
                    $api->call('deleteMessage', ['chat_id' => $send->group?->chat_id ?? 0, 'message_id' => $send->message_id]);
                    $gone = true;
                } catch (BotApiError $e) {
                    /* Already deleted by someone: gone all the same. */
                    $gone = str_contains(mb_strtolower($e->getMessage()), 'not found');
                }

                if ($gone) {
                    $send->forceFill(['deleted_at' => now()])->save();
                    $deleted++;
                } else {
                    $failed++;
                }
            }

            TelegramSenderBotPost::query()->whereKey($post->id)->update(['copies_deleted_at' => now()]);

            $actor = $post->delete_asked_by === TelegramSenderBotEvent::ACTOR_ADMIN ? TelegramSenderBotEvent::ACTOR_ADMIN : TelegramSenderBotEvent::ACTOR_USER;
            SenderBotHistory::add('copies_deleted', $actor, post: $post, data: ['deleted' => $deleted, 'failed' => $failed]);
            $say("post #{$post->id}: {$deleted} copies deleted, {$failed} not");

            if ($post->user instanceof TelegramSenderBotUser) {
                $this->dialog->tell($api, $post->user, $actor === TelegramSenderBotEvent::ACTOR_ADMIN && ! $this->settings->isAdmin($post->user->telegram_user_id) ? 'deleted_by_admin' : 'deleted_done', [
                    'id' => $post->id,
                    'deleted' => $deleted,
                    'failed' => $failed,
                ]);
            }
        }
    }

    private function finish(TelegramSenderBotPost $post, string $status, ?string $error, int $sent): void
    {
        TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->update([
                'status' => $status,
                'sent_times' => $sent,
                'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'next_at' => null,
                'finished_at' => now(),
            ]);
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            Log::info('Sender bot: a call that may fail did', ['error' => $e->getMessage()]);
        }
    }
}
