<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteBase;
use App\Application\Telegram\Services\GroupInviteSettings;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Our groups, the ones people are added into: what each got, who is still
 * left to try for it, its member count. People are added into one group at
 * a time: switching a group on, or adding one, makes it the current one
 * and switches the others off. Deleting archives a group - its history
 * stays, and the same link added again brings it back.
 */
final class GroupInviteGroupController extends Controller
{
    public function __construct(
        private readonly GroupInviteSettings $settings,
        private readonly GroupInviteBase $base,
    ) {}

    public function index(): JsonResponse
    {
        $config = $this->settings->current();

        $byStatus = TelegramGroupInvite::query()
            ->selectRaw('invite_group_id, status, COUNT(*) as total')
            ->groupBy('invite_group_id', 'status')
            ->get()
            ->groupBy('invite_group_id');

        $lastAdded = TelegramGroupInvite::query()
            ->whereIn('status', TelegramGroupInvite::ADDED)
            ->selectRaw('invite_group_id, MAX(attempted_at) as at')
            ->groupBy('invite_group_id')
            ->pluck('at', 'invite_group_id');

        $lastLeft = TelegramGroupInviteLog::query()
            ->where('event', TelegramGroupInviteLog::EVENT_CHECK)
            ->where('context->mode', 'left')
            ->whereNotNull('invite_group_id')
            ->selectRaw('invite_group_id, MAX(created_at) as at')
            ->groupBy('invite_group_id')
            ->pluck('at', 'invite_group_id');

        $groups = TelegramInviteGroup::query()
            ->withTrashed()
            ->orderBy('id')
            ->get()
            ->map(fn (TelegramInviteGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'link' => $group->link,
                'is_active' => $group->is_active,
                'archived_at' => $group->deleted_at,
                'created_at' => $group->created_at,
                'last_error' => $group->last_error,
                ...self::counts($byStatus->get($group->id, collect())->pluck('total', 'status')->all()),
                /* The member count when the last run came to the group and when it was done with it. */
                'members_before' => $group->members_before,
                'members_after' => $group->members_after,
                'members_checked_at' => $group->members_checked_at,
                'last_added_at' => $lastAdded[$group->id] ?? null,
                'left_checked_at' => $lastLeft[$group->id] ?? null,
                'queue' => $group->trashed() ? null : $this->base->queue($group, $config),
            ]);

        return response()->json([
            'groups' => $groups->values(),
            'current_id' => TelegramInviteGroup::current()?->id,
            'audience' => $config['audience'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:255'],
        ]);

        $link = TelegramInviteGroup::normalizeLink($data['link']);

        if ($link === '') {
            return response()->json([
                'message' => __('driver_check.invites.messages.bad_link'),
            ], 422);
        }

        $group = TelegramInviteGroup::query()->withTrashed()->where('link', $link)->first();

        $previous = TelegramInviteGroup::current();

        /* Added again after "delete": back with everything it had. */
        $restored = (bool) $group?->trashed();

        if ($restored) {
            $group->restore();
        }

        $group ??= TelegramInviteGroup::query()->create(['link' => $link, 'is_active' => false]);

        /* The group just added is where people go now. */
        $group->makeCurrent();

        $message = __($restored ? 'driver_check.invites.messages.group_restored' : 'driver_check.invites.messages.group_added');

        if ($previous !== null && $previous->id !== $group->id) {
            $message .= ' ' . __('driver_check.invites.messages.previous_off', ['group' => $previous->title ?: $previous->link]);
        }

        return response()->json(
            ['data' => $group->fresh(), 'message' => $message],
            $group->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function update(Request $request, TelegramInviteGroup $inviteGroup): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        /* On: it becomes the one group people are added into, the others go off. */
        $data['is_active'] ? $inviteGroup->makeCurrent() : $inviteGroup->update(['is_active' => false]);

        return response()->json([
            'data' => $inviteGroup->fresh(),
            'message' => __($data['is_active'] ? 'driver_check.invites.messages.group_current' : 'driver_check.invites.messages.group_off', [
                'group' => $inviteGroup->title ?: $inviteGroup->link,
            ]),
        ]);
    }

    /**
     * Archive: nothing is invited into it any more, who was added and who
     * was not stays.
     */
    public function destroy(TelegramInviteGroup $inviteGroup): JsonResponse
    {
        $inviteGroup->delete();

        return response()->json([
            'message' => __('driver_check.invites.messages.group_archived'),
        ]);
    }

    public function restore(int $inviteGroup): JsonResponse
    {
        $group = TelegramInviteGroup::query()->onlyTrashed()->findOrFail($inviteGroup);

        $group->restore();

        /* Back from the archive switched off when another group is current: one at a time. */
        if ($group->is_active && TelegramInviteGroup::query()->where('is_active', true)->whereKeyNot($group->id)->exists()) {
            $group->update(['is_active' => false]);
        }

        return response()->json([
            'message' => __('driver_check.invites.messages.group_restored'),
        ]);
    }

    /**
     * Forget this group's failures so the next run tries those people again
     * here: the ones not in the group after the call, the ones that cannot
     * be added (the refusal is forgotten for every group), or both. Who was
     * added stays.
     */
    public function retry(Request $request, TelegramInviteGroup $inviteGroup): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:failed,not_added'],
        ]);

        $failed = TelegramGroupInvite::query()
            ->where('invite_group_id', $inviteGroup->id)
            ->whereIn('status', filled($data['status'] ?? null)
                ? [$data['status']]
                : [TelegramGroupInvite::STATUS_FAILED, TelegramGroupInvite::STATUS_NOT_ADDED]);

        TelegramScrapedUser::query()
            ->where('can_add_to_group', false)
            ->whereIn('id', (clone $failed)->where('status', TelegramGroupInvite::STATUS_FAILED)->select('scraped_user_id'))
            ->update(['can_add_to_group' => null, 'add_to_group_error' => null]);

        $deleted = $failed->delete();

        GroupInviteBase::forget();

        return response()->json([
            'deleted' => $deleted,
            'message' => __('driver_check.invites.messages.retry_queued', ['n' => $deleted]),
        ]);
    }

    /**
     * A group's rows by status, with added (whether still in or left).
     *
     * @param array<string, int|string> $counts status => rows
     *
     * @return array{added: int, invited: int, left: int, not_added: int, already: int, sent: int, failed: int, pending: int}
     */
    public static function counts(array $counts): array
    {
        $of = fn (string $status) => (int) ($counts[$status] ?? 0);

        return [
            'added' => $of(TelegramGroupInvite::STATUS_INVITED) + $of(TelegramGroupInvite::STATUS_LEFT),
            'invited' => $of(TelegramGroupInvite::STATUS_INVITED),
            'left' => $of(TelegramGroupInvite::STATUS_LEFT),
            'not_added' => $of(TelegramGroupInvite::STATUS_NOT_ADDED),
            'already' => $of(TelegramGroupInvite::STATUS_ALREADY),
            'sent' => $of(TelegramGroupInvite::STATUS_SENT),
            'failed' => $of(TelegramGroupInvite::STATUS_FAILED),
            'pending' => $of(TelegramGroupInvite::STATUS_PENDING),
        ];
    }
}
