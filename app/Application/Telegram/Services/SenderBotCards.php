<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the sender bot shows in its chats, as sendMessage params (HTML):
 * the card of a post with its buttons, the history, the groups with their
 * links, the links to the copies of a post.
 *
 * The buttons of a card: "p:<act>:<id>" for the owner, "a:<act>:<id>"
 * for the admin. Acts: card, pause, resume, now, stop, show, again, del,
 * delok, delno, block.
 */
final class SenderBotCards
{
    /** Posts on one page of the history. */
    public const HISTORY_PAGE = 6;

    public function __construct(private readonly SenderBotPosts $posts) {}

    /**
     * @return array{text: string, parse_mode: string, reply_markup: array, link_preview_options: array}
     */
    public function post(TelegramSenderBotPost $post, string $locale, string $scope = 'p'): array
    {
        $tr = fn (string $key, array $replace = []) => __('sender_bot.' . $key, $replace, $locale);
        $copies = $post->sends()->whereNull('error')->count();
        $failed = $post->sends()->whereNotNull('error')->count();

        $lines = [$tr('card_title', ['id' => $post->id, 'status' => $tr('status_' . $post->status)])];

        if ($scope === 'a' && $post->user !== null) {
            $lines[] = $tr('card_author', ['name' => self::e($post->user->name()), 'username' => $post->user->username ? ' @' . self::e($post->user->username) : '']);
        }

        $lines[] = '«' . self::e(SenderBotDialog::preview($post, $locale, 200)) . '»';
        $lines[] = '';
        $lines[] = $tr('card_progress', [
            'sent' => $post->sent_times,
            'times' => $post->times,
            'interval' => $post->times > 1 ? SenderBotDialog::interval($post->interval_minutes, $locale) : $tr('interval_none'),
        ]);
        $lines[] = $this->groupsLine($post->chosenGroups(), $locale);

        if ($post->status === TelegramSenderBotPost::STATUS_ACTIVE && $post->next_at !== null) {
            $lines[] = $tr('card_next', ['next' => self::when($post->next_at, $locale)]);
        }

        if ($copies + $failed > 0) {
            $lines[] = $failed > 0 ? $tr('card_copies_failed', ['ok' => $copies, 'failed' => $failed]) : $tr('card_copies', ['ok' => $copies]);
        }

        if ($post->copies_deleted_at !== null) {
            $lines[] = $tr('card_deleted', ['time' => self::when($post->copies_deleted_at, $locale)]);
        } elseif ($post->delete_asked_at !== null) {
            $lines[] = $tr('card_deleting');
        }

        if (! $post->isOpen() && $post->finished_at !== null) {
            $lines[] = $tr('card_finished', ['time' => self::when($post->finished_at, $locale)]);
        }

        if ($post->status === TelegramSenderBotPost::STATUS_FAILED && $post->last_error) {
            $lines[] = '⚠️ ' . self::e(mb_substr($post->last_error, 0, 200));
        }

        return [
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => $this->postButtons($post, $locale, $scope)],
        ];
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function postButtons(TelegramSenderBotPost $post, string $locale, string $scope = 'p'): array
    {
        $b = fn (string $key, string $act) => ['text' => __('sender_bot.' . $key, [], $locale), 'callback_data' => "{$scope}:{$act}:{$post->id}"];
        $rows = [];

        if ($post->status === TelegramSenderBotPost::STATUS_ACTIVE) {
            $rows[] = [$b('btn_pause', 'pause'), $b('btn_now', 'now')];
            $rows[] = [$b('btn_stop_short', 'stop'), $b('btn_show', 'show')];
        } elseif ($post->status === TelegramSenderBotPost::STATUS_PAUSED) {
            $rows[] = [$b('btn_resume', 'resume'), $b('btn_now', 'now')];
            $rows[] = [$b('btn_stop_short', 'stop'), $b('btn_show', 'show')];
        } else {
            $rows[] = $scope === 'p'
                ? [$b('btn_again', 'again'), $b('btn_show', 'show')]
                : [$b('btn_show', 'show')];
        }

        if ($post->delete_asked_at === null && $post->deletableCopies()->exists()) {
            $rows[] = [$b('btn_delete', 'del')];
        }

        if ($scope === 'a' && $post->user !== null && $post->user->status === TelegramSenderBotUser::STATUS_APPROVED) {
            $rows[] = [['text' => __('sender_bot.btn_block_author', [], $locale), 'callback_data' => 'a:block:' . $post->id]];
        }

        return $rows;
    }

    /**
     * The person's finished posts, a page of them, each a button to its card.
     *
     * @return array{text: string, parse_mode: string, reply_markup: array}|null null: none yet
     */
    public function history(TelegramSenderBotUser $user, int $page, string $locale): ?array
    {
        $tr = fn (string $key, array $replace = []) => __('sender_bot.' . $key, $replace, $locale);
        $query = $user->posts()->whereNotIn('status', TelegramSenderBotPost::OPEN);
        $total = (clone $query)->count();

        if ($total === 0) {
            return null;
        }

        $pages = (int) ceil($total / self::HISTORY_PAGE);
        $page = max(1, min($pages, $page));

        $posts = $query->orderByDesc('id')
            ->skip(($page - 1) * self::HISTORY_PAGE)
            ->take(self::HISTORY_PAGE)
            ->get();

        $copies = TelegramSenderBotSend::query()
            ->whereIn('sender_bot_post_id', $user->posts()->select('id'))
            ->whereNull('error')
            ->count();

        $lines = [
            $tr('history_title', ['page' => $page, 'pages' => $pages]),
            $tr('history_totals', ['posts' => $user->posts()->count(), 'copies' => $copies]),
        ];

        foreach ($posts as $post) {
            $lines[] = '';
            $lines[] = $tr('history_line', [
                'id' => $post->id,
                'status' => $tr('status_' . $post->status),
                'time' => self::when($post->created_at, $locale),
            ]);
            $lines[] = '«' . self::e(SenderBotDialog::preview($post, $locale, 80)) . '» · 🔁 ' . $post->sent_times . '/' . $post->times;
        }

        $rows = $posts->map(fn (TelegramSenderBotPost $post) => [[
            'text' => '📌 #' . $post->id . ' · ' . SenderBotDialog::preview($post, $locale, 28),
            'callback_data' => 'p:card:' . $post->id,
        ]])->values()->all();

        $nav = [];

        if ($page > 1) {
            $nav[] = ['text' => '⬅️ ' . ($page - 1), 'callback_data' => 'h:' . ($page - 1)];
        }

        if ($page < $pages) {
            $nav[] = ['text' => ($page + 1) . ' ➡️', 'callback_data' => 'h:' . ($page + 1)];
        }

        if ($nav !== []) {
            $rows[] = $nav;
        }

        return [
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $rows],
        ];
    }

    /**
     * The groups the posts go to, each a link to open it, and a button for
     * each that has one.
     *
     * @return array{text: string, parse_mode: string, link_preview_options: array, reply_markup?: array}|null null: no group
     */
    public function groups(string $locale): ?array
    {
        $groups = $this->posts->groups(null)->sortBy(fn (TelegramSenderBotGroup $g) => mb_strtolower($g->label()))->values();

        if ($groups->isEmpty()) {
            return null;
        }

        $list = $groups->map(fn (TelegramSenderBotGroup $g) => $g->link() !== null
            ? '• <a href="' . self::e($g->link()) . '">' . self::e($g->label()) . '</a>'
            : '• ' . self::e($g->label()));

        $buttons = $groups
            ->filter(fn (TelegramSenderBotGroup $g) => $g->link() !== null)
            ->map(fn (TelegramSenderBotGroup $g) => [['text' => '↗️ ' . $g->label(), 'url' => $g->link()]])
            ->values()
            ->all();

        $message = [
            'text' => __('sender_bot.groups_list', ['list' => $list->implode("\n")], $locale)
                . ($groups->count() > count($buttons) ? "\n\n" . __('sender_bot.groups_no_link', [], $locale) : ''),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($buttons !== []) {
            $message['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        return $message;
    }

    /** "все группы (3)" / "2 из 3: A, B". */
    public function groupsLine(?array $ids, string $locale): string
    {
        $all = $this->posts->groups(null);

        if ($ids === null) {
            return __('sender_bot.card_groups_all', ['count' => $all->count()], $locale);
        }

        $chosen = $all->whereIn('id', $ids);
        $names = $chosen->map(fn (TelegramSenderBotGroup $g) => self::e($g->label()))->implode(', ');

        return __('sender_bot.card_groups_some', ['count' => $chosen->count(), 'all' => $all->count(), 'names' => $names ?: '—'], $locale);
    }

    /**
     * The copies of the last round of a post, each a link to the message
     * in its group (where one can be made).
     */
    public function copyLinks(TelegramSenderBotPost $post): string
    {
        $round = (int) $post->sends()->max('round');

        if ($round === 0) {
            return '';
        }

        return $post->sends()
            ->with('group')
            ->where('round', $round)
            ->orderBy('id')
            ->get()
            ->map(function (TelegramSenderBotSend $send) {
                $label = self::e($send->group?->label() ?? '—');

                if ($send->error !== null) {
                    return '• ' . $label . ' — ⚠️ ' . self::e(mb_substr($send->error, 0, 80));
                }

                $link = $send->group?->messageLink($send->message_id);

                return $link !== null ? '• <a href="' . self::e($link) . '">' . $label . '</a>' : '• ' . $label;
            })
            ->implode("\n");
    }

    /**
     * The group choice of the draft: each group a switch, then All / Next.
     *
     * @param array<int, int>|null $chosen null: all
     */
    public function groupChoice(Collection $groups, ?array $chosen, string $locale): array
    {
        $rows = $groups->map(fn (TelegramSenderBotGroup $g) => [[
            'text' => ($chosen === null || in_array($g->id, $chosen, true) ? '✅ ' : '▫️ ') . $g->label(),
            'callback_data' => 'g:' . $g->id,
        ]])->values()->all();

        $rows[] = [
            ['text' => __('sender_bot.btn_all_groups', [], $locale), 'callback_data' => 'g:all'],
            ['text' => __('sender_bot.btn_next', [], $locale), 'callback_data' => 'g:next'],
        ];

        return ['inline_keyboard' => $rows];
    }

    public static function when(?Carbon $at, string $locale): string
    {
        if ($at === null) {
            return '—';
        }

        $local = $at->copy()->timezone(config('app.timezone'));

        if ($local->isToday()) {
            return __('sender_bot.today_at', ['time' => $local->format('H:i')], $locale);
        }

        if ($local->isTomorrow()) {
            return __('sender_bot.tomorrow_at', ['time' => $local->format('H:i')], $locale);
        }

        return $local->format('d.m H:i');
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
