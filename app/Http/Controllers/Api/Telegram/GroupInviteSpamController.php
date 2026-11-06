<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\SpamAdmins;
use App\Application\Telegram\Services\SpamCleaner;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramSpamAdmin;
use App\Models\Telegram\TelegramSpamMessage;
use App\Models\Telegram\TelegramSpamSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group invites: the spam list. Our join request bot deletes what these
 * people write in our groups a few seconds after they wrote it and tells
 * the owner without a sound; they stay in the group. The list, the wait
 * and the switch of the reports, the messages the bot deleted.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteSpamController extends Controller
{
    private const LIST_SIZE = 50;

    private const STATUSES = [
        TelegramSpamMessage::STATUS_PENDING,
        TelegramSpamMessage::STATUS_DELETED,
        TelegramSpamMessage::STATUS_FAILED,
    ];

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly SpamCleaner $spam,
        private readonly SpamAdmins $admins,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:' . implode(',', self::STATUSES)]])['status'] ?? null;

        $current = $this->settings->current();
        $today = now()->startOfDay();

        $pending = TelegramSpamMessage::query()
            ->where('status', TelegramSpamMessage::STATUS_PENDING)
            ->whereNotNull('spam_sender_id')
            ->selectRaw('spam_sender_id, COUNT(*) as n')
            ->groupBy('spam_sender_id')
            ->pluck('n', 'spam_sender_id');

        $senders = TelegramSpamSender::query()
            ->latest('id')
            ->get()
            ->map(fn (TelegramSpamSender $sender) => [
                'id' => $sender->id,
                'telegram_user_id' => $sender->telegram_user_id,
                'username' => $sender->username,
                'name' => $sender->name,
                'note' => $sender->note,
                'deleted' => $sender->deleted_count,
                'pending' => (int) ($pending[$sender->id] ?? 0),
                'last_deleted_at' => $sender->last_deleted_at,
                'added_at' => $sender->created_at,
            ]);

        $messages = TelegramSpamMessage::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (TelegramSpamMessage $row) => [
                'id' => $row->id,
                'at' => $row->sent_at ?? $row->created_at,
                'delete_at' => $row->delete_at,
                'removed_at' => $row->removed_at,
                'group' => $row->chat_title ?: (string) $row->chat_id,
                'telegram_user_id' => $row->telegram_user_id,
                'username' => $row->username,
                'name' => $row->name,
                'kind' => $row->kind,
                'reason' => $row->reason,
                'text' => $row->text !== null ? mb_substr($row->text, 0, 300) : null,
                'status' => $row->status,
                'error' => $row->error,
            ]);

        return response()->json([
            'settings' => $this->settings->spam(),
            'bot' => [
                'connected' => $current['token'] !== null,
                'username' => $current['bot']['username'] ?? null,
                'enabled' => $current['enabled'],
                'alive' => $this->settings->alive(),
            ],
            'reports' => [
                'enabled' => $this->settings->reports()['enabled'],
                'chat_id' => $this->settings->reports()['chat_id'],
            ],
            'today' => [
                'deleted' => TelegramSpamMessage::query()->where('status', TelegramSpamMessage::STATUS_DELETED)->where('removed_at', '>=', $today)->count(),
                'failed' => TelegramSpamMessage::query()->where('status', TelegramSpamMessage::STATUS_FAILED)->where('updated_at', '>=', $today)->count(),
            ],
            /* The list's and the links'. */
            'pending' => TelegramSpamMessage::query()->where('status', TelegramSpamMessage::STATUS_PENDING)->count(),
            'total_deleted' => (int) TelegramSpamSender::query()->sum('deleted_count'),
            'senders' => $senders,
            'messages' => $messages,
            'list_size' => self::LIST_SIZE,
            'max_delay' => JoinBotSettings::MAX_SPAM_DELAY,
            /* Who may forward a spammer's message to the bot: the owner and the admins of the anti-spam. */
            'forward' => [
                'owner' => $this->admins->owner(),
                'admins' => TelegramSpamAdmin::query()->where(fn ($q) => $q->whereNotNull('telegram_user_id')->orWhereNotNull('username'))->count(),
            ],
        ]);
    }

    /**
     * Ids, @usernames or t.me links, one per line: whoever is not on the
     * list yet goes on it. What was not understood is named back.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $parsed = SpamCleaner::parse($data['entries']);

        if ($parsed['entries'] === []) {
            return response()->json([
                'message' => __('driver_check.invites.spam.messages.nothing', ['words' => implode(', ', array_slice($parsed['invalid'], 0, 5))]),
                'invalid' => $parsed['invalid'],
            ], 422);
        }

        $result = $this->spam->add($parsed['entries'], $data['note'] ?? null);

        $parts = [__('driver_check.invites.spam.messages.added', ['n' => $result['added']])];

        if ($result['known'] > 0) {
            $parts[] = __('driver_check.invites.spam.messages.known', ['n' => $result['known']]);
        }

        if ($parsed['invalid'] !== []) {
            $parts[] = __('driver_check.invites.spam.messages.invalid', ['words' => implode(', ', array_slice($parsed['invalid'], 0, 5))]);
        }

        return response()->json([
            'message' => implode(' ', $parts),
            'added' => $result['added'],
            'known' => $result['known'],
            'invalid' => $parsed['invalid'],
        ]);
    }

    /**
     * Off the list: what they write stays; their messages still waiting
     * are not deleted.
     */
    public function destroy(TelegramSpamSender $spamSender): JsonResponse
    {
        $this->spam->remove($spamSender);

        return response()->json(['message' => __('driver_check.invites.spam.messages.removed')]);
    }

    /**
     * How many seconds after it was sent a message goes, whether the owner
     * is told of each (without a sound), whether every message with a link
     * goes too (the owner gets its copy). Who may forward spam to the bot:
     * the admins' page (GroupInviteSpamAdminController).
     */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delay' => ['required', 'integer', 'min:0', 'max:' . JoinBotSettings::MAX_SPAM_DELAY],
            'notify' => ['required', 'boolean'],
            'links' => ['sometimes', 'boolean'],
        ]);

        $this->settings->saveSpam(
            (int) $data['delay'],
            (bool) $data['notify'],
            array_key_exists('links', $data) ? (bool) $data['links'] : null,
        );

        return response()->json(['message' => __('driver_check.invites.spam.messages.saved', ['n' => (int) $data['delay']])]);
    }
}
