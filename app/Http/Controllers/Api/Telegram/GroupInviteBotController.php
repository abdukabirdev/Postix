<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotReporter;
use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\JoinRequestService;
use App\Application\Telegram\Support\BotApiError;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramJoinRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group invites: our bot that approves the requests to join our groups.
 * The token and the switch, whether the bot is asking Telegram, where it is
 * an admin, the requests it took and what came of each.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteBotController extends Controller
{
    private const KINDS = ['people', 'ours', 'failed'];

    private const LIST_SIZE = 100;

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly JoinRequestService $requests,
        private readonly JoinBotReporter $reporter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $kind = $request->validate(['kind' => ['nullable', 'in:' . implode(',', self::KINDS)]])['kind'] ?? null;

        $current = $this->settings->current();
        $state = $this->settings->state();
        $today = now()->startOfDay();

        $rows = TelegramJoinRequest::query()
            ->with(['group:id,title,link,deleted_at', 'account:id,phone'])
            ->when($kind === 'people', fn ($q) => $q->whereNull('telegram_account_id'))
            ->when($kind === 'ours', fn ($q) => $q->whereNotNull('telegram_account_id'))
            ->when($kind === 'failed', fn ($q) => $q->where('status', TelegramJoinRequest::STATUS_FAILED))
            ->latest('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramJoinRequest $row) => [
                'id' => $row->id,
                'at' => $row->created_at,
                'group' => $row->group ? ($row->group->title ?: $row->group->link) : ($row->chat_title ?? (string) $row->chat_id),
                'ours_group' => $row->invite_group_id !== null,
                'telegram_user_id' => $row->telegram_user_id,
                'username' => $row->username,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'account' => $row->account?->phone,
                'is_ours' => $row->telegram_account_id !== null,
                'is_new' => $row->is_new,
                'status' => $row->status,
                'error' => $row->error,
            ]);

        $todays = TelegramJoinRequest::query()->where('created_at', '>=', $today);

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
            'reports' => [
                'chat_id' => $this->settings->reports()['chat_id'],
                'enabled' => $this->settings->reports()['enabled'],
                ...$this->settings->reportsState(),
            ],
            'chats' => collect($state['chats'])
                ->map(fn (array $chat, string|int $id) => [...$chat, 'id' => (string) $id])
                ->sortByDesc('at')
                ->values(),
            'today' => [
                'people' => (clone $todays)->whereNull('telegram_account_id')->where('status', TelegramJoinRequest::STATUS_APPROVED)->count(),
                'new' => (clone $todays)->where('is_new', true)->count(),
                'ours' => (clone $todays)->whereNotNull('telegram_account_id')->where('status', TelegramJoinRequest::STATUS_APPROVED)->count(),
                'failed' => (clone $todays)->where('status', TelegramJoinRequest::STATUS_FAILED)->count(),
            ],
            'total' => [
                'people' => TelegramJoinRequest::query()->whereNull('telegram_account_id')->where('status', TelegramJoinRequest::STATUS_APPROVED)->count(),
                'new' => TelegramJoinRequest::query()->where('is_new', true)->count(),
            ],
            'requests' => $rows,
            'list_size' => self::LIST_SIZE,
        ]);
    }

    /**
     * Where the bot stands in each of our groups: asked of Telegram now,
     * apart from the page, so the page does not wait for it.
     */
    public function groups(): JsonResponse
    {
        return response()->json(['groups' => $this->requests->groups()]);
    }

    /**
     * The token from BotFather: checked with Telegram, saved, the bot on.
     */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:120', 'regex:/^\s*\d{5,}:[A-Za-z0-9_-]{30,}\s*$/'],
        ], [
            'token.regex' => __('driver_check.invites.bot.errors.format'),
        ]);

        try {
            $bot = $this->requests->connect(trim($data['token']));
        } catch (DomainException $e) {
            return response()->json(['message' => __('driver_check.invites.bot.errors.' . $e->getMessage())], 422);
        } catch (BotApiError $e) {
            return response()->json([
                'message' => $e->getCode() === 401 || $e->getCode() === 404
                    ? __('driver_check.invites.bot.errors.invalid')
                    : __('driver_check.invites.bot.errors.telegram', ['reason' => $e->reason()]),
            ], 422);
        }

        return response()->json([
            'message' => __('driver_check.invites.bot.messages.connected', ['bot' => '@' . $bot['username']]),
        ]);
    }

    /**
     * The switch: off, the scheduler stops asking Telegram; the requests
     * wait in the groups until it is on again.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        if ($this->settings->token() === null) {
            return response()->json(['message' => __('driver_check.invites.bot.errors.no_token')], 422);
        }

        $this->settings->setEnabled((bool) $data['enabled']);

        return response()->json([
            'message' => __('driver_check.invites.bot.messages.' . ($data['enabled'] ? 'enabled' : 'disabled')),
        ]);
    }

    /**
     * Where the bot reports everything it does: a chat id (a person's, or
     * a group's with a minus), on or off. Empty: nowhere.
     */
    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:32', 'regex:/^\s*-?\d{5,20}\s*$/'],
            'enabled' => ['required', 'boolean'],
        ], [
            'chat_id.regex' => __('driver_check.invites.bot.reports.bad_chat'),
        ]);

        $chat = trim((string) ($data['chat_id'] ?? ''));
        $enabled = $chat !== '' && (bool) $data['enabled'];

        $this->settings->saveReports($chat, $enabled, app()->getLocale());

        return response()->json([
            'message' => $enabled
                ? __('driver_check.invites.bot.reports.saved', ['chat' => $chat])
                : __('driver_check.invites.bot.reports.saved_off'),
        ]);
    }

    /**
     * A test message to the chat the reports go to.
     */
    public function testReport(): JsonResponse
    {
        if ($this->settings->token() === null) {
            return response()->json(['message' => __('driver_check.invites.bot.errors.no_token')], 422);
        }

        $error = $this->reporter->test(app()->getLocale());

        if ($error !== null) {
            return response()->json(['message' => __('driver_check.invites.bot.reports.test_failed', ['reason' => $error])], 422);
        }

        return response()->json(['message' => __('driver_check.invites.bot.reports.test_sent')]);
    }

    /**
     * The token is forgotten; the requests it took stay.
     */
    public function disconnect(): JsonResponse
    {
        $this->settings->forget();

        return response()->json(['message' => __('driver_check.invites.bot.messages.disconnected')]);
    }
}
