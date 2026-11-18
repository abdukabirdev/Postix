<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\SenderBotDialog;
use App\Application\Telegram\Services\SenderBotCards;
use App\Application\Telegram\Services\SenderBotHistory;
use App\Application\Telegram\Services\SenderBotNotices;
use App\Application\Telegram\Services\SenderBotPosts;
use App\Application\Telegram\Services\SenderBotService;
use App\Application\Telegram\Services\SenderBotSettings;
use App\Application\Telegram\Support\BotApiError;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotLead;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel of the sender bot: the token and switch, who is told of new
 * requests, the limits of a post, the requests (approve / reject / block),
 * the groups, the posts.
 */
final class SenderBotController extends Controller
{
    private const LIST_SIZE = 200;

    public function __construct(
        private readonly SenderBotSettings $settings,
        private readonly SenderBotService $bot,
        private readonly SenderBotDialog $dialog,
        private readonly SenderBotPosts $actions,
    ) {}

    /** The bot, its settings, and the numbers every page shows. */
    public function show(): JsonResponse
    {
        $current = $this->settings->current();
        $state = $this->settings->state();
        $notify = $this->settings->notify();
        $listener = $this->settings->notifyListener();

        $lastNotice = TelegramSenderBotUser::query()
            ->where(fn ($q) => $q->whereNotNull('notified_at')->orWhereNotNull('notify_error'))
            ->latest('requested_at')
            ->first(['notified_at', 'notify_error', 'requested_at']);

        $posts = TelegramSenderBotPost::query()
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $users = TelegramSenderBotUser::query()
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'bot' => [
                'connected' => $current['token'] !== null,
                'token' => JoinBotSettings::mask($current['token']),
                'username' => $current['bot']['username'] ?? null,
                'name' => $current['bot']['name'] ?? null,
                'enabled' => $current['enabled'],
                'alive' => $this->settings->alive(),
                'polled_at' => $state['polled_at'],
                'error' => $state['error'],
                'error_at' => $state['error_at'],
            ],
            'notify' => [
                ...$notify,
                'listener' => $listener ? ['key' => $listener->key, 'name' => $listener->name ?: $listener->key] : null,
                'last' => $lastNotice ? [
                    'at' => $lastNotice->notified_at,
                    'error' => $lastNotice->notify_error,
                    'requested_at' => $lastNotice->requested_at,
                ] : null,
                'test' => SenderBotNotices::testState(),
            ],
            'listeners' => TelegramListener::query()
                ->whereNotNull('telegram_account_id')
                ->orderBy('id')
                ->get(['key', 'name', 'is_enabled'])
                ->map(fn (TelegramListener $l) => ['key' => $l->key, 'name' => $l->name ?: $l->key, 'enabled' => (bool) $l->is_enabled]),
            'limits' => $this->settings->limits(),
            'options' => $this->settings->options(),
            'quiet_now' => $this->settings->quietUntil()?->toIso8601String(),
            'max' => [
                'times' => SenderBotSettings::MAX_TIMES,
                'interval' => SenderBotSettings::MAX_INTERVAL,
                'active' => SenderBotSettings::MAX_ACTIVE,
                'per_day' => SenderBotSettings::MAX_PER_DAY,
            ],
            'counts' => [
                'pending' => (int) ($users[TelegramSenderBotUser::STATUS_PENDING] ?? 0),
                'approved' => (int) ($users[TelegramSenderBotUser::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($users[TelegramSenderBotUser::STATUS_REJECTED] ?? 0),
                'blocked' => (int) ($users[TelegramSenderBotUser::STATUS_BLOCKED] ?? 0),
                'new' => (int) ($users[TelegramSenderBotUser::STATUS_NEW] ?? 0),
                'groups_on' => TelegramSenderBotGroup::query()->usable()->count(),
                'groups' => TelegramSenderBotGroup::query()->count(),
                'posts_active' => (int) ($posts[TelegramSenderBotPost::STATUS_ACTIVE] ?? 0),
                'posts_paused' => (int) ($posts[TelegramSenderBotPost::STATUS_PAUSED] ?? 0),
                'posts_done' => (int) ($posts[TelegramSenderBotPost::STATUS_DONE] ?? 0),
                'posts_stopped' => (int) ($posts[TelegramSenderBotPost::STATUS_STOPPED] ?? 0),
                'posts_failed' => (int) ($posts[TelegramSenderBotPost::STATUS_FAILED] ?? 0),
                'posts_all' => (int) $posts->sum(),
                'posts_today' => TelegramSenderBotPost::query()->where('created_at', '>=', now()->startOfDay())->count(),
                'copies_today' => TelegramSenderBotSend::query()->where('created_at', '>=', now()->startOfDay())->whereNull('error')->count(),
                'leads' => TelegramSenderBotLead::query()->where('status', '!=', TelegramSenderBotLead::STATUS_NOT_FOUND)->count(),
                'leads_today' => TelegramSenderBotLead::query()->where('created_at', '>=', now()->startOfDay())->where('status', '!=', TelegramSenderBotLead::STATUS_NOT_FOUND)->count(),
                'leads_waiting' => TelegramSenderBotLead::query()->where('status', TelegramSenderBotLead::STATUS_ASKED)->where(fn ($q) => $q->whereNull('error')->orWhere('error', '!=', 'cancelled'))->count(),
            ],
        ]);
    }

    /** Only the number of requests waiting: the menu badge asks it now and then. */
    public function badge(): JsonResponse
    {
        return response()->json(['pending' => self::pendingCount()]);
    }

    public static function pendingCount(): int
    {
        try {
            return TelegramSenderBotUser::query()->where('status', TelegramSenderBotUser::STATUS_PENDING)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:120', 'regex:/^\s*\d{5,}:[A-Za-z0-9_-]{30,}\s*$/'],
        ], [
            'token.regex' => __('driver_check.sender_bot.errors.format'),
        ]);

        try {
            $bot = $this->bot->connect(trim($data['token']));
        } catch (DomainException $e) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.' . $e->getMessage())], 422);
        } catch (BotApiError $e) {
            return response()->json([
                'message' => $e->getCode() === 401 || $e->getCode() === 404
                    ? __('driver_check.sender_bot.errors.invalid')
                    : __('driver_check.sender_bot.errors.telegram', ['reason' => $e->reason()]),
            ], 422);
        }

        return response()->json(['message' => __('driver_check.sender_bot.messages.connected', ['bot' => '@' . $bot['username']])]);
    }

    public function disconnect(): JsonResponse
    {
        $this->settings->forget();

        return response()->json(['message' => __('driver_check.sender_bot.messages.disconnected')]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        if ($this->settings->token() === null) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.no_bot')], 422);
        }

        $this->settings->setEnabled((bool) $data['enabled']);

        return response()->json(['message' => __('driver_check.sender_bot.messages.' . ($data['enabled'] ? 'enabled' : 'disabled'))]);
    }

    public function notify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:32', 'regex:/^\s*\d{5,20}\s*$/'],
            'listener_key' => ['nullable', 'string', 'max:64'],
            'via_bot' => ['required', 'boolean'],
            'new_posts' => ['sometimes', 'boolean'],
        ], [
            'chat_id.regex' => __('driver_check.sender_bot.errors.chat'),
        ]);

        $this->settings->saveNotify(
            $data['chat_id'] ?? null,
            $data['listener_key'] ?? null,
            (bool) $data['via_bot'],
            (bool) ($data['new_posts'] ?? $this->settings->notify()['new_posts']),
        );

        return response()->json(['message' => __('driver_check.sender_bot.messages.saved')]);
    }

    public function testNotify(): JsonResponse
    {
        if ($this->settings->adminId() === null) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.chat')], 422);
        }

        if ($this->settings->notifyListener() === null) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.no_listener')], 422);
        }

        SenderBotNotices::askTest();

        return response()->json(['message' => __('driver_check.sender_bot.messages.test_asked')]);
    }

    public function limits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'max_times' => ['required', 'integer', 'min:1', 'max:' . SenderBotSettings::MAX_TIMES],
            'min_interval' => ['required', 'integer', 'min:1', 'max:' . SenderBotSettings::MAX_INTERVAL],
            'max_interval' => ['required', 'integer', 'gte:min_interval', 'max:' . SenderBotSettings::MAX_INTERVAL],
            'max_active' => ['sometimes', 'integer', 'min:1', 'max:' . SenderBotSettings::MAX_ACTIVE],
            'per_day' => ['sometimes', 'integer', 'min:1', 'max:' . SenderBotSettings::MAX_PER_DAY],
            'quiet_from' => ['nullable', 'string', 'regex:/^\s*\d{1,2}[:.]\d{2}\s*$/'],
            'quiet_to' => ['nullable', 'string', 'regex:/^\s*\d{1,2}[:.]\d{2}\s*$/', 'required_with:quiet_from'],
        ], [
            'quiet_from.regex' => __('driver_check.sender_bot.errors.clock'),
            'quiet_to.regex' => __('driver_check.sender_bot.errors.clock'),
            'quiet_to.required_with' => __('driver_check.sender_bot.errors.clock'),
        ]);

        $limits = $this->settings->limits();

        $this->settings->saveLimits(
            (int) $data['max_times'],
            (int) $data['min_interval'],
            (int) $data['max_interval'],
            (int) ($data['max_active'] ?? $limits['max_active']),
            (int) ($data['per_day'] ?? $limits['per_day']),
            $data['quiet_from'] ?? null,
            $data['quiet_to'] ?? null,
        );

        return response()->json(['message' => __('driver_check.sender_bot.messages.saved')]);
    }

    /** What drivers get: the operator's chat on a forward, the button under every copy. */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'driver_contacts' => ['required', 'boolean'],
            'contact_button' => ['required', 'boolean'],
        ]);

        $this->settings->saveOptions((bool) $data['driver_contacts'], (bool) $data['contact_button']);

        return response()->json(['message' => __('driver_check.sender_bot.messages.saved')]);
    }

    /** The people who pressed Start, by status. */
    public function users(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', TelegramSenderBotUser::STATUSES)],
        ])['status'] ?? null;

        $rows = TelegramSenderBotUser::query()
            ->withCount([
                'posts',
                'posts as active_posts_count' => fn ($q) => $q->where('status', TelegramSenderBotPost::STATUS_ACTIVE),
            ])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            /* The ones waiting first, then the latest. */
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TelegramSenderBotUser::STATUS_PENDING])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramSenderBotUser $u) => [
                'id' => $u->id,
                'telegram_user_id' => $u->telegram_user_id,
                'username' => $u->username,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'language' => $u->language,
                'status' => $u->status,
                'requested_at' => $u->requested_at,
                'decided_at' => $u->decided_at,
                'last_seen_at' => $u->last_seen_at,
                'notified_at' => $u->notified_at,
                'notify_error' => $u->notify_error,
                'posts' => $u->posts_count,
                'active_posts' => $u->active_posts_count,
            ]);

        return response()->json(['users' => $rows, 'list_size' => self::LIST_SIZE]);
    }

    public function decide(Request $request, TelegramSenderBotUser $senderBotUser): JsonResponse
    {
        $verdict = $request->validate(['verdict' => ['required', 'in:approve,reject,block,unblock']])['verdict'];

        $allowed = match ($verdict) {
            'approve', 'reject' => [TelegramSenderBotUser::STATUS_PENDING, TelegramSenderBotUser::STATUS_REJECTED, TelegramSenderBotUser::STATUS_NEW],
            'block' => [TelegramSenderBotUser::STATUS_APPROVED],
            'unblock' => [TelegramSenderBotUser::STATUS_BLOCKED],
        };

        if (! in_array($senderBotUser->status, $allowed, true)) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.already')], 422);
        }

        $error = $this->dialog->decide($senderBotUser, $verdict, $request->user()?->id);
        $message = __('driver_check.sender_bot.messages.' . $verdict, ['name' => $senderBotUser->name()]);

        if ($error !== null) {
            $message .= ' ' . __('driver_check.sender_bot.messages.not_told', ['reason' => $error]);
        }

        return response()->json(['message' => $message]);
    }

    /** Lets the listener tell the admin again of a request it could not. */
    public function renotify(TelegramSenderBotUser $senderBotUser): JsonResponse
    {
        $senderBotUser->forceFill(['notified_at' => null, 'notify_error' => null, 'requested_at' => now()])->save();

        return response()->json(['message' => __('driver_check.sender_bot.messages.renotify')]);
    }

    public function groups(): JsonResponse
    {
        $copies = DB::table('telegram_sender_bot_sends')
            ->select('sender_bot_group_id', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN error IS NULL THEN 1 ELSE 0 END) as ok'))
            ->groupBy('sender_bot_group_id')
            ->get()
            ->keyBy('sender_bot_group_id');

        return response()->json([
            'groups' => TelegramSenderBotGroup::query()
                ->orderByDesc('is_active')
                ->orderBy('title')
                ->get()
                ->map(fn (TelegramSenderBotGroup $g) => [
                    'id' => $g->id,
                    'chat_id' => $g->chat_id,
                    'title' => $g->label(),
                    'username' => $g->username,
                    'invite_link' => $g->invite_link,
                    'link' => $g->link(),
                    'type' => $g->type,
                    'is_active' => $g->is_active,
                    'bot_status' => $g->bot_status,
                    'bot_in' => $g->botIsIn(),
                    'last_error' => $g->last_error,
                    'last_sent_at' => $g->last_sent_at,
                    'created_at' => $g->created_at,
                    'copies' => (int) ($copies[$g->id]->ok ?? 0),
                    'failed' => (int) ($copies[$g->id]->total ?? 0) - (int) ($copies[$g->id]->ok ?? 0),
                ]),
        ]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['chat' => ['required', 'string', 'max:200']]);

        try {
            $group = $this->bot->addGroup($data['chat']);
        } catch (DomainException $e) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.group_' . $e->getMessage())], 422);
        }

        return response()->json([
            'message' => $group->botIsIn()
                ? __('driver_check.sender_bot.messages.group_added', ['group' => $group->label()])
                : __('driver_check.sender_bot.messages.group_added_no_bot', ['group' => $group->label()]),
        ]);
    }

    public function updateGroup(Request $request, TelegramSenderBotGroup $senderBotGroup): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'invite_link' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:~^https://(t\.me|telegram\.me)/\S+$~'],
        ], [
            'invite_link.regex' => __('driver_check.sender_bot.errors.invite_link'),
        ]);

        if (array_key_exists('invite_link', $data)) {
            $senderBotGroup->forceFill(['invite_link' => trim((string) $data['invite_link']) ?: null])->save();

            return response()->json(['message' => __('driver_check.sender_bot.messages.link_saved', ['group' => $senderBotGroup->label()])]);
        }

        if (! array_key_exists('is_active', $data)) {
            return response()->json(['message' => __('driver_check.sender_bot.messages.saved')]);
        }

        $senderBotGroup->forceFill(['is_active' => (bool) $data['is_active']])->save();
        SenderBotHistory::add($data['is_active'] ? 'group_on' : 'group_off', TelegramSenderBotEvent::ACTOR_ADMIN, group: $senderBotGroup, data: ['title' => $senderBotGroup->label()]);

        return response()->json([
            'message' => __('driver_check.sender_bot.messages.' . ($data['is_active'] ? 'group_on' : 'group_off'), ['group' => $senderBotGroup->label()]),
        ]);
    }

    public function destroyGroup(TelegramSenderBotGroup $senderBotGroup): JsonResponse
    {
        $label = $senderBotGroup->label();
        SenderBotHistory::add('group_removed', TelegramSenderBotEvent::ACTOR_ADMIN, group: $senderBotGroup, data: ['title' => $label]);
        $senderBotGroup->delete();

        return response()->json(['message' => __('driver_check.sender_bot.messages.group_deleted', ['group' => $label])]);
    }

    public function checkGroups(): JsonResponse
    {
        try {
            $checked = $this->bot->checkGroups();
        } catch (DomainException) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.no_bot')], 422);
        }

        return response()->json(['message' => __('driver_check.sender_bot.messages.groups_checked', ['count' => $checked])]);
    }

    public function posts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', TelegramSenderBotPost::STATUSES)],
            'user' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? null;
        $user = isset($filters['user']) ? (int) $filters['user'] : null;
        $groups = TelegramSenderBotGroup::query()->get()->keyBy('id');

        $rows = TelegramSenderBotPost::query()
            ->with('user:id,telegram_user_id,username,first_name,last_name')
            ->withCount([
                'sends as copies' => fn ($q) => $q->whereNull('error'),
                'sends as failed' => fn ($q) => $q->whereNotNull('error'),
                'sends as deletable' => fn ($q) => $q->whereNotNull('message_id')->whereNull('deleted_at')
                    ->where('created_at', '>=', now()->subHours(TelegramSenderBotPost::DELETE_HOURS)),
                'leads' => fn ($q) => $q->where('status', '!=', TelegramSenderBotLead::STATUS_NOT_FOUND),
            ])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($user !== null, fn ($q) => $q->where('sender_bot_user_id', $user))
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', TelegramSenderBotPost::OPEN)
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramSenderBotPost $p) => [
                'id' => $p->id,
                'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name(), 'username' => $p->user->username] : null,
                'groups' => $p->chosenGroups() === null ? null : collect($p->chosenGroups())->map(fn ($id) => $groups->get($id)?->label() ?? '#' . $id)->values(),
                'paused_at' => $p->paused_at,
                'repeat_of_id' => $p->repeat_of_id,
                'delete_asked_at' => $p->delete_asked_at,
                'copies_deleted_at' => $p->copies_deleted_at,
                'deletable' => $p->deletable,
                'leads' => $p->leads_count,
                'kind' => $p->kind,
                'text' => $p->text,
                'times' => $p->times,
                'sent_times' => $p->sent_times,
                'interval_minutes' => $p->interval_minutes,
                'next_at' => $p->next_at,
                'status' => $p->status,
                'stopped_by' => $p->stopped_by,
                'last_error' => $p->last_error,
                'created_at' => $p->created_at,
                'finished_at' => $p->finished_at,
                'copies' => $p->copies,
                'failed' => $p->failed,
            ]);

        return response()->json([
            'posts' => $rows,
            'list_size' => self::LIST_SIZE,
            'person' => $user !== null ? TelegramSenderBotUser::query()->find($user)?->name() : null,
        ]);
    }

    /**
     * One post in full: its text, groups, each round group by group (with
     * the link to the message), its history and the drivers it brought.
     */
    public function post(TelegramSenderBotPost $senderBotPost): JsonResponse
    {
        $post = $senderBotPost->load('user');

        $rounds = $post->sends()
            ->with('group')
            ->orderBy('round')
            ->orderBy('id')
            ->get()
            ->groupBy('round')
            ->map(fn ($sends, $round) => [
                'round' => (int) $round,
                'at' => $sends->first()->created_at,
                'ok' => $sends->whereNull('error')->count(),
                'sends' => $sends->map(fn (TelegramSenderBotSend $s) => [
                    'group' => $s->group?->label() ?? '—',
                    'link' => $s->error === null && $s->deleted_at === null ? $s->group?->messageLink($s->message_id) : null,
                    'error' => $s->error,
                    'deleted_at' => $s->deleted_at,
                ])->values(),
            ])
            ->values();

        $groups = TelegramSenderBotGroup::query()->get()->keyBy('id');

        return response()->json([
            'id' => $post->id,
            'text' => $post->text,
            'kind' => $post->kind,
            'groups' => $post->chosenGroups() === null ? null : collect($post->chosenGroups())->map(fn ($id) => $groups->get($id)?->label() ?? '#' . $id)->values(),
            'rounds' => $rounds,
            'events' => $this->events(TelegramSenderBotEvent::query()->where('sender_bot_post_id', $post->id)->orderBy('id')->limit(300)->get()),
            'leads' => $post->leads()->latest('id')->limit(100)->get()->map(fn (TelegramSenderBotLead $l) => $this->lead($l)),
        ]);
    }

    /** Pause, resume, send now, delete the copies from the groups - as the admin; the author is told. */
    public function postAction(TelegramSenderBotPost $senderBotPost, string $action): JsonResponse
    {
        $post = $senderBotPost->load('user');
        $admin = TelegramSenderBotEvent::ACTOR_ADMIN;

        if ($action === 'delete') {
            $count = $this->actions->askDelete($post, $admin);

            return $count > 0
                ? response()->json(['message' => __('driver_check.sender_bot.messages.delete_queued', ['count' => $count, 'id' => $post->id])])
                : response()->json(['message' => __('driver_check.sender_bot.errors.nothing_to_delete')], 422);
        }

        $done = match ($action) {
            'pause' => $this->actions->pause($post, $admin),
            'resume' => $this->actions->resume($post, $admin),
            'now' => $this->actions->sendNow($post, $admin),
        };

        if (! $done) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.not_active')], 422);
        }

        if ($action !== 'now') {
            $this->dialog->toldByAdmin($post, $action === 'pause' ? 'paused_by_admin' : 'resumed_by_admin');
        }

        return response()->json(['message' => __('driver_check.sender_bot.messages.post_' . $action, ['id' => $post->id])]);
    }

    public function stopPost(TelegramSenderBotPost $senderBotPost): JsonResponse
    {
        if (! $this->actions->stop($senderBotPost, TelegramSenderBotEvent::ACTOR_ADMIN)) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.not_active')], 422);
        }

        $this->dialog->stoppedByAdmin($senderBotPost->fresh('user'));

        return response()->json(['message' => __('driver_check.sender_bot.messages.post_stopped', ['id' => $senderBotPost->id])]);
    }

    /**
     * The history: by kind (requests, posts, drivers, groups), by person, a
     * page at a time (before = the last id seen).
     */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:' . implode(',', array_keys(TelegramSenderBotEvent::GROUPS))],
            'user' => ['nullable', 'integer'],
            'before' => ['nullable', 'integer'],
        ]);

        $rows = TelegramSenderBotEvent::query()
            ->when(isset($filters['type']), fn ($q) => $q->whereIn('type', TelegramSenderBotEvent::GROUPS[$filters['type']]))
            ->when(isset($filters['user']), fn ($q) => $q->where('sender_bot_user_id', (int) $filters['user']))
            ->when(isset($filters['before']), fn ($q) => $q->where('id', '<', (int) $filters['before']))
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get();

        return response()->json([
            'events' => $this->events($rows),
            'more' => $rows->count() === self::LIST_SIZE,
            'person' => isset($filters['user']) ? TelegramSenderBotUser::query()->find((int) $filters['user'])?->name() : null,
        ]);
    }

    /** The drivers who wanted an operator, by what came of it. */
    public function leads(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', TelegramSenderBotLead::STATUSES)],
        ])['status'] ?? null;

        $counts = TelegramSenderBotLead::query()
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'leads' => TelegramSenderBotLead::query()
                ->with(['post', 'user'])
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->latest('id')
                ->limit(self::LIST_SIZE)
                ->get()
                ->map(fn (TelegramSenderBotLead $l) => $this->lead($l)),
            'counts' => collect(TelegramSenderBotLead::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'list_size' => self::LIST_SIZE,
        ]);
    }

    private function lead(TelegramSenderBotLead $l): array
    {
        return [
            'id' => $l->id,
            'status' => $l->status,
            'source' => $l->source,
            'driver' => [
                'name' => $l->driverLabel(),
                'username' => $l->driver_username,
                'telegram_id' => $l->driver_telegram_id,
                'phone' => $l->driver_phone,
            ],
            'post' => $l->post ? ['id' => $l->post->id, 'text' => SenderBotDialog::preview($l->post, 'ru', 80)] : null,
            'operator' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name(), 'username' => $l->user->username] : null,
            'error' => $l->error,
            'created_at' => $l->created_at,
            'updated_at' => $l->updated_at,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, TelegramSenderBotEvent> $events
     */
    private function events($events): array
    {
        $users = TelegramSenderBotUser::query()->whereIn('id', $events->pluck('sender_bot_user_id')->filter()->unique())->get()->keyBy('id');
        $posts = TelegramSenderBotPost::query()->whereIn('id', $events->pluck('sender_bot_post_id')->filter()->unique())->get()->keyBy('id');
        $groups = TelegramSenderBotGroup::query()->whereIn('id', $events->pluck('sender_bot_group_id')->filter()->unique())->get()->keyBy('id');

        return $events->map(fn (TelegramSenderBotEvent $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'actor' => $e->actor,
            'user' => ($u = $users[$e->sender_bot_user_id] ?? null) ? ['id' => $u->id, 'name' => $u->name(), 'username' => $u->username] : null,
            'post' => ($p = $posts[$e->sender_bot_post_id] ?? null) ? ['id' => $p->id, 'text' => SenderBotDialog::preview($p, 'ru', 70)] : null,
            'group' => ($g = $groups[$e->sender_bot_group_id] ?? null) ? $g->label() : ($e->data['title'] ?? null),
            'data' => $e->data ?? [],
            'at' => $e->created_at,
        ])->values()->all();
    }
}
