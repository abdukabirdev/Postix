<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupInviteBase;
use App\Application\Telegram\Services\GroupInviteSettings;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The base of people: each one with where they stand (can be added, cannot,
 * tried without an answer, never tried) and what became of them in each of
 * our groups. One group's people when a group is chosen, by their status
 * in it.
 */
final class GroupInvitePeopleController extends Controller
{
    /** Every status a person can have in one group. */
    private const GROUP_STATUSES = ['invited', 'left', 'not_added', 'already', 'sent', 'failed', 'pending'];

    public function __construct(
        private readonly GroupInviteSettings $settings,
        private readonly GroupInviteBase $base,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flag' => ['nullable', 'in:' . implode(',', GroupInviteBase::FLAGS)],
            'reach' => ['nullable', 'in:reachable,unreachable'],
            'group_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:' . implode(',', self::GROUP_STATUSES) . ',added'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $config = $this->settings->current();
        $groupId = filled($data['group_id'] ?? null) ? (int) $data['group_id'] : null;
        $status = $data['status'] ?? null;

        $query = $this->base->people()
            ->when(filled($data['flag'] ?? null), fn ($q) => $this->base->whereFlag($q, $data['flag']))
            ->when(filled($data['reach'] ?? null), fn ($q) => $this->base->whereReachable($q, (bool) $config['use_usernames'], $data['reach'] === 'reachable'))
            ->when($groupId !== null, fn ($q) => $q->whereHas('groupInvites', fn ($q) => $q
                ->where('invite_group_id', $groupId)
                ->when($status !== null, fn ($q) => $q->whereIn('status', $status === 'added' ? TelegramGroupInvite::ADDED : [$status]))))
            ->when($groupId === null && $status !== null, fn ($q) => $q->whereHas('groupInvites', fn ($q) => $q
                ->whereIn('status', $status === 'added' ? TelegramGroupInvite::ADDED : [$status])))
            ->when(filled($data['search'] ?? null), function ($q) use ($data) {
                $term = trim((string) $data['search']);
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], ltrim($term, '@+')) . '%';

                $q->where(function ($q) use ($like, $term) {
                    $q->where('username', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like);

                    if (ctype_digit($term)) {
                        $q->orWhere('telegram_user_id', (int) $term);
                    }
                });
            });

        $counts = $this->base->cachedCounts($config);
        $perPage = (int) ($data['per_page'] ?? 30);
        $current = max(1, $request->integer('page', 1));

        /* Only "where they stand" chosen: the number is already counted. Anything narrower is counted here. */
        $narrowed = $groupId !== null || $status !== null || filled($data['search'] ?? null);
        $flag = $data['flag'] ?? null;
        $reach = $data['reach'] ?? null;

        $total = match (true) {
            $narrowed => (clone $query)->count(),
            $flag === GroupInviteBase::UNTRIED && $reach !== null => $counts[$reach === 'reachable' ? 'untried_reachable' : 'untried_unreachable'],
            filled($flag) && $reach === null => $counts[$flag],
            ! filled($flag) && $reach === null => $counts['total'],
            default => (clone $query)->count(),
        };

        /* The newest first by id: a big base sorts by its key, never by a column it would have to scan. */
        $rows = $query
            ->with(['groupInvites' => fn ($q) => $q
                ->with(['group:id,title,link,deleted_at', 'account:id,phone'])
                ->orderByDesc('id')])
            ->orderByDesc('id')
            ->forPage($current, $perPage)
            ->get();

        $group = $groupId !== null ? TelegramInviteGroup::query()->withTrashed()->find($groupId) : null;

        return response()->json([
            'data' => $rows->map(fn (TelegramScrapedUser $user) => self::row($user))->values(),
            'meta' => [
                'current_page' => $current,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
            'counts' => $counts,
            'group' => $group ? [
                'id' => $group->id,
                'title' => $group->title ?: $group->link,
                'archived' => $group->trashed(),
            ] : null,
            'groups' => TelegramInviteGroup::query()
                ->withTrashed()
                ->orderBy('id')
                ->get(['id', 'title', 'link', 'deleted_at'])
                ->map(fn (TelegramInviteGroup $g) => ['id' => $g->id, 'title' => $g->title ?: $g->link, 'archived' => $g->trashed()])
                ->values(),
        ]);
    }

    /**
     * Give the people Telegram refused another chance: the refusal is
     * forgotten, and every group tries them again. Who was added stays.
     */
    public function reset(): JsonResponse
    {
        $refused = TelegramScrapedUser::query()->where('can_add_to_group', false);

        $deleted = TelegramGroupInvite::query()
            ->where('status', TelegramGroupInvite::STATUS_FAILED)
            ->whereIn('scraped_user_id', (clone $refused)->select('id'))
            ->delete();

        $people = $refused->update([
            'can_add_to_group' => null,
            'add_to_group_error' => null,
            'add_to_group_checked_at' => null,
        ]);

        GroupInviteBase::forget();

        return response()->json([
            'people' => $people,
            'deleted' => $deleted,
            'message' => __('driver_check.invites.messages.people_reset', ['n' => $people]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(TelegramScrapedUser $user): array
    {
        $tries = $user->groupInvites;

        return [
            'id' => $user->id,
            'telegram_user_id' => $user->telegram_user_id,
            'username' => $user->username,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'phone' => $user->phone,
            'flag' => GroupInviteBase::flagOf(
                $user->can_add_to_group,
                $tries->contains(fn (TelegramGroupInvite $try) => in_array($try->status, TelegramGroupInvite::SETTLED, true)),
            ),
            'reason' => $user->add_to_group_error,
            'flag_at' => $user->add_to_group_checked_at,
            'groups' => $tries->map(fn (TelegramGroupInvite $try) => [
                'group_id' => $try->invite_group_id,
                'title' => $try->group ? ($try->group->title ?: $try->group->link) : '#' . $try->invite_group_id,
                'archived' => (bool) $try->group?->trashed(),
                'status' => $try->status,
                'error' => $try->error,
                'via' => $try->via,
                'account' => $try->account?->phone,
                'attempted_at' => $try->attempted_at,
                'checked_at' => $try->checked_at,
                'left_at' => $try->left_at,
            ])->values(),
        ];
    }
}
