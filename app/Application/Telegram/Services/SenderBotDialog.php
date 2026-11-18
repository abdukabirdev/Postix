<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sender bot's private chats with its people (the operators): Start,
 * the language, the request to the admin, then (once let in) a post - the
 * message, the groups, how many times, the pause, now or later - and the
 * posts themselves: running ones with Pause / Now / Stop, the history with
 * Repeat and Delete from the groups. Also the admin's decision on a
 * person, from the panel or from the bot's own buttons.
 *
 * The admin's own menu in the bot is SenderBotAdminDialog; the drivers who
 * forward a post to reach its author are SenderBotDrivers.
 */
final class SenderBotDialog
{
    public const STEP_TEXT = 'text';

    public const STEP_GROUPS = 'groups';

    public const STEP_TIMES = 'times';

    public const STEP_INTERVAL = 'interval';

    public const STEP_CONFIRM = 'confirm';

    public const STEP_START_AT = 'start_at';

    /** What the bot can copy into a group. */
    private const KINDS = ['text', 'photo', 'video', 'document', 'animation', 'audio', 'voice'];

    /** "Later": the offsets offered, minutes. */
    private const LATER = [30, 60, 120, 180, 360];

    /** The open posts shown as cards at once. */
    private const CARDS = 10;

    /** The admin's language in the bot and in the listener's notice. */
    public const ADMIN_LOCALE = 'ru';

    public function __construct(
        private readonly SenderBotSettings $settings,
        private readonly SenderBotPosts $posts,
        private readonly SenderBotCards $cards,
    ) {}

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

        if ($text === '/help') {
            $this->say($api, $user, 'help', keyboard: $user->status === TelegramSenderBotUser::STATUS_APPROVED ? $this->menuKeyboard($user) : null);

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
                $alert = $this->launch($api, $user, $source);
            } elseif ($data === 'post:later') {
                $this->askStartAt($api, $user, $source);
            } elseif ($data === 'post:cancel') {
                $this->dropButtons($api, $source);
                $this->reset($user);
                $this->say($api, $user, 'cancelled', keyboard: $this->menuKeyboard($user));
            } elseif (preg_match('/^g:(all|next|\d+)$/', $data, $m) === 1) {
                $alert = $this->chooseGroup($api, $user, $m[1], $source);
            } elseif (preg_match('/^h:(\d+)$/', $data, $m) === 1) {
                $this->history($api, $user, (int) $m[1], $source);
            } elseif (preg_match('/^(?:p:([a-z]+)|(stop)):(\d+)$/', $data, $m) === 1) {
                $post = $user->posts()->with('user')->find((int) $m[3]);
                $alert = $post === null
                    ? __('sender_bot.post_gone', [], $user->locale())
                    : $this->postAction($api, $source, $post, $m[1] !== '' ? $m[1] : 'stop', 'p', TelegramSenderBotEvent::ACTOR_USER, $user->locale());
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
     * A button of a post's card, pressed by its author ("p") or the admin
     * ("a"): the post changes and the card is drawn again in place.
     *
     * @return string|null an alert for the one who pressed
     */
    public function postAction(BotApi $api, array $source, TelegramSenderBotPost $post, string $act, string $scope, string $actor, string $locale): ?string
    {
        $tr = fn (string $key, array $replace = []) => __('sender_bot.' . $key, $replace, $locale);
        $chatId = (int) ($source['chat']['id'] ?? 0);
        $alert = null;

        switch ($act) {
            case 'card':
                $this->quietly(fn () => $api->call('sendMessage', ['chat_id' => $chatId, ...$this->cards->post($post, $locale, $scope)]));

                return null;

            case 'show':
                $this->show($api, $chatId, $post);

                return null;

            case 'again':
                $owner = $post->user;

                if ($owner === null || $scope !== 'p') {
                    return null;
                }

                if (($refusal = $this->posts->refusal($owner)) !== null) {
                    return $tr($refusal[0], $refusal[1]);
                }

                if ($this->posts->groups(null)->isEmpty()) {
                    return $tr('no_groups');
                }

                $this->confirm($api, $owner, $this->posts->repeatDraft($post));

                return null;

            case 'pause':
                $alert = $this->posts->pause($post, $actor) ? $tr('paused_alert') : $tr('not_open');
                $this->tellAuthor($api, $post, $scope, 'paused_by_admin');
                break;

            case 'resume':
                $alert = $this->posts->resume($post, $actor) ? $tr('resumed_alert') : $tr('not_open');
                $this->tellAuthor($api, $post, $scope, 'resumed_by_admin');
                break;

            case 'now':
                $quiet = $this->settings->quietUntil();
                $alert = ! $this->posts->sendNow($post, $actor)
                    ? $tr('not_open')
                    : ($quiet !== null ? $tr('now_quiet', ['time' => $quiet->format('H:i')]) : $tr('now_alert'));
                break;

            case 'stop':
                $alert = $this->posts->stop($post, $actor) ? $tr('stopped', ['id' => $post->id]) : $tr('not_open');
                $this->tellAuthor($api, $post, $scope, 'stopped_by_admin');
                break;

            case 'del':
                $count = $post->deletableCopies()->count();

                if ($count === 0) {
                    return $tr('delete_nothing');
                }

                $this->quietly(fn () => $api->call('editMessageReplyMarkup', [
                    'chat_id' => $chatId,
                    'message_id' => (int) ($source['message_id'] ?? 0),
                    'reply_markup' => ['inline_keyboard' => [[
                        ['text' => $tr('btn_delete_yes', ['count' => $count]), 'callback_data' => "{$scope}:delok:{$post->id}"],
                        ['text' => $tr('btn_delete_no'), 'callback_data' => "{$scope}:delno:{$post->id}"],
                    ]]],
                ]));

                return null;

            case 'delok':
                $count = $this->posts->askDelete($post, $actor);
                $alert = $count > 0 ? $tr('delete_queued', ['count' => $count]) : $tr('delete_nothing');
                break;

            case 'delno':
                break;

            default:
                return null;
        }

        $this->redraw($api, $source, $post, $scope, $locale);

        return $alert;
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

        SenderBotHistory::add(match ($verdict) {
            'approve' => 'approved',
            'unblock' => 'unblocked',
            'reject' => 'rejected',
            'block' => 'blocked',
        }, TelegramSenderBotEvent::ACTOR_ADMIN, $user);

        if ($status === TelegramSenderBotUser::STATUS_BLOCKED) {
            foreach ($user->posts()->whereIn('status', TelegramSenderBotPost::OPEN)->get() as $post) {
                $this->posts->stop($post, TelegramSenderBotEvent::ACTOR_ADMIN);
            }
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
     * The admin did something to a post from the panel: its author is told.
     */
    public function toldByAdmin(TelegramSenderBotPost $post, string $key): void
    {
        $api = $this->settings->api();

        if ($api !== null && $post->user !== null && ! $this->settings->isAdmin($post->user->telegram_user_id)) {
            $this->quietly(fn () => $this->say($api, $post->user, $key, ['id' => $post->id]));
        }
    }

    /** The admin stopped a post from the panel: the person is told. */
    public function stoppedByAdmin(TelegramSenderBotPost $post): void
    {
        $this->toldByAdmin($post, 'stopped_by_admin');
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

    public function menuKeyboard(TelegramSenderBotUser $user): array
    {
        $l = $user->locale();
        $last = [['text' => __('sender_bot.btn_language', [], $l)]];

        if ($this->settings->isAdmin($user->telegram_user_id)) {
            $last[] = ['text' => __('sender_bot.btn_admin', [], $l)];
        }

        return [
            'keyboard' => [
                [['text' => __('sender_bot.btn_send', [], $l)]],
                [['text' => __('sender_bot.btn_posts', [], $l)], ['text' => __('sender_bot.btn_history', [], $l)]],
                [['text' => __('sender_bot.btn_groups', [], $l)], ['text' => __('sender_bot.btn_help', [], $l)]],
                $last,
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function start(BotApi $api, TelegramSenderBotUser $user): void
    {
        $this->letAdminIn($user);

        match ($user->status) {
            TelegramSenderBotUser::STATUS_NEW => $this->askLanguage($api, $user),
            TelegramSenderBotUser::STATUS_PENDING => $this->say($api, $user, 'still_pending'),
            TelegramSenderBotUser::STATUS_REJECTED => $this->say($api, $user, 'rejected'),
            TelegramSenderBotUser::STATUS_BLOCKED => $this->say($api, $user, 'blocked', keyboard: ['remove_keyboard' => true]),
            default => $this->menu($api, $user),
        };
    }

    /** The admin of the bot does not ask themselves: let in once a language is chosen. */
    private function letAdminIn(TelegramSenderBotUser $user): void
    {
        if ($user->language !== null
            && $user->status !== TelegramSenderBotUser::STATUS_APPROVED
            && $this->settings->isAdmin($user->telegram_user_id)) {
            $user->forceFill(['status' => TelegramSenderBotUser::STATUS_APPROVED, 'decided_at' => now(), 'requested_at' => $user->requested_at ?? now()])->save();
        }
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

        if ($this->settings->isAdmin($user->telegram_user_id)) {
            $this->letAdminIn($user);
            $this->say($api, $user, 'approved', keyboard: $this->menuKeyboard($user));

            return;
        }

        /* The first language chosen: that is the request to the admin. */
        $user->forceFill([
            'status' => TelegramSenderBotUser::STATUS_PENDING,
            'requested_at' => now(),
            'notified_at' => null,
            'notify_error' => null,
        ])->save();

        SenderBotHistory::add('requested', TelegramSenderBotEvent::ACTOR_USER, $user, data: ['language' => $language]);

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

        $buttons = [
            'btn_cancel' => function () use ($api, $user) {
                $this->reset($user);
                $this->say($api, $user, 'cancelled', keyboard: $this->menuKeyboard($user));
            },
            'btn_send' => fn () => $this->askText($api, $user),
            'btn_posts' => fn () => $this->openPosts($api, $user),
            'btn_history' => fn () => $this->history($api, $user, 1),
            'btn_groups' => fn () => $this->groups($api, $user),
            'btn_help' => fn () => $this->say($api, $user, 'help', keyboard: $this->menuKeyboard($user)),
            'btn_language' => fn () => $this->askLanguage($api, $user),
        ];

        foreach ($buttons as $button => $run) {
            if ($is($button)) {
                if ($button !== 'btn_send') {
                    $this->reset($user);
                }

                $run();

                return;
            }
        }

        match ($user->step) {
            self::STEP_TEXT => $this->takeText($api, $user, $message),
            self::STEP_GROUPS => $this->say($api, $user, 'groups_buttons'),
            self::STEP_TIMES => $this->takeTimes($api, $user, $text),
            self::STEP_INTERVAL => $this->takeInterval($api, $user, $text),
            self::STEP_START_AT => $this->takeStartAt($api, $user, $text),
            self::STEP_CONFIRM => $this->say($api, $user, 'confirm_buttons'),
            /* A message with no button first (not a word or two): taken as the message to post. */
            default => self::kind($message) !== null
                && ($text === '' || mb_strlen($text) >= 12)
                && $this->posts->groups(null)->isNotEmpty()
                && $this->posts->refusal($user) === null
                ? $this->takeText($api, $user, $message)
                : $this->menu($api, $user),
        };
    }

    private function askText(BotApi $api, TelegramSenderBotUser $user): void
    {
        if ($this->posts->groups(null)->isEmpty()) {
            $this->reset($user);
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return;
        }

        if (($refusal = $this->posts->refusal($user)) !== null) {
            $this->reset($user);
            $this->say($api, $user, $refusal[0], $refusal[1], $this->menuKeyboard($user));

            return;
        }

        $user->forceFill(['step' => self::STEP_TEXT, 'draft' => null])->save();
        $this->say($api, $user, 'ask_text', keyboard: $this->cancelKeyboard($user));
    }

    private function takeText(BotApi $api, TelegramSenderBotUser $user, array $message): void
    {
        $kind = self::kind($message);

        if ($kind === null) {
            $this->say($api, $user, 'bad_message', keyboard: $this->cancelKeyboard($user));

            return;
        }

        $draft = [
            'chat_id' => (int) $message['chat']['id'],
            'message_id' => (int) $message['message_id'],
            'kind' => $kind,
            'text' => mb_substr((string) ($message['text'] ?? $message['caption'] ?? ''), 0, 4000),
            'payload' => self::payload($message, $kind),
            'groups' => null,
        ];

        $groups = $this->posts->groups(null);

        if ($groups->count() > 1) {
            $user->forceFill(['step' => self::STEP_GROUPS, 'draft' => $draft])->save();

            $api->call('sendMessage', [
                'chat_id' => $user->telegram_user_id,
                'text' => __('sender_bot.ask_groups', [], $user->locale()),
                'reply_markup' => $this->cards->groupChoice($groups, null, $user->locale()),
            ]);

            return;
        }

        $this->askTimes($api, $user, $draft);
    }

    /**
     * A switch of the group choice: one group on or off, all of them, or on
     * to the next question.
     *
     * @return string|null an alert
     */
    private function chooseGroup(BotApi $api, TelegramSenderBotUser $user, string $pick, array $source): ?string
    {
        $locale = $user->locale();

        if ($user->step !== self::STEP_GROUPS) {
            $this->dropButtons($api, $source);

            return null;
        }

        $draft = (array) $user->draft;
        $groups = $this->posts->groups(null);
        $all = $groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = isset($draft['groups']) && is_array($draft['groups']) ? array_map('intval', $draft['groups']) : $all;

        if ($pick === 'next') {
            $chosen = array_values(array_intersect($chosen, $all));

            if ($chosen === []) {
                return __('sender_bot.groups_pick_one', [], $locale);
            }

            $draft['groups'] = count($chosen) === count($all) ? null : $chosen;
            $this->dropButtons($api, $source, $this->cards->groupsLine($draft['groups'], $locale), html: true);
            $this->askTimes($api, $user, $draft);

            return null;
        }

        if ($pick === 'all') {
            $chosen = count($chosen) === count($all) ? [] : $all;
        } else {
            $id = (int) $pick;
            $chosen = in_array($id, $chosen, true) ? array_values(array_diff($chosen, [$id])) : [...$chosen, $id];
        }

        $draft['groups'] = $chosen;
        $user->forceFill(['draft' => $draft])->save();

        $this->quietly(fn () => $api->call('editMessageReplyMarkup', [
            'chat_id' => $user->telegram_user_id,
            'message_id' => (int) ($source['message_id'] ?? 0),
            'reply_markup' => $this->cards->groupChoice($groups, count($chosen) === count($all) ? null : $chosen, $locale),
        ]));

        return null;
    }

    private function askTimes(BotApi $api, TelegramSenderBotUser $user, array $draft): void
    {
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

    /** "Later": when to start, by a button (+30 min...) or a time typed (18:30). */
    private function askStartAt(BotApi $api, TelegramSenderBotUser $user, array $source): void
    {
        if ($user->step !== self::STEP_CONFIRM) {
            $this->dropButtons($api, $source);

            return;
        }

        $this->dropButtons($api, $source);
        $user->forceFill(['step' => self::STEP_START_AT])->save();

        $rows = array_chunk(array_map(fn (int $m) => ['text' => $this->laterLabel($m, $user->locale())], self::LATER), 3);
        $rows[] = [['text' => __('sender_bot.btn_cancel', [], $user->locale())]];

        $this->say($api, $user, 'ask_start', keyboard: ['keyboard' => $rows, 'resize_keyboard' => true]);
    }

    private function takeStartAt(BotApi $api, TelegramSenderBotUser $user, string $text): void
    {
        $at = null;

        foreach (self::LATER as $minutes) {
            if ($text === $this->laterLabel($minutes, $user->locale())) {
                $at = now()->addMinutes($minutes);
            }
        }

        if ($at === null && ($clock = SenderBotSettings::clock($text)) !== null) {
            [$h, $m] = array_map('intval', explode(':', $clock));
            $at = now()->timezone(config('app.timezone'))->setTime($h, $m);

            if ($at->lte(now())) {
                $at->addDay();
            }
        }

        if ($at === null) {
            $this->say($api, $user, 'bad_start');

            return;
        }

        $this->confirm($api, $user, [...(array) $user->draft, 'start_at' => $at->toIso8601String()]);
    }

    private function laterLabel(int $minutes, string $locale): string
    {
        return '+' . self::interval($minutes, $locale);
    }

    /**
     * The summary under the person's own message, with Start / Later / Cancel.
     */
    private function confirm(BotApi $api, TelegramSenderBotUser $user, array $draft): void
    {
        $user->forceFill(['step' => self::STEP_CONFIRM, 'draft' => $draft])->save();
        $locale = $user->locale();
        $startAt = isset($draft['start_at']) ? Carbon::parse($draft['start_at']) : null;
        $quiet = $this->settings->quietUntil($startAt);

        /* The reply keyboard goes first: an inline one cannot be sent with it. */
        $this->say($api, $user, 'menu', keyboard: $this->menuKeyboard($user));

        $text = __('sender_bot.confirm', [
            'groups' => $this->cards->groupsLine($draft['groups'] ?? null, $locale),
            'times' => (int) $draft['times'],
            'interval' => (int) $draft['times'] > 1 ? self::interval((int) $draft['interval'], $locale) : __('sender_bot.interval_none', [], $locale),
            'start' => $startAt !== null && $startAt->isFuture() ? SenderBotCards::when($startAt, $locale) : __('sender_bot.start_now', [], $locale),
        ], $locale);

        if ($quiet !== null) {
            $text .= "\n\n" . __('sender_bot.confirm_quiet', ['time' => $quiet->format('H:i')], $locale);
        }

        $api->call('sendMessage', [
            'chat_id' => $user->telegram_user_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_parameters' => ['message_id' => (int) $draft['message_id'], 'allow_sending_without_reply' => true],
            'reply_markup' => ['inline_keyboard' => [
                [
                    ['text' => __('sender_bot.btn_start', [], $locale), 'callback_data' => 'post:go'],
                    ['text' => __('sender_bot.btn_later', [], $locale), 'callback_data' => 'post:later'],
                ],
                [['text' => __('sender_bot.btn_cancel', [], $locale), 'callback_data' => 'post:cancel']],
            ]],
        ]);
    }

    /**
     * @return string|null an alert
     */
    private function launch(BotApi $api, TelegramSenderBotUser $user, array $source): ?string
    {
        $draft = (array) $user->draft;

        if ($user->step !== self::STEP_CONFIRM || ! isset($draft['message_id'], $draft['times'])) {
            $this->dropButtons($api, $source);
            $this->menu($api, $user);

            return null;
        }

        if ($this->posts->groups($draft['groups'] ?? null)->isEmpty()) {
            $this->dropButtons($api, $source);
            $this->reset($user);
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return null;
        }

        if (($refusal = $this->posts->refusal($user)) !== null) {
            $this->dropButtons($api, $source);
            $this->reset($user);
            $this->say($api, $user, $refusal[0], $refusal[1], $this->menuKeyboard($user));

            return null;
        }

        $post = $this->posts->create($user, $draft);
        $post->setRelation('user', $user);

        $this->reset($user);
        $this->dropButtons($api, $source);

        $later = $post->next_at !== null && $post->next_at->isAfter(now()->addMinute());
        $this->say($api, $user, $later ? 'started_later' : 'started', [
            'time' => SenderBotCards::when($post->next_at, $user->locale()),
        ], $this->menuKeyboard($user));
        $this->quietly(fn () => $api->call('sendMessage', ['chat_id' => $user->telegram_user_id, ...$this->cards->post($post, $user->locale())]));

        $this->tellAdminOfPost($api, $post, $user);

        return null;
    }

    /**
     * The admin sees every new post of somebody else: the message itself,
     * and its card with Stop / Delete / Block.
     */
    private function tellAdminOfPost(BotApi $api, TelegramSenderBotPost $post, TelegramSenderBotUser $user): void
    {
        $admin = $this->settings->adminId();

        if ($admin === null || $admin === $user->telegram_user_id || ! $this->settings->notify()['new_posts']) {
            return;
        }

        $this->quietly(function () use ($api, $post, $admin) {
            $this->show($api, $admin, $post);

            $card = $this->cards->post($post, self::ADMIN_LOCALE, 'a');
            $card['text'] = __('sender_bot.admin_new_post', [], self::ADMIN_LOCALE) . "\n" . $card['text'];

            $api->call('sendMessage', ['chat_id' => $admin, ...$card]);
        });
    }

    /** The open posts, each its own card with its buttons. */
    private function openPosts(BotApi $api, TelegramSenderBotUser $user): void
    {
        $locale = $user->locale();
        $posts = $user->posts()
            ->with('user')
            ->whereIn('status', TelegramSenderBotPost::OPEN)
            ->orderBy('id')
            ->limit(self::CARDS)
            ->get();

        if ($posts->isEmpty()) {
            $this->say($api, $user, 'posts_none', keyboard: $this->menuKeyboard($user));

            return;
        }

        $this->say($api, $user, 'posts_title', ['count' => $posts->count()], $this->menuKeyboard($user));

        foreach ($posts as $post) {
            $api->call('sendMessage', ['chat_id' => $user->telegram_user_id, ...$this->cards->post($post, $locale)]);
        }
    }

    /** The finished posts, a page; a page button edits the same message. */
    private function history(BotApi $api, TelegramSenderBotUser $user, int $page, ?array $source = null): void
    {
        $view = $this->cards->history($user, $page, $user->locale());

        if ($view === null) {
            $this->say($api, $user, 'history_none', keyboard: $this->menuKeyboard($user));

            return;
        }

        if ($source !== null && isset($source['message_id'])) {
            $this->quietly(fn () => $api->call('editMessageText', [
                'chat_id' => $user->telegram_user_id,
                'message_id' => (int) $source['message_id'],
                ...$view,
            ]));

            return;
        }

        $api->call('sendMessage', ['chat_id' => $user->telegram_user_id, ...$view]);
    }

    private function groups(BotApi $api, TelegramSenderBotUser $user): void
    {
        $view = $this->cards->groups($user->locale());

        if ($view === null) {
            $this->say($api, $user, 'no_groups', keyboard: $this->menuKeyboard($user));

            return;
        }

        $api->call('sendMessage', ['chat_id' => $user->telegram_user_id, ...$view]);
    }

    /** The post's message itself, to see what went: copied, or sent anew from what was kept. */
    private function show(BotApi $api, int $chatId, TelegramSenderBotPost $post): void
    {
        try {
            $api->call('copyMessage', [
                'chat_id' => $chatId,
                'from_chat_id' => $post->source_chat_id,
                'message_id' => $post->source_message_id,
            ]);
        } catch (BotApiError $e) {
            if (! is_array($post->payload)) {
                throw $e;
            }

            SenderBotDelivery::sendPayload($api, $chatId, $post->payload);
        }
    }

    /** The card of a post drawn again where it was. */
    private function redraw(BotApi $api, array $source, TelegramSenderBotPost $post, string $scope, string $locale): void
    {
        if (! isset($source['message_id'], $source['chat']['id'])) {
            return;
        }

        $post = $post->fresh(['user']) ?? $post;

        $this->quietly(fn () => $api->call('editMessageText', [
            'chat_id' => $source['chat']['id'],
            'message_id' => $source['message_id'],
            ...$this->cards->post($post, $locale, $scope),
        ]));
    }

    /** The admin changed somebody's post from the bot: its author is told. */
    private function tellAuthor(BotApi $api, TelegramSenderBotPost $post, string $scope, string $key): void
    {
        if ($scope !== 'a' || $post->user === null || $this->settings->isAdmin($post->user->telegram_user_id)) {
            return;
        }

        $this->tell($api, $post->user, $key, ['id' => $post->id]);
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
    private function dropButtons(BotApi $api, array $message, ?string $line = null, bool $html = false): void
    {
        if (! isset($message['message_id'], $message['chat']['id'])) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        $this->quietly(fn () => $line === null
            ? $api->call('editMessageReplyMarkup', [
                'chat_id' => $message['chat']['id'],
                'message_id' => $message['message_id'],
                'reply_markup' => ['inline_keyboard' => []],
            ])
            : $api->call('editMessageText', array_filter([
                'chat_id' => $message['chat']['id'],
                'message_id' => $message['message_id'],
                'text' => ($html ? SenderBotCards::e($text) : $text) . "\n\n" . $line,
                'parse_mode' => $html ? 'HTML' : null,
                'link_preview_options' => ['is_disabled' => true],
            ], static fn ($v) => $v !== null)));
    }

    public static function preview(TelegramSenderBotPost $post, string $locale, int $length = 60): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $post->text) ?? '');

        if ($text === '') {
            return __('sender_bot.media', [], $locale);
        }

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    /** What kind of message the bot can post this is, or null. */
    private static function kind(array $message): ?string
    {
        foreach (self::KINDS as $candidate) {
            if (isset($message[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What is kept of the message to send it anew: the text with its
     * formatting, or the file id with its caption.
     */
    private static function payload(array $message, string $kind): array
    {
        if ($kind === 'text') {
            return array_filter([
                'kind' => 'text',
                'text' => (string) $message['text'],
                'entities' => $message['entities'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        $file = $kind === 'photo' ? (array) end($message['photo']) : (array) $message[$kind];

        return array_filter([
            'kind' => $kind,
            'file_id' => (string) ($file['file_id'] ?? ''),
            'caption' => $message['caption'] ?? null,
            'caption_entities' => $message['caption_entities'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
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
