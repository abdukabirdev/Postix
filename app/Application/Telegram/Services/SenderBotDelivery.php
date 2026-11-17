<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use Illuminate\Support\Carbon;

/**
 * The posts whose time has come: the person's own message is copied
 * (copyMessage - text, formatting, photo, all as written, with no "forwarded
 * from") into every group that is on. Then the next round is set, or the
 * post is done and the person told.
 */
final class SenderBotDelivery
{
    /** Between two groups, microseconds: well under Telegram's 30 a second. */
    private const PAUSE = 120000;

    /** The longest Telegram may ask us to wait before one copy is tried again, seconds. */
    private const MAX_RETRY_AFTER = 30;

    public function __construct(private readonly SenderBotDialog $dialog) {}

    /**
     * Seconds until the nearest round, at most $cap (no post: $cap).
     */
    public function dueIn(int $cap): int
    {
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
        $groups = TelegramSenderBotGroup::query()->usable()->orderBy('id')->get();
        $locale = $post->user?->locale() ?? 'ru';

        if ($groups->isEmpty()) {
            $this->finish($post, TelegramSenderBotPost::STATUS_FAILED, 'no_groups', $round - 1);

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

        /* The very first round got nowhere: the message itself is the trouble (deleted, too big...). */
        if ($copied === 0 && $round === 1) {
            $this->finish($post, TelegramSenderBotPost::STATUS_FAILED, $errors[0] ?? null, 0);

            if ($post->user !== null) {
                $this->dialog->tell($api, $post->user, 'failed', ['id' => $post->id, 'error' => $errors[0] ?? '']);
            }

            return 0;
        }

        $post->last_error = $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 500);

        if ($round >= $post->times) {
            $this->finish($post, TelegramSenderBotPost::STATUS_DONE, $post->last_error, $round);

            if ($post->user !== null) {
                $this->dialog->tell($api, $post->user, 'done', [
                    'id' => $post->id,
                    'times' => $post->times,
                    'groups' => $groups->count(),
                ]);
            }

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
     * @return string|null why the copy did not get there
     */
    private function copy(BotApi $api, TelegramSenderBotPost $post, TelegramSenderBotGroup $group, int $round): ?string
    {
        $params = [
            'chat_id' => $group->chat_id,
            'from_chat_id' => $post->source_chat_id,
            'message_id' => $post->source_message_id,
        ];

        $messageId = null;
        $error = null;

        for ($try = 1; $try <= 2; $try++) {
            try {
                $messageId = (int) (((array) $api->call('copyMessage', $params))['message_id'] ?? 0);
                $error = null;
                break;
            } catch (BotApiError $e) {
                $error = $e->reason();

                if ($try === 1 && $e->getCode() === 429 && $e->retryAfter <= self::MAX_RETRY_AFTER) {
                    sleep(max(1, $e->retryAfter));

                    continue;
                }

                if ($e->getCode() === 403 || str_contains(mb_strtolower($e->getMessage()), 'chat not found')) {
                    $group->bot_status = str_contains(mb_strtolower($e->getMessage()), 'kicked') ? 'kicked' : 'left';
                }

                break;
            }
        }

        TelegramSenderBotSend::query()->create([
            'sender_bot_post_id' => $post->id,
            'sender_bot_group_id' => $group->id,
            'round' => $round,
            'message_id' => $messageId ?: null,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);

        $group->forceFill($error === null
            ? ['last_sent_at' => now(), 'last_error' => null]
            : ['last_error' => mb_substr($error, 0, 500)])->save();

        return $error;
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
}
