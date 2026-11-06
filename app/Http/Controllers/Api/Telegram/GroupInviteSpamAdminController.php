<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\SpamAdmins;
use App\Application\Telegram\Services\SpamCleaner;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramSpamAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bot of groups -> Admins: the admins of our anti-spam, on one page. Their
 * links in our groups stay, they may forward spam to the bot. Put on by
 * ID, @username or a t.me link, or by an invite link made here; the owner
 * is one always.
 * Access is the `role:driverCheck,superadmin` middleware of the route group.
 */
final class GroupInviteSpamAdminController extends Controller
{
    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly SpamAdmins $admins,
    ) {}

    public function show(): JsonResponse
    {
        $current = $this->settings->current();
        $rows = TelegramSpamAdmin::query()->latest('id')->get();

        return response()->json([
            'owner' => $this->admins->owner(),
            'bot' => [
                'connected' => $current['token'] !== null,
                'username' => $current['bot']['username'] ?? null,
            ],
            'invite_days' => SpamAdmins::INVITE_DAYS,
            'admins' => $rows->reject(fn (TelegramSpamAdmin $row) => $row->isInvite())->map(fn (TelegramSpamAdmin $row) => [
                'id' => $row->id,
                'telegram_user_id' => $row->telegram_user_id,
                'username' => $row->username,
                'name' => $row->name,
                'note' => $row->note,
                'by_invite' => $row->joined_at !== null,
                'added_at' => $row->joined_at ?? $row->created_at,
            ])->values(),
            'invites' => $rows->filter(fn (TelegramSpamAdmin $row) => $row->isInvite())->map(fn (TelegramSpamAdmin $row) => [
                'id' => $row->id,
                'note' => $row->note,
                'link' => $this->admins->link($row),
                'expires_at' => $row->invite_expires_at,
                'expired' => $row->invite_expires_at?->isPast() ?? false,
                'created_at' => $row->created_at,
            ])->values(),
        ]);
    }

    /**
     * IDs, @usernames or t.me links: whoever is not an admin yet becomes
     * one. What was not understood is named back.
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

        $result = $this->admins->add($parsed['entries'], $data['note'] ?? null);

        $parts = [__('driver_check.invites.spam.admins.messages.added', ['n' => $result['added']])];

        if ($result['known'] > 0) {
            $parts[] = __('driver_check.invites.spam.admins.messages.known', ['n' => $result['known']]);
        }

        if ($parsed['invalid'] !== []) {
            $parts[] = __('driver_check.invites.spam.messages.invalid', ['words' => implode(', ', array_slice($parsed['invalid'], 0, 5))]);
        }

        return response()->json([
            'message' => implode(' ', $parts),
            'invalid' => $parsed['invalid'],
        ]);
    }

    /**
     * A new invite link to send to somebody: they open the bot, press
     * Start, and are an admin. Good once and for SpamAdmins::INVITE_DAYS.
     */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        if (empty($this->settings->current()['bot']['username'] ?? null)) {
            return response()->json(['message' => __('driver_check.invites.spam.admins.messages.no_bot')], 422);
        }

        $invite = $this->admins->invite($data['note'] ?? null);

        return response()->json([
            'message' => __('driver_check.invites.spam.admins.messages.invite_made'),
            'id' => $invite->id,
            'link' => $this->admins->link($invite),
        ]);
    }

    /**
     * An admin no more - their links go as anybody's; or an invite link
     * that will not work any more.
     */
    public function destroy(TelegramSpamAdmin $spamAdmin): JsonResponse
    {
        $invite = $spamAdmin->isInvite();
        $spamAdmin->delete();

        return response()->json([
            'message' => __($invite ? 'driver_check.invites.spam.admins.messages.invite_removed' : 'driver_check.invites.spam.admins.messages.removed'),
        ]);
    }
}
