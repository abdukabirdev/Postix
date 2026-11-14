<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramSetting;
use App\Models\Telegram\TelegramUntrackedReminder;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hurries the operators of a "📍 Не назначенные слежения" report: a plain
 * message in their private chat - "5 та заказни слеженияси йук, тезрок
 * бер" - harder at each level (the n-th report of the day that names
 * them). The texts are in config/client_checks.php (untracked), in the
 * person's language and tone, never the same one twice in a row.
 *
 * Runs on the tick of the account that delivers the person's penalties
 * (DutyOwners), only within the `hours`; what waits longer than
 * `wait_minutes` is given up - the next report comes anyway.
 */
final class UntrackedOrdersReminder
{
    public function __construct(
        private readonly ClientCheckEscalation $escalation,
    ) {
    }

    public function flush(SimpleEventHandler $telegram, ?string $key = null): void
    {
        $roles = app(DutyOwners::class)->penaltyRoles($key);

        if ($roles === []) {
            return;
        }

        $pending = fn (): Builder => TelegramUntrackedReminder::query()
            ->where('status', TelegramUntrackedReminder::STATUS_PENDING);

        if (! (bool) config('client_checks.untracked.enabled', true) || ! TelegramSetting::clientChecksEnabled()) {
            $pending()->update([
                'status' => TelegramUntrackedReminder::STATUS_SKIPPED,
                'reason' => TelegramUntrackedReminder::REASON_DISABLED,
            ]);

            return;
        }

        $pending()
            ->where('created_at', '<', now()->subMinutes(max(1, (int) config('client_checks.untracked.wait_minutes', 90))))
            ->update([
                'status' => TelegramUntrackedReminder::STATUS_SKIPPED,
                'reason' => TelegramUntrackedReminder::REASON_STALE,
            ]);

        if (! self::withinHours()) {
            return;
        }

        $rows = $pending()
            ->with('operationUser')
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            $person = $row->operationUser;

            /* Another account delivers this role's penalties: left to it. */
            if ($person instanceof OperationUser && ! in_array($person->roleOrDefault(), $roles, true)) {
                continue;
            }

            $this->remind($telegram, $row);
        }
    }

    /**
     * Within `untracked.hours` (app timezone). Both null: every hour.
     */
    public static function withinHours(): bool
    {
        $from = config('client_checks.untracked.hours.from');
        $to = config('client_checks.untracked.hours.to');

        if (! is_string($from) || ! is_string($to) || $from === $to) {
            return true;
        }

        $time = now()->setTimezone(config('app.timezone'))->format('H:i');

        return $from < $to
            ? $time >= $from && $time < $to
            : $time >= $from || $time < $to;
    }

    private function remind(SimpleEventHandler $telegram, TelegramUntrackedReminder $row): void
    {
        $person = $row->operationUser;

        if (! $person instanceof OperationUser || ! $person->hasTelegramPeer()) {
            $row->update([
                'status' => TelegramUntrackedReminder::STATUS_SKIPPED,
                'reason' => TelegramUntrackedReminder::REASON_NO_OPERATOR,
            ]);

            return;
        }

        if (! $person->allows('penalties_enabled')) {
            $row->update([
                'status' => TelegramUntrackedReminder::STATUS_SKIPPED,
                'reason' => TelegramUntrackedReminder::REASON_PERSON_OFF,
            ]);

            return;
        }

        /* Taken by this tick: a slow send must not be picked up again by the next one. */
        $claimed = TelegramUntrackedReminder::query()
            ->whereKey($row->id)
            ->where('status', TelegramUntrackedReminder::STATUS_PENDING)
            ->update([
                'status' => TelegramUntrackedReminder::STATUS_SENDING,
                'attempts' => $row->attempts + 1,
            ]);

        if ($claimed === 0) {
            return;
        }

        $row->forceFill([
            'status' => TelegramUntrackedReminder::STATUS_SENDING,
            'attempts' => $row->attempts + 1,
        ])->syncOriginal();

        $peers = $person->telegramPeerCandidates();
        $peer = $peers[0] ?? '';

        try {
            $language = $person->messageLanguage();
            $phrase = $this->phrase($row, $person, $language);
            $text = $this->render($phrase, $row, $person, $language);

            $errors = [];
            $sent = false;

            foreach ($peers as $candidate) {
                try {
                    $telegram->messages->sendMessage([
                        'peer' => $candidate,
                        'message' => $text,
                        'parse_mode' => 'html',
                        'no_webpage' => true,
                    ]);

                    $peer = $candidate;
                    $sent = true;

                    break;
                } catch (Throwable $e) {
                    $errors[] = "{$candidate}: " . $e->getMessage();
                }
            }

            if (! $sent) {
                throw new RuntimeException($errors === [] ? 'no reachable Telegram peer' : implode(' | ', $errors));
            }

            $row->update([
                'status' => TelegramUntrackedReminder::STATUS_SENT,
                'reason' => null,
                'error' => null,
                'peer' => (string) $peer,
                'phrase' => mb_substr($phrase, 0, 500),
                'text' => $text,
                'sent_at' => now(),
            ]);

            Log::info('Untracked orders: reminded', [
                'reminder_id' => $row->id,
                'operation_user_id' => $person->id,
                'orders' => $row->orders_count,
                'level' => $row->level,
            ]);
        } catch (Throwable $e) {
            $attempts = $row->attempts;
            $last = $attempts >= max(1, (int) config('client_checks.untracked.max_attempts', 3));

            $row->update([
                'status' => $last ? TelegramUntrackedReminder::STATUS_FAILED : TelegramUntrackedReminder::STATUS_PENDING,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('Untracked orders: not reminded', [
                'reminder_id' => $row->id,
                'operation_user_id' => $person->id,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
            ]);

            if ($last) {
                app(DeveloperAlerts::class)->report(
                    kind: 'untracked_reminder_failed',
                    title: 'Не напомнили про слежения: ' . $person->name,
                    details: $e->getMessage(),
                    context: [
                        'Заказов' => $row->orders_count,
                        'Человек (id)' => $person->id,
                        'Peer' => (string) $peer,
                        'Попыток' => $attempts,
                    ],
                    listenerKey: app(DutyOwners::class)->penaltyOwner($person->roleOrDefault()),
                    about: 'untracked:' . $row->id,
                );
            }
        }
    }

    /**
     * A phrase of the row's level (the last level covers every one after
     * it), in the person's language and tone - plain when the respectful
     * list is empty, Russian, then Uzbek, when theirs is. Never the one
     * they got last time.
     */
    private function phrase(TelegramUntrackedReminder $row, OperationUser $person, string $language): string
    {
        $levels = array_values(array_filter((array) config('client_checks.untracked.levels', []), 'is_array'));

        if ($levels === []) {
            return '{count} та заказга слежения беринг';
        }

        $level = $levels[min(max(1, $row->level), count($levels)) - 1];
        $tones = $person->respectful ? [ClientCheckRules::TONE_RESPECTFUL, ClientCheckRules::TONE_PLAIN] : [ClientCheckRules::TONE_PLAIN];

        $list = [];

        foreach (array_unique([$language, OperationUser::LANGUAGE_RU, OperationUser::LANGUAGE_UZ]) as $lang) {
            foreach ($tones as $tone) {
                $list = array_values(array_filter(
                    (array) ($level[$lang][$tone] ?? []),
                    static fn ($phrase): bool => is_string($phrase) && trim($phrase) !== '',
                ));

                if ($list !== []) {
                    break 2;
                }
            }
        }

        if ($list === []) {
            return '{count} та заказга слежения беринг';
        }

        $previous = TelegramUntrackedReminder::query()
            ->where('operation_user_id', $person->id)
            ->where('status', TelegramUntrackedReminder::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->value('phrase');

        $fresh = array_values(array_filter($list, static fn (string $phrase): bool => $phrase !== $previous));

        $pool = $fresh === [] ? $list : $fresh;

        return $pool[array_rand($pool)];
    }

    /**
     * {count} - the number, {orders} - "5 заказов" / "5 та заказ"; then
     * the penalties' own placeholders ({address}, {name}) and the Uzbek
     * script, as a penalty comment would get them.
     */
    private function render(string $phrase, TelegramUntrackedReminder $row, OperationUser $person, string $language): string
    {
        $count = $row->orders_count;

        $phrase = strtr($phrase, [
            '{count}' => (string) $count,
            '{orders}' => $language === OperationUser::LANGUAGE_RU ? self::ordersRu($count) : "{$count} та заказ",
        ]);

        return $this->escalation->render($phrase, new TelegramClientCheck(), $person, $language);
    }

    public static function ordersRu(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        $word = match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'заказ',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'заказа',
            default => 'заказов',
        };

        return "{$count} {$word}";
    }
}
