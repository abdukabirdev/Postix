<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotLead;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The admin's own menu in the bot ("🛠 Админ"): the requests waiting with
 * Approve / Reject, everybody's running posts with Pause / Now / Stop /
 * Delete / Block the author, and the numbers of the last days. In Russian.
 *
 * Buttons: "am:<menu|req|posts|stats>", and the post cards' "a:<act>:<id>".
 */
final class SenderBotAdminDialog
{
    private const LOCALE = SenderBotDialog::ADMIN_LOCALE;

    /** Cards sent at once. */
    private const CARDS = 15;

    public function __construct(
        private readonly SenderBotSettings $settings,
        private readonly SenderBotDialog $dialog,
        private readonly SenderBotCards $cards,
    ) {}

    /** The admin pressed "🛠 Админ" (or wrote /admin). */
    public function wants(array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));

        return ($text === '/admin' || in_array($text, [__('sender_bot.btn_admin', [], 'ru'), __('sender_bot.btn_admin', [], 'uz')], true))
            && $this->settings->isAdmin($message['from']['id'] ?? null);
    }

    public function message(BotApi $api, array $message): void
    {
        $api->call('sendMessage', ['chat_id' => (int) $message['chat']['id'], ...$this->menu()]);
    }

    public function callback(BotApi $api, array $query): void
    {
        $data = (string) ($query['data'] ?? '');
        $source = (array) ($query['message'] ?? []);
        $chatId = (int) ($source['chat']['id'] ?? $query['from']['id'] ?? 0);
        $alert = null;

        try {
            if (! $this->settings->isAdmin($query['from']['id'] ?? null)) {
                $alert = __('sender_bot.admin_only', [], self::LOCALE);

                return;
            }

            if (preg_match('/^am:(menu|req|posts|stats)$/', $data, $m) === 1) {
                match ($m[1]) {
                    'menu' => $this->edit($api, $source, $this->menu()),
                    'req' => $this->requests($api, $chatId),
                    'posts' => $this->posts($api, $chatId),
                    'stats' => $this->edit($api, $source, $this->stats()),
                };

                return;
            }

            if (preg_match('/^a:([a-z]+):(\d+)$/', $data, $m) !== 1) {
                return;
            }

            $post = TelegramSenderBotPost::query()->with('user')->find((int) $m[2]);

            if ($post === null) {
                $alert = __('sender_bot.post_gone', [], self::LOCALE);

                return;
            }

            if ($m[1] === 'block') {
                $alert = $this->block($api, $source, $post);

                return;
            }

            $alert = $this->dialog->postAction($api, $source, $post, $m[1], 'a', TelegramSenderBotEvent::ACTOR_ADMIN, self::LOCALE);
        } finally {
            try {
                $api->call('answerCallbackQuery', array_filter([
                    'callback_query_id' => (string) ($query['id'] ?? ''),
                    'text' => $alert,
                    'show_alert' => $alert !== null ? true : null,
                ], static fn ($v) => $v !== null));
            } catch (BotApiError) {
                /* An old button. */
            }
        }
    }

    /**
     * @return array{text: string, parse_mode: string, reply_markup: array}
     */
    public function menu(): array
    {
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $posts = TelegramSenderBotPost::query()->select('status', DB::raw('COUNT(*) as cnt'))->groupBy('status')->pluck('cnt', 'status');
        $pending = TelegramSenderBotUser::query()->where('status', TelegramSenderBotUser::STATUS_PENDING)->count();
        $active = (int) ($posts[TelegramSenderBotPost::STATUS_ACTIVE] ?? 0);
        $paused = (int) ($posts[TelegramSenderBotPost::STATUS_PAUSED] ?? 0);

        $text = __('sender_bot.admin_menu', [
            'pending' => $pending,
            'active' => $active,
            'paused' => $paused,
            'posts_today' => TelegramSenderBotPost::query()->where('created_at', '>=', $today)->count(),
            'copies_today' => TelegramSenderBotSend::query()->where('created_at', '>=', $today)->whereNull('error')->count(),
            'errors_today' => TelegramSenderBotSend::query()->where('created_at', '>=', $today)->whereNotNull('error')->count(),
            'leads_today' => TelegramSenderBotLead::query()->where('created_at', '>=', $today)->where('status', '!=', TelegramSenderBotLead::STATUS_NOT_FOUND)->count(),
            'approved' => TelegramSenderBotUser::query()->where('status', TelegramSenderBotUser::STATUS_APPROVED)->count(),
        ], self::LOCALE);

        $rows = [
            [
                ['text' => __('sender_bot.btn_adm_requests', ['count' => $pending], self::LOCALE), 'callback_data' => 'am:req'],
                ['text' => __('sender_bot.btn_adm_posts', ['count' => $active + $paused], self::LOCALE), 'callback_data' => 'am:posts'],
            ],
            [['text' => __('sender_bot.btn_adm_stats', [], self::LOCALE), 'callback_data' => 'am:stats']],
        ];

        $panel = route('driver-check.sender-bot');

        if (str_starts_with($panel, 'https://')) {
            $rows[] = [['text' => __('sender_bot.btn_adm_panel', [], self::LOCALE), 'url' => $panel]];
        }

        return ['text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $rows]];
    }

    /**
     * The last 7 days, a line a day, and who posted the most.
     *
     * @return array{text: string, parse_mode: string, reply_markup: array}
     */
    public function stats(): array
    {
        $from = now()->timezone(config('app.timezone'))->startOfDay()->subDays(6);
        $lines = [__('sender_bot.admin_stats_title', [], self::LOCALE), ''];

        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            $end = $day->copy()->addDay();

            $lines[] = __('sender_bot.admin_stats_day', [
                'day' => $day->format('d.m'),
                'posts' => TelegramSenderBotPost::query()->whereBetween('created_at', [$day, $end])->count(),
                'copies' => TelegramSenderBotSend::query()->whereBetween('created_at', [$day, $end])->whereNull('error')->count(),
                'leads' => TelegramSenderBotLead::query()->whereBetween('created_at', [$day, $end])->where('status', '!=', TelegramSenderBotLead::STATUS_NOT_FOUND)->count(),
            ], self::LOCALE);
        }

        $top = TelegramSenderBotPost::query()
            ->with('user')
            ->select('sender_bot_user_id', DB::raw('COUNT(*) as cnt'))
            ->where('created_at', '>=', $from)
            ->groupBy('sender_bot_user_id')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get();

        if ($top->isNotEmpty()) {
            $lines[] = '';
            $lines[] = __('sender_bot.admin_stats_top', [], self::LOCALE);

            foreach ($top as $i => $row) {
                $lines[] = ($i + 1) . '. ' . SenderBotCards::e($row->user?->name() ?? '—') . ' — ' . $row->cnt;
            }
        }

        return [
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => __('sender_bot.btn_back', [], self::LOCALE), 'callback_data' => 'am:menu']]]],
        ];
    }

    /** The requests waiting, each with Approve / Reject. */
    private function requests(BotApi $api, int $chatId): void
    {
        $waiting = TelegramSenderBotUser::query()
            ->where('status', TelegramSenderBotUser::STATUS_PENDING)
            ->orderBy('requested_at')
            ->limit(self::CARDS)
            ->get();

        if ($waiting->isEmpty()) {
            $api->call('sendMessage', ['chat_id' => $chatId, 'text' => __('sender_bot.admin_no_requests', [], self::LOCALE)]);

            return;
        }

        foreach ($waiting as $user) {
            $api->call('sendMessage', [
                'chat_id' => $chatId,
                'text' => SenderBotDialog::requestText($user, withLink: false),
                'reply_markup' => ['inline_keyboard' => [[
                    ['text' => __('sender_bot.btn_approve', [], self::LOCALE), 'callback_data' => 'adm:ok:' . $user->id],
                    ['text' => __('sender_bot.btn_reject', [], self::LOCALE), 'callback_data' => 'adm:no:' . $user->id],
                ]]],
            ]);
        }
    }

    /** Everybody's running and paused posts, as admin cards. */
    private function posts(BotApi $api, int $chatId): void
    {
        $posts = TelegramSenderBotPost::query()
            ->with('user')
            ->whereIn('status', TelegramSenderBotPost::OPEN)
            ->orderBy('id')
            ->limit(self::CARDS)
            ->get();

        if ($posts->isEmpty()) {
            $api->call('sendMessage', ['chat_id' => $chatId, 'text' => __('sender_bot.admin_no_posts', [], self::LOCALE)]);

            return;
        }

        foreach ($posts as $post) {
            $api->call('sendMessage', ['chat_id' => $chatId, ...$this->cards->post($post, self::LOCALE, 'a')]);
        }
    }

    /** The author of a post is blocked: their posts stop, they are told. */
    private function block(BotApi $api, array $source, TelegramSenderBotPost $post): string
    {
        $author = $post->user;

        if ($author === null || $author->status !== TelegramSenderBotUser::STATUS_APPROVED || $this->settings->isAdmin($author->telegram_user_id)) {
            return __('sender_bot.admin_already', [], self::LOCALE);
        }

        $this->dialog->decide($author, 'block');
        $post = $post->fresh(['user']) ?? $post;

        $this->edit($api, $source, $this->cards->post($post, self::LOCALE, 'a'));

        return __('sender_bot.admin_blocked', ['name' => $author->name()], self::LOCALE);
    }

    private function edit(BotApi $api, array $source, array $view): void
    {
        if (! isset($source['message_id'], $source['chat']['id'])) {
            return;
        }

        try {
            $api->call('editMessageText', [
                'chat_id' => $source['chat']['id'],
                'message_id' => $source['message_id'],
                ...$view,
            ]);
        } catch (Throwable $e) {
            Log::info('Sender bot: an admin view was not edited', ['error' => $e->getMessage()]);
        }
    }
}
