<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\SenderBotDialog;
use App\Application\Telegram\Services\SenderBotNotices;
use App\Application\Telegram\Services\SenderBotService;
use App\Application\Telegram\Services\SenderBotSettings;
use App\Application\Telegram\Support\BotApiError;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
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
            'max' => ['times' => SenderBotSettings::MAX_TIMES, 'interval' => SenderBotSettings::MAX_INTERVAL],
            'counts' => [
                'pending' => (int) ($users[TelegramSenderBotUser::STATUS_PENDING] ?? 0),
                'approved' => (int) ($users[TelegramSenderBotUser::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($users[TelegramSenderBotUser::STATUS_REJECTED] ?? 0),
                'blocked' => (int) ($users[TelegramSenderBotUser::STATUS_BLOCKED] ?? 0),
                'new' => (int) ($users[TelegramSenderBotUser::STATUS_NEW] ?? 0),
                'groups_on' => TelegramSenderBotGroup::query()->usable()->count(),
                'groups' => TelegramSenderBotGroup::query()->count(),
                'posts_active' => (int) ($posts[TelegramSenderBotPost::STATUS_ACTIVE] ?? 0),
                'posts_done' => (int) ($posts[TelegramSenderBotPost::STATUS_DONE] ?? 0),
                'posts_stopped' => (int) ($posts[TelegramSenderBotPost::STATUS_STOPPED] ?? 0),
                'posts_failed' => (int) ($posts[TelegramSenderBotPost::STATUS_FAILED] ?? 0),
                'posts_all' => (int) $posts->sum(),
                'posts_today' => TelegramSenderBotPost::query()->where('created_at', '>=', now()->startOfDay())->count(),
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
        ], [
            'chat_id.regex' => __('driver_check.sender_bot.errors.chat'),
        ]);

        $this->settings->saveNotify($data['chat_id'] ?? null, $data['listener_key'] ?? null, (bool) $data['via_bot']);

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
        ]);

        $this->settings->saveLimits((int) $data['max_times'], (int) $data['min_interval'], (int) $data['max_interval']);

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
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $senderBotGroup->forceFill(['is_active' => (bool) $data['is_active']])->save();

        return response()->json([
            'message' => __('driver_check.sender_bot.messages.' . ($data['is_active'] ? 'group_on' : 'group_off'), ['group' => $senderBotGroup->label()]),
        ]);
    }

    public function destroyGroup(TelegramSenderBotGroup $senderBotGroup): JsonResponse
    {
        $label = $senderBotGroup->label();
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
        $status = $request->validate([
            'status' => ['nullable', 'in:active,done,stopped,failed'],
        ])['status'] ?? null;

        $rows = TelegramSenderBotPost::query()
            ->with('user:id,telegram_user_id,username,first_name,last_name')
            ->withCount([
                'sends as copies' => fn ($q) => $q->whereNull('error'),
                'sends as failed' => fn ($q) => $q->whereNotNull('error'),
            ])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TelegramSenderBotPost::STATUS_ACTIVE])
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramSenderBotPost $p) => [
                'id' => $p->id,
                'user' => $p->user ? ['name' => $p->user->name(), 'username' => $p->user->username] : null,
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

        return response()->json(['posts' => $rows, 'list_size' => self::LIST_SIZE]);
    }

    public function stopPost(TelegramSenderBotPost $senderBotPost): JsonResponse
    {
        $stopped = TelegramSenderBotPost::query()
            ->whereKey($senderBotPost->id)
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->update([
                'status' => TelegramSenderBotPost::STATUS_STOPPED,
                'stopped_by' => TelegramSenderBotPost::STOPPED_BY_ADMIN,
                'finished_at' => now(),
                'next_at' => null,
            ]);

        if ($stopped === 0) {
            return response()->json(['message' => __('driver_check.sender_bot.errors.not_active')], 422);
        }

        $this->dialog->stoppedByAdmin($senderBotPost->fresh('user'));

        return response()->json(['message' => __('driver_check.sender_bot.messages.post_stopped', ['id' => $senderBotPost->id])]);
    }
}
