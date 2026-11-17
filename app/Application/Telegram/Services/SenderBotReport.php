<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Console\Commands\Telegram\TelegramSenderBotCommand;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use App\Telegram\TelegramProcessLock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The sender bot as the developer needs to see it on prod: is it connected
 * and on, is the posting command alive (its lock, its last run, the
 * scheduler's log), what came by the webhook, the posts and their copies,
 * the groups, the people, and the log lines about it. No token, no phones.
 */
final class SenderBotReport
{
    /** How much of the end of a log file is read, bytes. */
    private const LOG_TAIL = 400_000;

    public function __construct(private readonly SenderBotSettings $settings) {}

    public function build(int $lines): array
    {
        $current = $this->settings->current();
        $state = $this->settings->state();
        $listener = rescue(fn () => $this->settings->notifyListener(), null, false);

        return [
            'generated_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'bot' => [
                'connected' => $current['token'] !== null,
                'username' => $current['bot']['username'] ?? null,
                'enabled' => $current['enabled'],
                'last_activity_at' => $state['polled_at'],
                'error' => $state['error'],
                'error_at' => $state['error_at'],
            ],
            'runner' => [
                'command' => 'telegram:sender-bot',
                'running_now' => TelegramProcessLock::isHeld(TelegramSenderBotCommand::LOCK_NAME),
                'pid' => TelegramProcessLock::holderPid(TelegramSenderBotCommand::LOCK_NAME),
                'last_run_at' => $state['ran_at'],
                'last_run_ago_seconds' => self::ago($state['ran_at']),
                /* The scheduler itself: is its log still written to. */
                'schedule_log_at' => self::mtime(storage_path('logs/schedule.log')),
            ],
            'notify' => [
                ...$this->settings->notify(),
                'listener' => $listener?->key,
            ],
            'limits' => $this->settings->limits(),
            'people' => [
                'by_status' => TelegramSenderBotUser::query()
                    ->select('status', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('status')
                    ->pluck('cnt', 'status'),
                'latest' => TelegramSenderBotUser::query()
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (TelegramSenderBotUser $u) => [
                        'id' => $u->id,
                        'telegram_user_id' => $u->telegram_user_id,
                        'username' => $u->username,
                        'name' => $u->name(),
                        'language' => $u->language,
                        'status' => $u->status,
                        'step' => $u->step,
                        'requested_at' => $u->requested_at?->toIso8601String(),
                        'notified_at' => $u->notified_at?->toIso8601String(),
                        'notify_error' => $u->notify_error,
                        'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                    ]),
            ],
            'groups' => TelegramSenderBotGroup::query()
                ->orderBy('id')
                ->get()
                ->map(fn (TelegramSenderBotGroup $g) => [
                    'id' => $g->id,
                    'chat_id' => $g->chat_id,
                    'title' => $g->label(),
                    'is_active' => $g->is_active,
                    'bot_status' => $g->bot_status,
                    'usable' => $g->is_active && $g->chat_id !== null && $g->botIsIn(),
                    'last_sent_at' => $g->last_sent_at?->toIso8601String(),
                    'last_error' => $g->last_error,
                ]),
            'posts' => TelegramSenderBotPost::query()
                ->with('user:id,username,first_name,last_name,telegram_user_id')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (TelegramSenderBotPost $p) => [
                    'id' => $p->id,
                    'user' => $p->user?->name(),
                    'kind' => $p->kind,
                    'text' => mb_substr((string) $p->text, 0, 120),
                    'status' => $p->status,
                    'times' => $p->times,
                    'sent_times' => $p->sent_times,
                    'interval_minutes' => $p->interval_minutes,
                    'next_at' => $p->next_at?->toIso8601String(),
                    'overdue_seconds' => $p->status === TelegramSenderBotPost::STATUS_ACTIVE && $p->next_at?->isPast()
                        ? (int) $p->next_at->diffInSeconds(now(), true)
                        : null,
                    'stopped_by' => $p->stopped_by,
                    'last_error' => $p->last_error,
                    'created_at' => $p->created_at?->toIso8601String(),
                ]),
            'sends' => TelegramSenderBotSend::query()
                ->with('group:id,title,chat_id')
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(fn (TelegramSenderBotSend $s) => [
                    'post' => $s->sender_bot_post_id,
                    'round' => $s->round,
                    'group' => $s->group?->label(),
                    'message_id' => $s->message_id,
                    'error' => $s->error,
                    'at' => $s->created_at?->toIso8601String(),
                ]),
            'log' => [
                'laravel' => self::grep(storage_path('logs/laravel.log'), '/sender[ _-]?bot/i', $lines),
                'schedule' => self::grep(storage_path('logs/schedule.log'), '/sender-bot/i', $lines),
            ],
        ];
    }

    private static function ago(?string $iso): ?int
    {
        if ($iso === null) {
            return null;
        }

        try {
            return (int) Carbon::parse($iso)->diffInSeconds(now(), true);
        } catch (Throwable) {
            return null;
        }
    }

    private static function mtime(string $path): ?string
    {
        return is_file($path) ? Carbon::createFromTimestamp((int) filemtime($path))->timezone(config('app.timezone'))->toIso8601String() : null;
    }

    /**
     * The last $count lines of the end of a log file that match $pattern.
     *
     * @return list<string>
     */
    private static function grep(string $path, string $pattern, int $count): array
    {
        if ($count <= 0 || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $size = (int) filesize($path);
            $handle = fopen($path, 'r');

            if ($handle === false) {
                return [];
            }

            fseek($handle, max(0, $size - self::LOG_TAIL));
            $tail = (string) stream_get_contents($handle);
            fclose($handle);
        } catch (Throwable) {
            return [];
        }

        $found = array_values(array_filter(
            preg_split('/\R/', $tail) ?: [],
            static fn (string $line) => preg_match($pattern, $line) === 1,
        ));

        return array_map(
            static fn (string $line) => mb_substr($line, 0, 600),
            array_slice($found, -$count),
        );
    }
}
