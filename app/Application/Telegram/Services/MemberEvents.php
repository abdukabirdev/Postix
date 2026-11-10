<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupMemberEvent;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use Illuminate\Support\Carbon;

/**
 * Who came into our groups, who left, who was removed and by whom, written
 * down from the chat_member updates our join request bot gets as an admin
 * of the group. Nothing is asked of Telegram: the bot is told anyway.
 *
 * A person we added who goes (left, removed) is marked left in the
 * group's history at once, with the real time; one who comes back is in
 * again. The "who left" reading of the members list around the invite runs
 * stays as it was and agrees with this.
 */
final class MemberEvents
{
    /** How long the events are kept. */
    public const KEEP_DAYS = 90;

    /** In the group with these statuses (restricted only when still a member). */
    private const IN = ['creator', 'administrator', 'member'];

    private const ADMIN = ['creator', 'administrator'];

    /** The old rows go once per process, not on every update. */
    private static bool $pruned = false;

    public function __construct(private readonly JoinBotSettings $settings) {}

    /**
     * One chat_member update of one of our groups. Null: not about a person
     * (no user id).
     *
     * @param array<string, mixed> $change
     */
    public function record(array $change, TelegramInviteGroup $group): ?TelegramGroupMemberEvent
    {
        $old = (array) ($change['old_chat_member'] ?? []);
        $new = (array) ($change['new_chat_member'] ?? []);
        $user = (array) ($new['user'] ?? $old['user'] ?? []);
        $actor = (array) ($change['from'] ?? []);
        $chat = (array) ($change['chat'] ?? []);
        $userId = (int) ($user['id'] ?? 0);

        if ($userId === 0) {
            return null;
        }

        $this->prune();

        $oldStatus = (string) ($old['status'] ?? '');
        $newStatus = (string) ($new['status'] ?? '');
        $inBefore = self::in($old);
        $inAfter = self::in($new);
        $actorId = (int) ($actor['id'] ?? 0);
        $viaRequest = ! empty($change['via_join_request']);

        [$event, $via] = match (true) {
            ! $inBefore && $inAfter => $viaRequest || $actorId === $userId || $actorId === 0
                ? [TelegramGroupMemberEvent::JOINED, $viaRequest ? 'request' : (! empty($change['via_chat_folder_invite_link']) ? 'folder' : (isset($change['invite_link']) ? 'link' : null))]
                : [TelegramGroupMemberEvent::ADDED, null],
            $inBefore && ! $inAfter => $newStatus === 'kicked' || ($actorId !== 0 && $actorId !== $userId)
                ? [TelegramGroupMemberEvent::REMOVED, null]
                : [TelegramGroupMemberEvent::LEFT, null],
            $oldStatus === 'kicked' && $newStatus === 'left' => [TelegramGroupMemberEvent::UNBANNED, null],
            ! $inBefore && $newStatus === 'kicked' => [TelegramGroupMemberEvent::BANNED, null],
            $newStatus === 'restricted' && $oldStatus !== 'restricted' => [TelegramGroupMemberEvent::RESTRICTED, null],
            $oldStatus === 'restricted' && $newStatus === 'member' => [TelegramGroupMemberEvent::UNRESTRICTED, null],
            in_array($newStatus, self::ADMIN, true) && ! in_array($oldStatus, self::ADMIN, true) => [TelegramGroupMemberEvent::PROMOTED, null],
            in_array($oldStatus, self::ADMIN, true) && ! in_array($newStatus, self::ADMIN, true) => [TelegramGroupMemberEvent::DEMOTED, null],
            default => [TelegramGroupMemberEvent::CHANGED, null],
        };

        $accounts = TelegramAccount::query()
            ->whereIn('telegram_user_id', array_filter([$userId, $actorId]))
            ->pluck('id', 'telegram_user_id');
        $botId = (int) ($this->settings->current()['bot']['id'] ?? 0);

        $actorKind = match (true) {
            $actorId === 0 || $actorId === $userId => TelegramGroupMemberEvent::BY_SELF,
            $botId !== 0 && $actorId === $botId => TelegramGroupMemberEvent::BY_BOT,
            isset($accounts[$actorId]) => TelegramGroupMemberEvent::BY_OURS,
            default => TelegramGroupMemberEvent::BY_OTHER,
        };

        $personId = TelegramScrapedUser::query()->where('telegram_user_id', $userId)->value('id');
        $happenedAt = isset($change['date']) ? Carbon::createFromTimestamp((int) $change['date']) : now();

        $invitedBy = $personId !== null
            ? TelegramGroupInvite::query()
                ->where('invite_group_id', $group->id)
                ->where('scraped_user_id', $personId)
                ->whereIn('status', TelegramGroupInvite::ADDED)
                ->orderByDesc('id')
                ->value('telegram_account_id')
            : null;

        $row = TelegramGroupMemberEvent::query()->create([
            'invite_group_id' => $group->id,
            'chat_id' => (int) ($chat['id'] ?? $group->telegram_chat_id ?? 0),
            'chat_title' => self::cut($chat['title'] ?? $group->title),
            'telegram_user_id' => $userId,
            'username' => self::cut($user['username'] ?? null, 64),
            'name' => self::cut(trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')))),
            'is_bot' => ! empty($user['is_bot']),
            'scraped_user_id' => $personId,
            'member_account_id' => $accounts[$userId] ?? null,
            'invited_by_account_id' => $invitedBy !== null ? (int) $invitedBy : null,
            'event' => $event,
            'via' => $via,
            'old_status' => self::cut($oldStatus, 16),
            'new_status' => self::cut($newStatus, 16),
            'actor_kind' => $actorKind,
            'actor_user_id' => $actorId !== 0 ? $actorId : null,
            'actor_name' => $actorId !== 0 ? self::cut(trim(((string) ($actor['first_name'] ?? '')) . ' ' . ((string) ($actor['last_name'] ?? '')))) : null,
            'actor_account_id' => $accounts[$actorId] ?? null,
            'happened_at' => $happenedAt,
        ]);

        if ($personId !== null) {
            $this->markHistory($group, (int) $personId, $event, $happenedAt);
        }

        return $row;
    }

    /**
     * The group's history of the person: one we added who is gone is left
     * from this moment; one who is back is in again.
     */
    private function markHistory(TelegramInviteGroup $group, int $personId, string $event, Carbon $at): void
    {
        $rows = TelegramGroupInvite::query()
            ->where('invite_group_id', $group->id)
            ->where('scraped_user_id', $personId);

        if (in_array($event, TelegramGroupMemberEvent::GONE, true)) {
            $rows->where('status', TelegramGroupInvite::STATUS_INVITED)
                ->update(['status' => TelegramGroupInvite::STATUS_LEFT, 'left_at' => $at, 'checked_at' => now(), 'updated_at' => now()]);

            return;
        }

        if (in_array($event, TelegramGroupMemberEvent::CAME, true)) {
            $rows->where('status', TelegramGroupInvite::STATUS_LEFT)
                ->update(['status' => TelegramGroupInvite::STATUS_INVITED, 'left_at' => null, 'checked_at' => now(), 'updated_at' => now()]);
        }
    }

    private function prune(): void
    {
        if (self::$pruned) {
            return;
        }

        self::$pruned = true;

        TelegramGroupMemberEvent::query()->where('happened_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function in(array $member): bool
    {
        $status = $member['status'] ?? null;

        return in_array($status, self::IN, true) || ($status === 'restricted' && ! empty($member['is_member']));
    }

    private static function cut(mixed $value, int $length = 255): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
