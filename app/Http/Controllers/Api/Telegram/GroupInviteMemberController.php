<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\MemberEvents;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupMemberEvent;
use App\Models\Telegram\TelegramInviteGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group invites: who came into our groups, who left, who was removed and
 * by whom, as our join request bot saw it (MemberEvents). The totals of
 * the period, who removes, whose added people are gone, the events.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteMemberController extends Controller
{
    private const LIST_SIZE = 300;

    private const DAYS = [1, 3, 7, 30];

    public function __construct(private readonly JoinBotSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        $input = $request->validate([
            'days' => ['nullable', 'integer', 'in:' . implode(',', self::DAYS)],
            'group' => ['nullable', 'integer'],
            'event' => ['nullable', 'in:all,joined,added,left,removed,other'],
            'who' => ['nullable', 'in:all,ours'],
        ]);

        $days = (int) ($input['days'] ?? 7);
        $groupId = isset($input['group']) ? (int) $input['group'] : null;
        $event = $input['event'] ?? 'all';
        $who = $input['who'] ?? 'all';
        $from = now()->subDays($days);

        $period = fn (): Builder => TelegramGroupMemberEvent::query()
            ->where('happened_at', '>=', $from)
            ->when($groupId !== null, fn (Builder $q) => $q->where('invite_group_id', $groupId));

        $totals = $period()
            ->selectRaw('event, COUNT(*) as n')
            ->groupBy('event')
            ->pluck('n', 'event')
            ->map(fn ($n) => (int) $n);

        $main = [TelegramGroupMemberEvent::JOINED, TelegramGroupMemberEvent::ADDED, TelegramGroupMemberEvent::LEFT, TelegramGroupMemberEvent::REMOVED];

        /* Of the people our calls added into the group: gone, and by whose hand. */
        $ours = $period()
            ->whereNotNull('invited_by_account_id')
            ->whereIn('event', TelegramGroupMemberEvent::GONE)
            ->selectRaw('event, COUNT(*) as n')
            ->groupBy('event')
            ->pluck('n', 'event')
            ->map(fn ($n) => (int) $n);

        $byInviter = $period()
            ->whereNotNull('invited_by_account_id')
            ->whereIn('event', TelegramGroupMemberEvent::GONE)
            ->selectRaw('invited_by_account_id, SUM(event = ?) as left_n, SUM(event = ?) as removed_n', [TelegramGroupMemberEvent::LEFT, TelegramGroupMemberEvent::REMOVED])
            ->groupBy('invited_by_account_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(20)
            ->get();

        $phones = TelegramAccount::query()
            ->whereIn('id', $byInviter->pluck('invited_by_account_id')->all())
            ->pluck('phone', 'id');

        $removers = $period()
            ->whereIn('event', [TelegramGroupMemberEvent::REMOVED, TelegramGroupMemberEvent::BANNED])
            ->selectRaw('actor_kind, actor_user_id, MAX(actor_name) as actor_name, MAX(actor_account_id) as actor_account_id, COUNT(*) as n, SUM(invited_by_account_id IS NOT NULL) as ours_n')
            ->groupBy('actor_kind', 'actor_user_id')
            ->orderByDesc('n')
            ->limit(20)
            ->get()
            ->map(fn (TelegramGroupMemberEvent $row) => [
                'kind' => $row->actor_kind,
                'user_id' => $row->actor_user_id,
                'name' => $row->actor_name,
                'account_id' => $row->actor_account_id !== null ? (int) $row->actor_account_id : null,
                'n' => (int) $row->n,
                'ours' => (int) $row->ours_n,
            ]);

        $perDay = $period()
            ->whereIn('event', $main)
            ->selectRaw('DATE(happened_at) as day, event, COUNT(*) as n')
            ->groupBy('day', 'event')
            ->orderBy('day')
            ->get()
            ->groupBy('day')
            ->map(fn ($rows, $day) => [
                'day' => $day,
                ...array_fill_keys($main, 0),
                ...$rows->pluck('n', 'event')->map(fn ($n) => (int) $n)->all(),
            ])
            ->values();

        $events = $period()
            ->when($event !== 'all' && $event !== 'other', fn (Builder $q) => $q->where('event', $event))
            ->when($event === 'other', fn (Builder $q) => $q->whereNotIn('event', $main))
            ->when($who === 'ours', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNotNull('invited_by_account_id')->orWhereNotNull('member_account_id')))
            ->with('group:id,title,link')
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramGroupMemberEvent $row) => [
                'id' => $row->id,
                'at' => $row->happened_at,
                'group_id' => $row->invite_group_id,
                'group' => $row->group?->title ?: ($row->chat_title ?: ($row->group?->link ?? (string) $row->chat_id)),
                'event' => $row->event,
                'via' => $row->via,
                'user_id' => $row->telegram_user_id,
                'username' => $row->username,
                'name' => $row->name,
                'is_bot' => $row->is_bot,
                /* The member is one of our accounts; our account that had added them. */
                'account_id' => $row->member_account_id,
                'invited_by' => $row->invited_by_account_id,
                'in_base' => $row->scraped_user_id !== null,
                'by' => [
                    'kind' => $row->actor_kind,
                    'user_id' => $row->actor_user_id,
                    'name' => $row->actor_name,
                    'account_id' => $row->actor_account_id,
                ],
            ]);

        $current = $this->settings->current();

        return response()->json([
            'bot' => [
                'connected' => $current['token'] !== null,
                'enabled' => $current['enabled'],
                'alive' => $this->settings->alive(),
            ],
            'since' => TelegramGroupMemberEvent::query()->min('happened_at'),
            'keep_days' => MemberEvents::KEEP_DAYS,
            'days' => $days,
            'groups' => TelegramInviteGroup::query()
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get(['id', 'title', 'link', 'is_active'])
                ->map(fn (TelegramInviteGroup $group) => [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->link,
                    'is_active' => (bool) $group->is_active,
                ]),
            'totals' => [
                ...array_fill_keys($main, 0),
                ...$totals->only($main)->all(),
                'other' => $totals->except($main)->sum(),
            ],
            'ours' => [
                'left' => (int) ($ours[TelegramGroupMemberEvent::LEFT] ?? 0),
                'removed' => (int) ($ours[TelegramGroupMemberEvent::REMOVED] ?? 0),
            ],
            'by_inviter' => $byInviter->map(fn (TelegramGroupMemberEvent $row) => [
                'account_id' => (int) $row->invited_by_account_id,
                'phone' => $phones[$row->invited_by_account_id] ?? null,
                'left' => (int) $row->left_n,
                'removed' => (int) $row->removed_n,
            ])->values(),
            'removers' => $removers,
            'per_day' => $perDay,
            'events' => $events,
            'list_size' => self::LIST_SIZE,
        ]);
    }
}
