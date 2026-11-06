<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSpamAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The admins of our anti-spam: their links in our groups stay
 * (SpamCleaner), they may forward spam to the join request bot
 * (ForwardedSpam). The owner - whoever the bot's reports go to - is one
 * always.
 *
 * The panel puts them on by ID, @username or a t.me link (one typed by
 * @username is known by the id from the first time the bot sees them), or
 * makes an invite link: t.me/<bot>?start=adm_..., good once and for
 * INVITE_DAYS. Whoever opens it and presses Start is an admin, the bot
 * tells them so and the owner learns who came.
 *
 * An add-on: a trouble of its own is logged; nobody is an admin by a guess.
 */
final class SpamAdmins
{
    /** The /start parameter of an invite link starts so. */
    public const INVITE_PREFIX = 'adm_';

    /** An invite link not opened is good this many days. */
    public const INVITE_DAYS = 7;

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly JoinBotReporter $reporter,
    ) {}

    /**
     * Whether they are an admin: the owner, or one of the list - by id, or
     * by @username while the id is not known (then the id is learnt).
     *
     * @param array<string, mixed> $user a Bot API User or Chat
     */
    public function isAdmin(array $user): bool
    {
        $id = (int) ($user['id'] ?? 0);

        if ($id === 0) {
            return false;
        }

        if ($id === $this->owner()) {
            return true;
        }

        try {
            if (TelegramSpamAdmin::query()->where('telegram_user_id', $id)->exists()) {
                return true;
            }

            $username = SpamCleaner::username($user['username'] ?? null);

            if ($username === null) {
                return false;
            }

            $admin = TelegramSpamAdmin::query()->whereNull('telegram_user_id')->where('username', $username)->first();

            if ($admin === null) {
                return false;
            }

            $admin->update([
                'telegram_user_id' => $id,
                'name' => SpamCleaner::cut(SpamCleaner::nameOf($user), 255) ?? $admin->name,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Join bot: the admins of the anti-spam could not be read', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The owner: whoever the bot's reports go to, when it is a person (a
     * group's chat id is nobody).
     */
    public function owner(): ?int
    {
        $chat = (string) $this->settings->reports()['chat_id'];

        return preg_match('/^\d{3,16}$/', $chat) === 1 ? (int) $chat : null;
    }

    /**
     * Whoever is not an admin yet becomes one. Someone typed by id gets
     * the name the base of people has for them.
     *
     * @param list<array{telegram_user_id: int|null, username: string|null}> $entries SpamCleaner::parse()
     *
     * @return array{added: int, known: int}
     */
    public function add(array $entries, ?string $note): array
    {
        $added = 0;
        $known = 0;
        $note = SpamCleaner::cut($note, 255);

        foreach ($entries as $entry) {
            $exists = TelegramSpamAdmin::query()
                ->when(
                    $entry['telegram_user_id'] !== null,
                    fn ($q) => $q->where('telegram_user_id', $entry['telegram_user_id']),
                    fn ($q) => $q->where('username', $entry['username']),
                )
                ->exists();

            if ($exists || ($entry['telegram_user_id'] !== null && $entry['telegram_user_id'] === $this->owner())) {
                $known++;

                continue;
            }

            $person = $entry['telegram_user_id'] !== null
                ? TelegramScrapedUser::query()->where('telegram_user_id', $entry['telegram_user_id'])->first()
                : null;

            TelegramSpamAdmin::query()->create([
                'telegram_user_id' => $entry['telegram_user_id'],
                'username' => $entry['username'] ?? SpamCleaner::username($person?->username),
                'name' => $person !== null ? SpamCleaner::cut(trim($person->first_name . ' ' . $person->last_name), 255) : null,
                'note' => $note,
            ]);

            $added++;
        }

        return ['added' => $added, 'known' => $known];
    }

    /**
     * A new invite link, good once and for INVITE_DAYS.
     */
    public function invite(?string $note): TelegramSpamAdmin
    {
        return TelegramSpamAdmin::query()->create([
            'note' => SpamCleaner::cut($note, 255),
            'invite_token' => self::INVITE_PREFIX . Str::random(24),
            'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
        ]);
    }

    /**
     * The link to send: it opens our bot with the invite in it. Null: no
     * bot connected, or not an invite.
     */
    public function link(TelegramSpamAdmin $admin): ?string
    {
        $bot = (string) ($this->settings->current()['bot']['username'] ?? '');

        return $bot !== '' && $admin->invite_token !== null ? "https://t.me/{$bot}?start={$admin->invite_token}" : null;
    }

    /**
     * Each of our groups (not archived) as Telegram has it now: its admins -
     * their links stay there - and how our bot stands in it: ok (an admin
     * that may delete), no_delete (an admin without that right), not_admin
     * (a plain member: it does not even see the messages), absent, unknown
     * (the group's chat is not known yet), error.
     *
     * @return list<array{id: int, title: string, bot: string, error: string|null, admins: list<array{id: int, name: string|null, username: string|null, creator: bool, anonymous: bool, is_bot: bool}>}>
     */
    public function groups(): array
    {
        $current = $this->settings->current();

        if ($current['token'] === null) {
            return [];
        }

        $api = new BotApi($current['token']);
        $botId = (int) ($current['bot']['id'] ?? 0);

        return TelegramInviteGroup::query()
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->map(function (TelegramInviteGroup $group) use ($api, $botId): array {
                $row = ['id' => $group->id, 'title' => $group->title ?: $group->link, 'bot' => 'unknown', 'error' => null, 'admins' => []];
                $chat = $group->telegram_chat_id ?: ($group->isInvite() ? null : '@' . ltrim($group->link, '@'));

                if ($chat === null) {
                    return $row;
                }

                try {
                    $list = (array) $api->call('getChatAdministrators', ['chat_id' => $chat], 8);
                } catch (BotApiError $e) {
                    return [...$row, 'bot' => in_array($e->getCode(), [400, 403], true) ? 'absent' : 'error', 'error' => $e->reason()];
                }

                $row['bot'] = 'not_admin';

                foreach ($list as $member) {
                    $user = is_array($member) ? (array) ($member['user'] ?? []) : [];

                    if (empty($user['id'])) {
                        continue;
                    }

                    if ((int) $user['id'] === $botId) {
                        $row['bot'] = ($member['status'] ?? null) === 'creator' || ! empty($member['can_delete_messages']) ? 'ok' : 'no_delete';

                        continue;
                    }

                    $row['admins'][] = [
                        'id' => (int) $user['id'],
                        'name' => SpamCleaner::nameOf($user),
                        'username' => $user['username'] ?? null,
                        'creator' => ($member['status'] ?? null) === 'creator',
                        'anonymous' => ! empty($member['is_anonymous']),
                        'is_bot' => ! empty($user['is_bot']),
                    ];
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * A message to the bot in its own chat: "/start adm_..." - somebody
     * opened an invite link. They become an admin and are answered; true.
     * Anything else: false, it is not this one's.
     *
     * @param array<string, mixed>   $message
     * @param callable(string): void $say
     */
    public function takeStart(BotApi $api, array $message, callable $say): bool
    {
        $chat = (array) ($message['chat'] ?? []);
        $from = (array) ($message['from'] ?? []);
        $text = trim((string) ($message['text'] ?? ''));

        if (($chat['type'] ?? null) !== 'private' || empty($from['id']) || ! empty($from['is_bot'])) {
            return false;
        }

        if (preg_match('/^\/start(?:@\w+)?\s+(' . preg_quote(self::INVITE_PREFIX, '/') . '[A-Za-z0-9]{8,60})$/', $text, $m) !== 1) {
            return false;
        }

        try {
            $answer = $this->accept($m[1], $from, $say);
        } catch (Throwable $e) {
            Log::warning('Join bot: an invite link of the anti-spam could not be taken', ['error' => $e->getMessage()]);
            $answer = $this->line('failed');
        }

        $this->answer($api, $message, $answer);

        return true;
    }

    /**
     * The invite spent on them: its line is theirs now - or, an admin
     * already, their line is brought up to date and the invite goes.
     *
     * @param array<string, mixed>   $from
     * @param callable(string): void $say
     *
     * @return string the answer
     */
    private function accept(string $token, array $from, callable $say): string
    {
        $id = (int) $from['id'];
        $username = SpamCleaner::username($from['username'] ?? null);
        $name = SpamCleaner::cut(SpamCleaner::nameOf($from), 255);
        $who = JoinRequestService::who($from);

        $note = DB::transaction(function () use ($token, $id, $username, $name): string|false|null {
            $invite = TelegramSpamAdmin::query()->where('invite_token', $token)->lockForUpdate()->first();

            if ($invite === null || $invite->invite_expires_at?->isPast()) {
                return false;
            }

            $already = TelegramSpamAdmin::query()
                ->whereKeyNot($invite->id)
                ->where(fn ($q) => $q
                    ->where('telegram_user_id', $id)
                    ->when($username !== null, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('telegram_user_id')->where('username', $username))))
                ->first();

            if ($already !== null) {
                $already->fill(['telegram_user_id' => $id, 'username' => $username, 'name' => $name ?? $already->name]);
                $already->note ??= $invite->note;
                $already->save();
                $invite->delete();

                return $invite->note;
            }

            $invite->update([
                'telegram_user_id' => $id,
                'username' => $username,
                'name' => $name,
                'invite_token' => null,
                'invite_expires_at' => null,
                'joined_at' => now(),
            ]);

            return $invite->note;
        });

        if ($note === false) {
            $say("admins: {$who} opened an invite link spent or out of date");

            return $this->line('spent');
        }

        $say("admins: {$who} came by an invite link");
        $this->reporter->send('admin_joined', ['who' => $who, 'note' => $note !== null ? "\n{$note}" : '']);

        return $this->line('welcome', ['name' => (string) ($from['first_name'] ?? '')]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function answer(BotApi $api, array $message, string $text): void
    {
        try {
            $api->call('sendMessage', [
                'chat_id' => (int) $message['chat']['id'],
                'text' => mb_substr($text, 0, 4000),
                'link_preview_options' => ['is_disabled' => true],
            ], 10);
        } catch (BotApiError $e) {
            Log::warning('Join bot: the answer to an invite link did not get through', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return (string) __("driver_check.invites.spam.admins.bot.{$key}", $replace, $this->settings->reports()['locale']);
    }
}
