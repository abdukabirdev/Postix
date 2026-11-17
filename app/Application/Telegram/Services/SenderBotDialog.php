<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sender bot's private chats: Start, the language, the request to the
 * admin, then (once let in) the questions of a post - the message, how many
 * times, the pause - and the posts of the person. Also the admin's
 * decision, from the panel or from the bot's own buttons.
 */
final class SenderBotDialog
{
    public const STEP_TEXT = 'text';

    public const STEP_TIMES = 'times';

    public const STEP_INTERVAL = 'interval';

    public const STEP_CONFIRM = 'confirm';

    /** What the bot can copy into a group. */
    private const KINDS = ['text', 'photo', 'video', 'document', 'animation', 'audio', 'voice'];

    /** The admin's language in the bot and in the listener's notice. */
    public const ADMIN_LOCALE = 'ru';

    public function __construct(private readonly SenderBotSettings $settings) {}

    /**
     * A message in a private chat with the bot.
     */
    public function message(BotApi $api, array $message): void
    {
        $from = (array) ($message['from'] ?? []);

        if (($from['is_bot'] ?? false) || ! isset($from['id'])) {
            return;
        }

        $user = $this->remember($from);
        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $this->start($api, $user);

            return;
        }

        match ($user->status) {
            TelegramSenderBotUser::STATUS_NEW => $this->askLanguage($api, $user),
            TelegramSenderBotUser::STATUS_PENDING => $this->say($api, $user, 'still_pending'),
            TelegramSenderBotUser::STATUS_REJECTED => $this->say($api, $user, 'rejected'),
            TelegramSenderBotUser::STATUS_BLOCKED => $this->say($api, $user, 'blocked', keyboard: ['remove_keyboard' => true]),
            default => $this->approved($api, $user, $message, $text),
        };
    }

    /**
     * A button under a message of the bot. Answered here, whatever it was.
     */
    public function callback(BotApi $api, array $query): void
    {
        $data = (string) ($query['data'] ?? '');
        $from = (array) ($query['from'] ?? []);
        $alert = null;

        try {
            if (str_starts_with($data, 'adm:')) {
                $alert = $this->adminButton($api, $query, $data);

                return;
            }

            if (! isset($from['id']) || ($from['is_bot'] ?? false)) {
                return;
            }

            $user = $this->remember($from);
            $source = (array) ($query['message'] ?? []);

            if (preg_match('/^lang:(ru|uz)$/', $data, $m) === 1) {
                $this->chooseLanguage($api, $user, $m[1]);
            } elseif ($user->status !== TelegramSenderBotUser::STATUS_APPROVED) {
                $this->start($api, $user);
            } elseif ($data === 'post:go') {
                $this->launch($api, $user, $source);
            } elseif ($data === 'post:cancel') {
                $this->dropButtons($api, $source);
                $this->reset($user);
                $this->say($api, $user, 'cancelled', keyboard: $this->menuKeyboard($user));
            } elseif (preg_match('/^stop:(\d+)$/', $data, $m) === 1) {
                $this->stopOwn($api, $user, (int) $m[1], $source);
            }
        } finally {
            try {
                $api->call('answerCallbackQuery', array_filter([
                    'callback_query_id' => (string) ($query['id'] ?? ''),
                    'text' => $alert,
                    'show_alert' => $alert !== null ? true : null,
                ], static fn ($v) => $v !== null));
            } catch (BotApiError) {
                /* An old button: nothing to answer. */
            }
        }
    }

    /**
     * The admin's word on a person: approve, reject, block (an approved
     * one; their running posts stop) or unblock. The person is told.
     *
     * @return string|null why the person could not be told
     */
    public function decide(TelegramSenderBotUser $user, string $verdict, ?int $by = null): ?string
    {
        $status = match ($verdict) {
            'approve', 'unblock' => TelegramSenderBotUser::STATUS_APPROVED,
            'reject' => TelegramSenderBotUser::STATUS_REJECTED,
            'block' => TelegramSenderBotUser::STATUS_BLOCKED,
        };

        $user->forceFill([
            'status' => $status,
            'decided_at' => now(),
            'decided_by' => $by,
            'step' => null,
            'draft' => null,
        ])->save();

        if ($status === TelegramSenderBotUser::STATUS_BLOCKED) {
            $user->posts()
                ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
                ->update([
                    'status' => TelegramSenderBotPost::STATUS_STOPPED,
                    'stopped_by' => TelegramSenderBotPost::STOPPED_BY_ADMIN,
                    'finished_at' => now(),
                    'next_at' => null,
                ]);
        }

        $api = $this->settings->api();

        if ($api === null) {
            return 'no bot';
        }

        try {
            match ($status) {
                TelegramSenderBotUser::STATUS_APPROVED => $this->say($api, $user, 'approved', keyboard: $this->menuKeyboard($user)),
                TelegramSenderBotUser::STATUS_REJECTED => $this->say($api, $user, 'rejected', keyboard: ['remove_keyboard' => true]),
                TelegramSenderBotUser::STATUS_BLOCKED => $this->say($api, $user, 'blocked', keyboard: ['remove_keyboard' => true]),
            };
        } catch (BotApiError $e) {
            return $e->reason();
        }

        return null;
    }

    /**
     * The admin stopped a post from the panel: the person is told.
     */
    public function stoppedByAdmin(TelegramSenderBotPost $post): void
    {
        $api = $this->settings->api();

        if ($api !== null && $post->user !== null) {
            $this->quietly(fn () => $this->say($api, $post->user, 'stopped_by_admin', ['id' => $post->id]));
        }
    }

    /**
     * Tells the person what came of a post: done, failed, stopped.
     *
     * @param array<string, string|int> $replace
     */
    public function tell(BotApi $api, TelegramSenderBotUser $user, string $key, array $replace = []): void
    {
        $this->quietly(fn () => $this->say($api, $user, $key, $replace));
    }

    /** How long a pause is in words: "30 мин", "2 ч", "1 ч 30 мин". */
    public static function interval(int $minutes, string $locale): string
    {
        if ($minutes <= 0) {
            return __('sender_bot.interval_none', [], $locale);
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return match (true) {
            $h === 0 => __('sender_bot.minutes', ['m' => $m], $locale),
            $m === 0 => __('sender_bot.hours', ['h' => $h], $locale),
            default => __('sender_bot.hours_minutes', ['h' => $h, 'm' => $m], $locale),
        };
    }

    /**
     * The text of the request for the admin (the listener's notice and the
     * bot's own message with the buttons).
     */
    public static function requestText(TelegramSenderBotUser $user, bool $withLink): string
    {
        $locale = self::ADMIN_LOCALE;

        $text = __('sender_bot.admin_request', [
            'name' => $user->name(),
            'username' => $user->username !== null ? '@' . $user->username . "\n" : '',
            'id' => (string) $user->telegram_user_id,
            'language' => $user->language === 'uz' ? "O'zbekcha" : 'Русский',
        ], $locale);

        if ($withLink) {
            $text .= __('sender_bot.admin_request_link', ['link' => route('driver-check.sender-bot')], $locale);
        }

        return $text;
    }

    private function start(BotApi $api, TelegramSenderBotUser $user): void
    {
        match ($user->status) {
            TelegramSenderBotUser::STATUS_NEW => $this->askLanguage($api, $user),
            TelegramSenderBotUser::STATUS_PENDING => $this->say($api, $user, 'still_pending'),
            TelegramSenderBotUser::STATUS_REJECTED => $this->say($api, $user, 'rejected'),
            TelegramSenderBotUser::STATUS_BLOCKED => $this->say($api, $user, 'blocked', keyboard: ['remove_keyboard' => true]),
            default => $this->menu($api, $user),
        };
    }

    private function askLanguage(BotApi $api, TelegramSenderBotUser $user): void
    {
        $api->call('sendMessage', [
            'chat_id' => $user->telegram_user_id,
            'text' => __('sender_bot.choose_language', [], 'ru'),
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => __('sender_bot.lang_ru', [], 'ru'), 'callback_data' => 'lang:ru'],
                ['text' => __('sender_bot.lang_uz', [], 'uz'), 'callback_data' => 'lang:uz'],
            ]]],
        ]);
    }

    private function chooseLanguage(BotApi $api, TelegramSenderBotUser $user, string $language): void
    {
        $user->forceFill(['language' => $language])->save();

        if ($user->status !== TelegramSenderBotUser::STATUS_NEW) {
            $this->say($api, $user, 'language_set');
            $this->start($api, $user);

            return;
        }

        /* The first language chosen: that is the request to the admin. */
        $user->forceFill([
            'status' => TelegramSenderBotUser::STATUS_PENDING,
            'requested_at' => now(),
            'notified_at' => null,
            'notify_error' => null,
        ])->save();

        $this->say($api, $user, 'requested', keyboard: ['remove_keyboard' => true]);
        $this->askAdmin($api, $user);
    }

    /**
     * The request also comes to the admin in the bot itself, with the two
     * buttons - if the admin has pressed Start in it (Telegram refuses
     * otherwise, and the listener's notice is there anyway).
     */
    private function askAdmin(BotApi $api, TelegramSenderBotUser $user): void
    {
        $admin = $this->settings->adminId();

        if ($admin === null || ! $this->settings->notify()['via_bot'] || $admin === $user->telegram_user_id) {
            return;
        }

        $this->quietly(fn () => $api->call('sendMessage', [
            'chat_id' => $admin,
            'text' => self::requestText($user, withLink: true),
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => __('sender_bot.btn_approve', [], self::ADMIN_LOCALE), 'callback_data' => 'adm:ok:' . $user->id],
                ['text' => __('sender_bot.btn_reject', [], self::ADMIN_LOCALE), 'callback_data' => 'adm:no:' . $user->id],
            ]]],
        ]));
    }

    /**
     * @return string|null an alert for the one who pressed
     */
    private function adminButton(BotApi $api, array $query, string $data): ?string
    {
        $locale = self::ADMIN_LOCALE;
        $admin = $this->settings->adminId();

        if ($admin === null || (int) ($query['from']['id'] ?? 0) !== $admin) {
            return __('sender_bot.admin_only', [], $locale);
        }

        if (preg_match('/^adm:(ok|no):(\d+)$/', $data, $m) !== 1) {
            return null;
        }

        $user = TelegramSenderBotUser::query()->find((int) $m[2]);
        $source = (array) ($query['message'] ?? []);

        if ($user === null || $user->status !== TelegramSenderBotUser::STATUS_PENDING) {
            $this->dropButtons($api, $source, __('sender_bot.admin_already', [], $locale) . ($user ? ': ' . $user->status : ''));

            return __('sender_bot.admin_already', [], $locale);
        }

        $this->decide($user, $m[1] === 'ok' ? 'approve' : 'reject');

        $this->dropButtons($api, $source, __('sender_bot.' . ($m[1] === 'ok' ? 'admin_approved' : 'admin_rejected'), [], $locale));

        return null;
    }

    private function approved(BotApi $api, TelegramSenderBotUser $user, array $message, string $text): void
    {
        $is = fn (string $button): bool => $text !== '' && in_array($text, [
            __('sender_bot.' . $button, [], 'ru'),
            __('sender_bot.' . $button, [], 'uz'),
        ], true);

        if ($is('btn_cancel')) {
            $this->reset($user);
            $this->say($api, $user, 'cancelled', keyboard: $this->menuKeyboard($user));

            return;
        }

        if ($is('btn_send')) {
            $this->askText($api, $user);

            return;
        }

        if ($is('btn_posts')) {
            $this->reset($user);
            $this->posts($api, $user);

            return;
        }

        if ($is('btn_groups')) {
            $this->reset($user);
            $this->groups($api, $user);

            return;
        }

        if ($is('btn_language')) {
            $this->reset($user);
            $this->askLanguage($api, $user);

            return;
        }

        match ($user->step) {
            self::STEP_TEXT => $this->takeText($api, $user, $message),
            self::STEP_TIMES => $this->takeTimes($api, $user, $text),
            self::STEP_INTERVAL => $this->takeInterval($api, $user, $text),
            self::STEP_CONFIRM => $this->say($api, $user, 'confirm_buttons'),
            default => $this->menu($api, $user),
        };
    }

    private function askText(BotApi $api, TelegramSenderBotUser $user): void
    {
        if ($this->activeGroups() === 0) {
            $this->reset($user);
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return;
        }

        $user->forceFill(['step' => self::STEP_TEXT, 'draft' => null])->save();
        $this->say($api, $user, 'ask_text', keyboard: $this->cancelKeyboard($user));
    }

    private function takeText(BotApi $api, TelegramSenderBotUser $user, array $message): void
    {
        $kind = null;

        foreach (self::KINDS as $candidate) {
            if (isset($message[$candidate])) {
                $kind = $candidate;
                break;
            }
        }

        if ($kind === null) {
            $this->say($api, $user, 'bad_message', keyboard: $this->cancelKeyboard($user));

            return;
        }

        $draft = [
            'chat_id' => (int) $message['chat']['id'],
            'message_id' => (int) $message['message_id'],
            'kind' => $kind,
            'text' => mb_substr((string) ($message['text'] ?? $message['caption'] ?? ''), 0, 4000),
        ];

        $limits = $this->settings->limits();

        if ($limits['max_times'] === 1) {
            $this->confirm($api, $user, [...$draft, 'times' => 1, 'interval' => 0]);

            return;
        }

        $user->forceFill(['step' => self::STEP_TIMES, 'draft' => $draft])->save();
        $this->say($api, $user, 'ask_times', ['max' => $limits['max_times']], $this->numbersKeyboard(
            $user,
            array_filter([1, 2, 3, 5, 10, 20], fn (int $n) => $n <= $limits['max_times']),
        ));
    }

    private function takeTimes(BotApi $api, TelegramSenderBotUser $user, string $text): void
    {
        $max = $this->settings->limits()['max_times'];
        $times = self::number($text);

        if ($times === null || $times < 1 || $times > $max) {
            $this->say($api, $user, 'bad_times', ['max' => $max]);

            return;
        }

        $draft = [...(array) $user->draft, 'times' => $times];

        if ($times === 1) {
            $this->confirm($api, $user, [...$draft, 'interval' => 0]);

            return;
        }

        $limits = $this->settings->limits();

        $user->forceFill(['step' => self::STEP_INTERVAL, 'draft' => $draft])->save();
        $this->say($api, $user, 'ask_interval', ['min' => $limits['min_interval'], 'max' => $limits['max_interval']], $this->numbersKeyboard(
            $user,
            array_filter([5, 10, 15, 30, 60, 120], fn (int $n) => $n >= $limits['min_interval'] && $n <= $limits['max_interval']),
        ));
    }

    private function takeInterval(BotApi $api, TelegramSenderBotUser $user, string $text): void
    {
        $limits = $this->settings->limits();
        $minutes = self::number($text);

        if ($minutes === null || $minutes < $limits['min_interval'] || $minutes > $limits['max_interval']) {
            $this->say($api, $user, 'bad_interval', ['min' => $limits['min_interval'], 'max' => $limits['max_interval']]);

            return;
        }

        $this->confirm($api, $user, [...(array) $user->draft, 'interval' => $minutes]);
    }

    /**
     * The summary under the person's own message, with Start / Cancel.
     */
    private function confirm(BotApi $api, TelegramSenderBotUser $user, array $draft): void
    {
        $user->forceFill(['step' => self::STEP_CONFIRM, 'draft' => $draft])->save();
        $locale = $user->locale();

        /* The reply keyboard goes first: an inline one cannot be sent with it. */
        $this->say($api, $user, 'menu', keyboard: $this->menuKeyboard($user));

        $api->call('sendMessage', [
            'chat_id' => $user->telegram_user_id,
            'text' => __('sender_bot.confirm', [
                'groups' => $this->activeGroups(),
                'times' => (int) $draft['times'],
                'interval' => (int) $draft['times'] > 1 ? self::interval((int) $draft['interval'], $locale) : __('sender_bot.interval_none', [], $locale),
            ], $locale),
            'reply_parameters' => ['message_id' => (int) $draft['message_id'], 'allow_sending_without_reply' => true],
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => __('sender_bot.btn_start', [], $locale), 'callback_data' => 'post:go'],
                ['text' => __('sender_bot.btn_cancel', [], $locale), 'callback_data' => 'post:cancel'],
            ]]],
        ]);
    }

    private function launch(BotApi $api, TelegramSenderBotUser $user, array $source): void
    {
        $draft = (array) $user->draft;

        if ($user->step !== self::STEP_CONFIRM || ! isset($draft['message_id'], $draft['times'])) {
            $this->dropButtons($api, $source);
            $this->menu($api, $user);

            return;
        }

        if ($this->activeGroups() === 0) {
            $this->dropButtons($api, $source);
            $this->reset($user);
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return;
        }

        TelegramSenderBotPost::query()->create([
            'sender_bot_user_id' => $user->id,
            'source_chat_id' => (int) $draft['chat_id'],
            'source_message_id' => (int) $draft['message_id'],
            'kind' => (string) ($draft['kind'] ?? 'text'),
            'text' => (string) ($draft['text'] ?? ''),
            'times' => (int) $draft['times'],
            'interval_minutes' => (int) $draft['times'] > 1 ? (int) ($draft['interval'] ?? 0) : 0,
            'sent_times' => 0,
            'next_at' => now(),
            'status' => TelegramSenderBotPost::STATUS_ACTIVE,
        ]);

        $this->reset($user);
        $this->dropButtons($api, $source);
        $this->say($api, $user, 'started', keyboard: $this->menuKeyboard($user));
    }

    private function posts(BotApi $api, TelegramSenderBotUser $user): void
    {
        $locale = $user->locale();
        $posts = $user->posts()
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->orderBy('id')
            ->limit(20)
            ->get();

        if ($posts->isEmpty()) {
            $this->say($api, $user, 'posts_none', keyboard: $this->menuKeyboard($user));

            return;
        }

        $lines = $posts->map(fn (TelegramSenderBotPost $post) => __('sender_bot.post_line', [
            'id' => $post->id,
            'text' => self::preview($post, $locale),
            'sent' => $post->sent_times,
            'times' => $post->times,
            'next' => $post->next_at === null || $post->next_at->isPast()
                ? __('sender_bot.next_now', [], $locale)
                : $post->next_at->timezone(config('app.timezone'))->format($post->next_at->isToday() ? 'H:i' : 'd.m H:i'),
        ], $locale));

        $api->call('sendMessage', [
            'chat_id' => $user->telegram_user_id,
            'text' => __('sender_bot.posts_title', [], $locale) . "\n\n" . $lines->implode("\n\n"),
            'reply_markup' => ['inline_keyboard' => $posts
                ->map(fn (TelegramSenderBotPost $post) => [[
                    'text' => __('sender_bot.btn_stop', ['id' => $post->id], $locale),
                    'callback_data' => 'stop:' . $post->id,
                ]])
                ->values()
                ->all()],
        ]);
    }

    private function stopOwn(BotApi $api, TelegramSenderBotUser $user, int $postId, array $source): void
    {
        $stopped = $user->posts()
            ->whereKey($postId)
            ->where('status', TelegramSenderBotPost::STATUS_ACTIVE)
            ->update([
                'status' => TelegramSenderBotPost::STATUS_STOPPED,
                'stopped_by' => TelegramSenderBotPost::STOPPED_BY_USER,
                'finished_at' => now(),
                'next_at' => null,
            ]);

        if ($stopped > 0) {
            $this->say($api, $user, 'stopped', ['id' => $postId]);
        }

        /* The list again, without the stopped one. */
        $this->quietly(fn () => $api->call('deleteMessage', [
            'chat_id' => $user->telegram_user_id,
            'message_id' => (int) ($source['message_id'] ?? 0),
        ]));
        $this->posts($api, $user);
    }

    private function groups(BotApi $api, TelegramSenderBotUser $user): void
    {
        $list = TelegramSenderBotGroup::query()
            ->usable()
            ->orderBy('title')
            ->get()
            ->map(fn (TelegramSenderBotGroup $group) => '• ' . $group->label());

        if ($list->isEmpty()) {
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return;
        }

        $this->say($api, $user, 'groups_list', ['list' => $list->implode("\n")], $this->menuKeyboard($user));
    }

    private function menu(BotApi $api, TelegramSenderBotUser $user): void
    {
        $this->reset($user);
        $this->say($api, $user, 'menu', keyboard: $this->menuKeyboard($user));
    }

    private function reset(TelegramSenderBotUser $user): void
    {
        if ($user->step !== null || $user->draft !== null) {
            $user->forceFill(['step' => null, 'draft' => null])->save();
        }
    }

    private function remember(array $from): TelegramSenderBotUser
    {
        $user = TelegramSenderBotUser::query()->firstOrNew(['telegram_user_id' => (int) $from['id']]);

        $user->fill([
            'username' => isset($from['username']) ? (string) $from['username'] : null,
            'first_name' => isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 255) : null,
            'last_name' => isset($from['last_name']) ? mb_substr((string) $from['last_name'], 0, 255) : null,
            'last_seen_at' => now(),
        ]);

        if (! $user->exists) {
            $user->status = TelegramSenderBotUser::STATUS_NEW;
        }

        $user->save();

        return $user;
    }

    /**
     * @param array<string, string|int> $replace
     */
    private function say(BotApi $api, TelegramSenderBotUser $user, string $key, array $replace = [], ?array $keyboard = null): void
    {
        $api->call('sendMessage', array_filter([
            'chat_id' => $user->telegram_user_id,
            'text' => __('sender_bot.' . $key, $replace, $user->locale()),
            'reply_markup' => $keyboard,
        ], static fn ($v) => $v !== null));
    }

    private function menuKeyboard(TelegramSenderBotUser $user): array
    {
        $l = $user->locale();

        return [
            'keyboard' => [
                [['text' => __('sender_bot.btn_send', [], $l)]],
                [['text' => __('sender_bot.btn_posts', [], $l)], ['text' => __('sender_bot.btn_groups', [], $l)]],
                [['text' => __('sender_bot.btn_language', [], $l)]],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function cancelKeyboard(TelegramSenderBotUser $user): array
    {
        return [
            'keyboard' => [[['text' => __('sender_bot.btn_cancel', [], $user->locale())]]],
            'resize_keyboard' => true,
        ];
    }

    /**
     * @param array<int, int> $numbers
     */
    private function numbersKeyboard(TelegramSenderBotUser $user, array $numbers): array
    {
        $rows = array_chunk(array_map(fn (int $n) => ['text' => (string) $n], array_values($numbers)), 3);
        $rows[] = [['text' => __('sender_bot.btn_cancel', [], $user->locale())]];

        return ['keyboard' => $rows, 'resize_keyboard' => true];
    }

    /** Takes the buttons off a message of the bot, with a line added to its text if given. */
    private function dropButtons(BotApi $api, array $message, ?string $line = null): void
    {
        if (! isset($message['message_id'], $message['chat']['id'])) {
            return;
        }

        $this->quietly(fn () => $line === null
            ? $api->call('editMessageReplyMarkup', [
                'chat_id' => $message['chat']['id'],
                'message_id' => $message['message_id'],
                'reply_markup' => ['inline_keyboard' => []],
            ])
            : $api->call('editMessageText', [
                'chat_id' => $message['chat']['id'],
                'message_id' => $message['message_id'],
                'text' => trim((string) ($message['text'] ?? '')) . "\n\n" . $line,
                'link_preview_options' => ['is_disabled' => true],
            ]));
    }

    private function activeGroups(): int
    {
        return TelegramSenderBotGroup::query()->usable()->count();
    }

    public static function preview(TelegramSenderBotPost $post, string $locale): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $post->text) ?? '');

        if ($text === '') {
            return __('sender_bot.media', [], $locale);
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '…' : $text;
    }

    private static function number(string $text): ?int
    {
        $text = trim($text);

        return preg_match('/^\d{1,6}$/', $text) === 1 ? (int) $text : null;
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            Log::info('Sender bot: a call that may fail did', ['error' => $e->getMessage()]);
        }
    }
}
