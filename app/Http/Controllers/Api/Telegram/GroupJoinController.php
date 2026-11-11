<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\GroupJoinControl;
use App\Application\Telegram\Services\GroupJoinSettings;
use App\Console\Commands\Telegram\TelegramGroupJoinCommand;
use App\Console\Commands\Telegram\TelegramGroupJoinWatchdogCommand;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramJoinKeyword;
use App\Models\Telegram\TelegramJoinLog;
use App\Models\Telegram\TelegramJoinMembership;
use App\Models\Telegram\TelegramJoinSearch;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Behind the Join group section: what the watchdog and every account's
 * worker are doing, the keywords, the groups (joined, waiting, the rest)
 * and the people read from them. Groups are not added here: the workers
 * find them. Access is the `role:driverCheck,superadmin` middleware of the
 * route group.
 */
final class GroupJoinController extends Controller
{
    /** Which join statuses each list of the section shows. */
    private const VIEWS = [
        'joined' => [TelegramScrapeGroup::JOIN_JOINED],
        'queue' => [TelegramScrapeGroup::JOIN_APPROVED, TelegramScrapeGroup::JOIN_NEW],
        'other' => [TelegramScrapeGroup::JOIN_REJECTED, TelegramScrapeGroup::JOIN_DEAD],
    ];

    public function __construct(
        private readonly GroupJoinSettings $settings,
        private readonly GroupJoinControl $control,
    ) {}

    /**
     * The status page: the watchdog, the workers, the keywords, the totals.
     */
    public function show(): JsonResponse
    {
        $config = $this->settings->current();

        $byStatus = TelegramScrapeGroup::query()
            ->joinable()
            ->select('join_status', DB::raw('count(*) as n'))
            ->groupBy('join_status')
            ->pluck('n', 'join_status');

        $memberships = TelegramJoinMembership::query()
            ->selectRaw(
                'telegram_account_id,
                SUM(status IN (?, ?)) as held,
                SUM(status IN (?, ?) AND joined_at >= ?) as today,
                SUM(status = ?) as failed,
                MAX(joined_at) as last_joined_at',
                [
                    TelegramJoinMembership::STATUS_JOINED,
                    TelegramJoinMembership::STATUS_REQUESTED,
                    TelegramJoinMembership::STATUS_JOINED,
                    TelegramJoinMembership::STATUS_REQUESTED,
                    now()->startOfDay(),
                    TelegramJoinMembership::STATUS_FAILED,
                ],
            )
            ->groupBy('telegram_account_id')
            ->get()
            ->keyBy('telegram_account_id');

        $states = TelegramAccountProcess::query()
            ->where('process', ProcessEnum::GroupJoin->value)
            ->get()
            ->keyBy('telegram_account_id');

        /* The marked accounts, each with its worker: is it up, what is it doing. */
        $accounts = TelegramAccount::query()
            ->whereIn('id', $config['accounts'])
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($memberships, $states): array {
                $stats = $memberships->get($account->id);
                $state = $states->get($account->id);
                $meta = (array) ($state?->meta ?? []);
                $lock = TelegramGroupJoinCommand::lockName($account->id);
                $running = TelegramProcessLock::isHeldByOtherProcess($lock);

                return [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'authorized' => (bool) $account->is_authorized,
                    'running' => $running,
                    'pid' => $running ? TelegramProcessLock::holderPid($lock) : null,
                    'doing' => $running ? ($meta['doing'] ?? null) : null,
                    'heartbeat_at' => $meta['heartbeat_at'] ?? null,
                    'last_exit' => Cache::get(TelegramGroupJoinWatchdogCommand::LAST_EXIT_CACHE . $account->id),
                    'disabled' => $state !== null && ! $state->is_available,
                    'disabled_reason' => $state?->disabled_reason,
                    'flood_until' => self::future($meta['flood_until'] ?? null),
                    'flood_rpc' => $meta['flood_rpc'] ?? null,
                    'next_join_at' => self::future($meta['next_join_at'] ?? null),
                    'full' => ! empty($meta['full']),
                    'held' => (int) ($stats->held ?? 0),
                    'today' => (int) ($stats->today ?? 0),
                    'failed' => (int) ($stats->failed ?? 0),
                    'last_joined_at' => $stats->last_joined_at ?? null,
                ];
            })
            ->values();

        $keywords = TelegramJoinKeyword::query()
            ->withCount(['groups', 'searches'])
            ->withCount(['searches as searched_count' => fn ($q) => $q->whereNotNull('last_searched_at')])
            ->orderByRaw("source = 'learned' asc")
            ->orderBy('keyword')
            ->get();

        return response()->json(['data' => [
            'enabled' => $config['enabled'],
            'watchdog' => [
                'running' => $this->control->running(),
                'pid' => $this->control->pid(),
                'command' => 'php artisan telegram:group-join-watchdog',
            ],
            'limits' => [
                'joins_per_day' => $config['joins_per_day'],
                'max_groups_per_account' => $config['max_groups_per_account'],
            ],
            'totals' => [
                'groups' => (int) $byStatus->sum(),
                'new' => (int) ($byStatus[TelegramScrapeGroup::JOIN_NEW] ?? 0),
                'approved' => (int) ($byStatus[TelegramScrapeGroup::JOIN_APPROVED] ?? 0),
                'rejected' => (int) ($byStatus[TelegramScrapeGroup::JOIN_REJECTED] ?? 0),
                'joined' => (int) ($byStatus[TelegramScrapeGroup::JOIN_JOINED] ?? 0),
                'dead' => (int) ($byStatus[TelegramScrapeGroup::JOIN_DEAD] ?? 0),
                'joins_today' => (int) $accounts->sum('today'),
                'memberships' => (int) $accounts->sum('held'),
                'people' => $this->peopleCount(),
                'searches' => TelegramJoinSearch::query()->count(),
                'searches_due' => TelegramJoinSearch::query()
                    ->where(fn ($q) => $q
                        ->whereNull('last_searched_at')
                        ->orWhere('last_searched_at', '<=', now()->subHours((int) $config['search_every_hours'])))
                    ->count(),
                'learned' => $keywords->where('source', 'learned')->count(),
            ],
            'keywords' => $keywords,
            'accounts' => $accounts,
        ]]);
    }

    /**
     * One list of the section: joined, waiting to be joined, or the rest.
     */
    public function groups(Request $request): JsonResponse
    {
        $request->validate([
            'view' => ['nullable', 'in:joined,queue,other'],
            'status' => ['nullable', 'string', 'max:16'],
            'type' => ['nullable', 'in:contacts,database'],
            'source' => ['nullable', 'in:search,link,forward'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:score,members,people,last_message,found'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $view = $request->input('view', 'queue');
        $search = trim((string) $request->input('search', ''));

        $groups = TelegramScrapeGroup::query()
            ->joinable()
            ->with([
                'keyword:id,keyword,topic',
                'memberships' => fn ($q) => $q->select('id', 'group_id', 'telegram_account_id', 'status', 'error', 'joined_at'),
                'memberships.account:id,phone',
            ])
            ->whereIn('join_status', self::VIEWS[$view])
            ->when($request->filled('status'), fn ($q) => $q->where('join_status', $request->input('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', '%' . $search . '%')
                ->orWhere('link', 'like', '%' . $search . '%')))
            ->when(
                $request->input('sort', $view === 'joined' ? 'people' : 'score'),
                fn ($q, string $sort) => match ($sort) {
                    'members' => $q->orderByDesc('members_count'),
                    'people' => $q->orderByDesc('members_read'),
                    'last_message' => $q->orderByDesc('last_message_at'),
                    'found' => $q->orderByDesc('id'),
                    /* Waiting: the next one an account takes comes first. */
                    default => $q->orderByRaw("decided_by = 'manual' desc")->orderByRaw("join_status = 'approved' desc")->orderByDesc('score'),
                },
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($groups);
    }

    /**
     * The people read from Join group's groups: who is in which group.
     */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            /* `total`: only how many match - a count over the whole base, asked for after the list is on the screen. */
            'part' => ['nullable', 'in:total'],
        ]);

        $term = trim((string) ($data['search'] ?? ''));

        $filtered = TelegramScrapedUser::query()
            ->where('is_bot', false)
            ->when(
                filled($data['group_id'] ?? null),
                fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('telegram_scrape_groups.id', (int) $data['group_id'])),
                fn ($q) => $q->whereHas('groups', fn ($g) => $g->joinable()),
            )
            ->when($request->boolean('phone'), fn ($q) => $q->whereNotNull('phone'))
            ->when($term !== '', function ($q) use ($term) {
                /* An exact start where it can be: the name and phone columns are indexed, a million rows deep. */
                if (str_starts_with($term, '@')) {
                    $q->where('username', 'like', ltrim($term, '@') . '%');
                } elseif (ctype_digit(ltrim($term, '+'))) {
                    $digits = ltrim($term, '+');

                    /* Phones are kept as +998...: the start of one, typed with or without the plus. */
                    $q->where(fn ($q) => $q
                        ->where('phone', 'like', '+' . $digits . '%')
                        ->orWhere('telegram_user_id', (int) $digits));
                } else {
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    $q->where(fn ($q) => $q
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('username', 'like', $like));
                }
            })
            ;

        if (($data['part'] ?? null) === 'total') {
            return response()->json(['total' => (clone $filtered)->count(), 'all' => $this->peopleCount()]);
        }

        $perPage = (int) ($data['per_page'] ?? 30);
        $current = max(1, $request->integer('page', 1));

        /* One more than the page: whether there is a next one, without counting the base. */
        $people = (clone $filtered)
            ->with(['groups' => fn ($q) => $q->select('telegram_scrape_groups.id', 'title', 'link', 'type')])
            ->withCount('groups')
            ->orderByDesc('id')
            ->offset(($current - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        return response()->json([
            'data' => $people->take($perPage)->map(fn (TelegramScrapedUser $user) => [
                'id' => $user->id,
                'telegram_user_id' => $user->telegram_user_id,
                'username' => $user->username,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'phone' => $user->phone,
                'found_at' => $user->created_at,
                'groups_count' => $user->groups_count,
                'groups' => $user->groups->take(5)->map(fn ($group) => [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->link,
                    'type' => $group->type,
                ])->values(),
            ])->values(),
            'meta' => [
                'current_page' => $current,
                'has_more' => $people->count() > $perPage,
            ],
            'group' => filled($data['group_id'] ?? null)
                ? TelegramScrapeGroup::query()->find((int) $data['group_id'], ['id', 'title', 'link', 'members_read', 'last_synced_at'])
                : null,
        ]);
    }

    /**
     * One keyword or several, one per line.
     */
    public function storeKeyword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:2000'],
            'topic' => ['nullable', 'string', 'max:60'],
            'expand' => ['nullable', 'boolean'],
        ]);

        $topic = trim((string) ($data['topic'] ?? '')) ?: null;
        $added = 0;

        foreach (preg_split('/\R/u', $data['keyword']) as $line) {
            $keyword = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, 100);

            if ($keyword === '') {
                continue;
            }

            $row = TelegramJoinKeyword::query()->firstOrCreate(['keyword' => $keyword], [
                'topic' => $topic,
                'source' => 'manual',
                'expand' => (bool) ($data['expand'] ?? false),
            ]);

            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json([
            'message' => __('driver_check.join.messages.keywords_added', ['n' => $added]),
        ], $added > 0 ? 201 : 200);
    }

    public function updateKeyword(Request $request, TelegramJoinKeyword $keyword): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'expand' => ['sometimes', 'boolean'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $keyword->update($data);

        return response()->json(['data' => $keyword]);
    }

    public function destroyKeyword(TelegramJoinKeyword $keyword): JsonResponse
    {
        /* Its searches go with it; the groups found by it stay, they only lose the link to the word. */
        $keyword->delete();

        return response()->json([
            'message' => __('driver_check.join.messages.keyword_deleted'),
        ]);
    }

    /**
     * Every query searched again, as the workers get to them.
     */
    public function searchNow(): JsonResponse
    {
        TelegramJoinSearch::query()->update(['last_searched_at' => null]);

        return response()->json([
            'message' => __('driver_check.join.messages.search_queued'),
        ]);
    }

    /**
     * approve / reject: a person decides and the checks no longer change it;
     * auto: back to the checks, read again on the next round;
     * contacts / database: what becomes of its people - saved into contacts
     * by the contact sync, or only kept.
     */
    public function updateGroup(Request $request, TelegramScrapeGroup $group): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,auto,contacts,database'],
        ]);

        abort_if($group->join_status === null, 404);

        $joined = $group->memberships()->where('status', TelegramJoinMembership::STATUS_JOINED)->exists();

        $group->update(match ($data['action']) {
            'approve' => [
                'join_status' => $joined ? TelegramScrapeGroup::JOIN_JOINED : TelegramScrapeGroup::JOIN_APPROVED,
                'decided_by' => 'manual',
            ],
            'reject' => [
                'join_status' => TelegramScrapeGroup::JOIN_REJECTED,
                'decided_by' => 'manual',
            ],
            'auto' => [
                'join_status' => $joined ? TelegramScrapeGroup::JOIN_JOINED : TelegramScrapeGroup::JOIN_NEW,
                'decided_by' => 'auto',
                'checked_at' => null,
            ],
            /* The contact sync reads only active groups of type contacts. */
            'contacts' => ['type' => TelegramScrapeGroup::TYPE_CONTACTS, 'is_active' => true],
            'database' => ['type' => TelegramScrapeGroup::TYPE_DATABASE, 'is_active' => false],
        });

        return response()->json([
            'data' => $group->fresh(),
            'message' => in_array($data['action'], ['contacts', 'database'], true)
                ? __('driver_check.join.messages.type_' . $data['action'])
                : null,
        ]);
    }

    /**
     * Back into Join group after an error put it on hold; a flood wait and
     * the "too many groups" mark are cleared with it.
     */
    public function enableAccount(TelegramAccount $account): JsonResponse
    {
        TelegramAccountProcess::query()
            ->where('telegram_account_id', $account->id)
            ->where('process', ProcessEnum::GroupJoin->value)
            ->get()
            ->each(function (TelegramAccountProcess $state): void {
                $state->enable();

                $meta = (array) $state->meta;
                unset($meta['flood_until'], $meta['flood_rpc'], $meta['full']);

                $state->update(['meta' => $meta]);
            });

        return response()->json([
            'message' => __('driver_check.join.messages.account_enabled'),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'event' => ['nullable', 'string', 'max:24'],
            'account_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $logs = TelegramJoinLog::query()
            ->with(['account:id,phone', 'group:id,title,link'])
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('account_id'), fn ($q) => $q->where('telegram_account_id', $request->integer('account_id')))
            ->when($request->filled('group_id'), fn ($q) => $q->where('group_id', $request->integer('group_id')))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }

    /**
     * The people of Join group's groups. Counting a million rows on every
     * poll is not worth it: once a minute.
     */
    private function peopleCount(): int
    {
        return (int) Cache::remember('group-join:people-count', 60, fn () => DB::table('telegram_scrape_group_user')
            ->join('telegram_scrape_groups', 'telegram_scrape_groups.id', '=', 'telegram_scrape_group_user.group_id')
            ->whereNotNull('telegram_scrape_groups.join_status')
            ->distinct()
            ->count('telegram_scrape_group_user.scraped_user_id'));
    }

    /**
     * A stored moment, only while it is still ahead.
     */
    private static function future(mixed $at): ?string
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        $time = strtotime($at);

        return $time !== false && $time > now()->timestamp ? $at : null;
    }
}
