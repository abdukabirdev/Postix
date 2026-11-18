<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What can be done to a post, the same from the bot (the person, the
 * admin) and from the panel: start, pause, resume, send now, stop, delete
 * its copies from the groups, repeat. Each writes its line of history.
 *
 * Every change is a conditional update: a round being posted, or a second
 * press, finds the post already changed and does nothing.
 */
final class SenderBotPosts
{
    public function __construct(private readonly SenderBotSettings $settings) {}

    /**
     * Why this person may not start one more post now (key + replacements
     * of sender_bot.*), or null. The admin is not held by the limits.
     *
     * @return array{0: string, 1: array<string, int>}|null
     */
    public function refusal(TelegramSenderBotUser $user): ?array
    {
        if ($this->settings->isAdmin($user->telegram_user_id)) {
            return null;
        }

        $limits = $this->settings->limits();

        $open = $user->posts()->whereIn('status', TelegramSenderBotPost::OPEN)->count();

        if ($open >= $limits['max_active']) {
            return ['limit_active', ['max' => $limits['max_active']]];
        }

        $today = $user->posts()->where('created_at', '>=', now()->timezone(config('app.timezone'))->startOfDay())->count();

        if ($today >= $limits['per_day']) {
            return ['limit_day', ['max' => $limits['per_day']]];
        }

        return null;
    }

    /**
     * The groups a post goes to: every usable one, or the usable ones of
     * those chosen.
     *
     * @param array<int, int>|null $ids
     *
     * @return Collection<int, TelegramSenderBotGroup>
     */
    public function groups(?array $ids): Collection
    {
        return TelegramSenderBotGroup::query()
            ->usable()
            ->when($ids !== null && $ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();
    }

    /**
     * A post from the person's draft (the confirm card's Start).
     */
    public function create(TelegramSenderBotUser $user, array $draft): TelegramSenderBotPost
    {
        $times = max(1, (int) ($draft['times'] ?? 1));
        $groups = isset($draft['groups']) && is_array($draft['groups']) && $draft['groups'] !== []
            ? array_values(array_map('intval', $draft['groups']))
            : null;
        $startAt = isset($draft['start_at']) ? Carbon::parse($draft['start_at']) : null;

        $post = TelegramSenderBotPost::query()->create([
            'sender_bot_user_id' => $user->id,
            'source_chat_id' => (int) $draft['chat_id'],
            'source_message_id' => (int) $draft['message_id'],
            'kind' => (string) ($draft['kind'] ?? 'text'),
            'text' => (string) ($draft['text'] ?? ''),
            'group_ids' => $groups,
            'payload' => is_array($draft['payload'] ?? null) ? $draft['payload'] : null,
            'times' => $times,
            'interval_minutes' => $times > 1 ? (int) ($draft['interval'] ?? 0) : 0,
            'sent_times' => 0,
            'next_at' => $startAt !== null && $startAt->isFuture() ? $startAt : now(),
            'status' => TelegramSenderBotPost::STATUS_ACTIVE,
            'repeat_of_id' => isset($draft['repeat_of']) ? (int) $draft['repeat_of'] : null,
        ]);

        SenderBotHistory::add('post_created', TelegramSenderBotEvent::ACTOR_USER, $user, $post, data: array_filter([
            'times' => $post->times,
            'interval' => $post->interval_minutes,
            'groups' => $groups !== null ? count($groups) : null,
            'start_at' => $post->next_at?->isFuture() ? $post->next_at->toIso8601String() : null,
            'repeat_of' => $post->repeat_of_id,
        ], static fn ($v) => $v !== null));

        return $post;
    }

    public function pause(TelegramSenderBotPost $post, string $actor): bool
    {
        $done = TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->update(['status' => TelegramSenderBotPost::STATUS_PAUSED, 'paused_at' => now()]);

        if ($done > 0) {
            SenderBotHistory::add('post_paused', $actor, post: $post);
        }

        return $done > 0;
    }

    /**
     * Back to running. A round missed while paused goes now; a later one
     * keeps its time.
     */
    public function resume(TelegramSenderBotPost $post, string $actor): bool
    {
        $fresh = $post->fresh();

        if ($fresh === null || $fresh->status !== TelegramSenderBotPost::STATUS_PAUSED) {
            return false;
        }

        $next = $fresh->next_at === null || $fresh->next_at->isPast() ? now() : $fresh->next_at;

        $done = TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->where('status', TelegramSenderBotPost::STATUS_PAUSED)
            ->update(['status' => TelegramSenderBotPost::STATUS_ACTIVE, 'paused_at' => null, 'next_at' => $next]);

        if ($done > 0) {
            SenderBotHistory::add('post_resumed', $actor, post: $post);
        }

        return $done > 0;
    }

    /** The next round goes now instead of waiting (a paused post is resumed). */
    public function sendNow(TelegramSenderBotPost $post, string $actor): bool
    {
        $done = TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->whereIn('status', TelegramSenderBotPost::OPEN)
            ->update(['status' => TelegramSenderBotPost::STATUS_ACTIVE, 'paused_at' => null, 'next_at' => now()]);

        if ($done > 0) {
            SenderBotHistory::add('post_now', $actor, post: $post);
        }

        return $done > 0;
    }

    public function stop(TelegramSenderBotPost $post, string $actor): bool
    {
        $done = TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->whereIn('status', TelegramSenderBotPost::OPEN)
            ->update([
                'status' => TelegramSenderBotPost::STATUS_STOPPED,
                'stopped_by' => $actor === TelegramSenderBotEvent::ACTOR_ADMIN ? TelegramSenderBotPost::STOPPED_BY_ADMIN : TelegramSenderBotPost::STOPPED_BY_USER,
                'finished_at' => now(),
                'next_at' => null,
                'paused_at' => null,
            ]);

        if ($done > 0) {
            SenderBotHistory::add('post_stopped', $actor, post: $post);
        }

        return $done > 0;
    }

    /**
     * Asks the posting command to delete the copies of a post from the
     * groups (a running post is stopped first). How many it will try; 0:
     * nothing left to delete (none sent, already deleted, or too old).
     */
    public function askDelete(TelegramSenderBotPost $post, string $actor): int
    {
        $count = $post->deletableCopies()->count();

        if ($post->isOpen()) {
            $this->stop($post, $actor);
        }

        if ($count === 0) {
            return 0;
        }

        TelegramSenderBotPost::query()
            ->whereKey($post->id)
            ->update(['delete_asked_at' => now(), 'delete_asked_by' => $actor]);

        return $count;
    }

    /**
     * The draft of a new post made from an earlier one: the same message,
     * groups, times and pause (held to today's limits).
     */
    public function repeatDraft(TelegramSenderBotPost $post): array
    {
        $limits = $this->settings->limits();
        $times = max(1, min($limits['max_times'], $post->times));

        return [
            'chat_id' => $post->source_chat_id,
            'message_id' => $post->source_message_id,
            'kind' => $post->kind,
            'text' => (string) $post->text,
            'payload' => $post->payload,
            'groups' => $post->chosenGroups(),
            'times' => $times,
            'interval' => $times > 1 ? max($limits['min_interval'], min($limits['max_interval'], $post->interval_minutes ?: $limits['min_interval'])) : 0,
            'repeat_of' => $post->id,
        ];
    }
}
