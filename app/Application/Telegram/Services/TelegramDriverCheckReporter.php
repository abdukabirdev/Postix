<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Actions\RerunTelegramDriverCheck;
use App\Application\Telegram\Support\VendorNoticeShield;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Models\Driver\TelegramDriverCheck;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramDriverCheckReporter
{
    public function __construct(
        private readonly TelegramOperatorNotifier $operatorNotifier,
        private readonly DriverCheckBot $bot,
    ) {}

    /**
     * Brings an already posted report in line with the check.
     *
     * Called when the verdict changed after the report went out: a
     * button in the group decided it by hand, or a re-run reached a new
     * answer. The report is edited in place, so the group keeps one
     * report per driver; one sent before report ids were kept has no
     * message to edit, and gets a fresh reply instead.
     */
    public function edit(
        SimpleEventHandler $telegram,
        TelegramDriverCheck $check,
    ): void {
        $message = $this->buildMessage(
            check: $check,
            match: data_get($check->telegram_raw, 'name_match'),
        );

        if ($check->report_message_id === null) {
            $check->forceFill([
                'report_message_id' => $this->post($telegram, $check, $message),
            ])->save();
        } else {
            $this->editMessage($telegram, $check, (int) $check->report_message_id, $message);
        }

        $this->bot->syncNow($check);
    }

    /**
     * Marks a posted report as outdated, before its check is re-run
     * under another message: the verdict it shows is struck through and
     * the note says where the new one will be.
     */
    public function retire(
        SimpleEventHandler $telegram,
        TelegramDriverCheck $check,
        string $note,
    ): void {
        if ($check->report_message_id === null) {
            return;
        }

        try {
            $message = $this->buildMessage(
                check: $check,
                match: data_get($check->telegram_raw, 'name_match'),
                statusLine: '<b>Статус:</b> <s>'
                    . $this->statusLabel($check->status?->value ?? 'unknown')
                    . '</s> 🔄 ПОВТОРНАЯ ПРОВЕРКА',
            );

            $this->editMessage(
                $telegram,
                $check,
                (int) $check->report_message_id,
                $message . "\n\n" . $this->escape($note),
            );
        } catch (Throwable $e) {
            // The new report is what matters; an outdated one left as is is not.
            Log::warning(
                'Failed to mark a driver check report as outdated',
                [
                    'check_id' => $check->id,
                    'report_message_id' => $check->report_message_id,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Posts the report under the message it answers and returns the id
     * Telegram gave it.
     */
    private function post(
        SimpleEventHandler $telegram,
        TelegramDriverCheck $check,
        string $message,
    ): ?int {
        /*
         * A PHP notice from inside MadelineProto must not abort a
         * report that Telegram has already accepted: reported_at
         * would stay null and the cron would send the same reply
         * again on every pass. See VendorNoticeShield.
         */
        $result = VendorNoticeShield::guard(
            'messages.sendMessage',
            static fn(): mixed => $telegram->messages->sendMessage([
                'peer' => $check->telegram_chat_id,

                'reply_to' => [
                    '_' => 'inputReplyToMessage',
                    'reply_to_msg_id'
                    => $check->report_reply_to_message_id
                        ?? $check->telegram_message_id,
                ],

                'message' => $message,
                'parse_mode' => 'html',
                'no_webpage' => true,
            ]),
            [
                'check_id' => $check->id,
                'chat_id' => $check->telegram_chat_id,
            ],
        );

        return self::sentMessageId($result);
    }

    private function editMessage(
        SimpleEventHandler $telegram,
        TelegramDriverCheck $check,
        int $messageId,
        string $message,
    ): void {
        try {
            VendorNoticeShield::guard(
                'messages.editMessage',
                static fn(): mixed => $telegram->messages->editMessage([
                    'peer' => $check->telegram_chat_id,
                    'id' => $messageId,
                    'message' => $message,
                    'parse_mode' => 'html',
                    'no_webpage' => true,
                ]),
                [
                    'check_id' => $check->id,
                    'chat_id' => $check->telegram_chat_id,
                ],
            );
        } catch (Throwable $e) {
            /*
             * Two edits raced to the same text: the report already
             * says what it should.
             */
            if (str_contains($e->getMessage(), 'MESSAGE_NOT_MODIFIED')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * The id of the message a messages.sendMessage call created.
     *
     * MadelineProto hands back the raw Updates: a short
     * updateShortSentMessage, or an updates bundle where the id sits in
     * updateMessageID and in the new-message update itself.
     */
    public static function sentMessageId(mixed $result): ?int
    {
        if (! is_array($result)) {
            return null;
        }

        if (($result['_'] ?? null) === 'updateShortSentMessage' && isset($result['id'])) {
            return (int) $result['id'];
        }

        $updates = ($result['_'] ?? null) === 'updateShort'
            ? [$result['update'] ?? null]
            : ($result['updates'] ?? []);

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $type = $update['_'] ?? null;

            if ($type === 'updateMessageID' && isset($update['id'])) {
                return (int) $update['id'];
            }

            if (
                in_array($type, ['updateNewChannelMessage', 'updateNewMessage'], true)
                && isset($update['message']['id'])
            ) {
                return (int) $update['message']['id'];
            }
        }

        return null;
    }

    public function send(
        SimpleEventHandler $telegram,
        TelegramDriverCheck $check,
        ?array $match = null,
    ): void {
        try {
            $message = $this->buildMessage(
                check: $check,
                match: $match,
            );

            /*
             * A PHP notice from inside MadelineProto must not abort a
             * report that Telegram has already accepted: reported_at
             * would stay null and the cron would send the same reply
             * again on every pass. See VendorNoticeShield.
             */
            $sent = $this->post($telegram, $check, $message);

            $check->forceFill([
                'reported_at' => now(),
                'report_message_id' => $sent,
                'report_dirty_at' => null,
            ])->save();

            /*
             * The buttons go out straight after the report, from this
             * process - the bot has nothing to watch in the group, it only
             * follows the reports this listener posts.
             */
            $this->bot->syncNow($check);

            /*
             * The same report is copied into the operator's private chat.
             * It runs after the group reply and swallows its own failures,
             * so an unreachable operator never blocks the group report or
             * leaves the check unreported.
             */
            if ($check->status?->value === 'not_confirmed') {
                $this->operatorNotifier->notify(
                    telegram: $telegram,
                    check: $check,
                    report: $message,
                );
            }
        } catch (Throwable $e) {
            Log::error(
                'Failed to send driver check reply',
                [
                    'check_id'
                    => $check->id,

                    'chat_id'
                    => $check->telegram_chat_id,

                    'message_id'
                    => $check->telegram_message_id,

                    'error'
                    => $e->getMessage(),

                    'exception'
                    => $e::class,
                ],
            );

            throw $e;
        }
    }

    private function buildMessage(
        TelegramDriverCheck $check,
        ?array $match,
        ?string $statusLine = null,
    ): string {
        $lines = [
            '<b>Проверка Telegram водителя</b>',
            '',

            $statusLine ?? $this->statusLine($check),

            '<b>ID проверки:</b> '
                . $check->id,

            '<b>ID сообщения:</b> '
                . $check->telegram_message_id,

            ...$this->decisionLines($check),

            '',

            '<b>Телефон:</b>',

            $this->escape(
                $check->phone_normalized
                    ?? '-',
            ),

            '',

            '<b>Водитель из сообщения:</b>',

            $this->escape(
                $check->driver_name
                    ?? '-',
            ),

            '',

            '<b>Telegram:</b>',

            $this->escape(
                $this->telegramDisplayName(
                    $check,
                ),
            ),
        ];

        /*
         * -------------------------------------------------------------
         * USERNAME
         * -------------------------------------------------------------
         */
        if (
            is_string(
                $check->telegram_username,
            )
            && trim(
                $check->telegram_username,
            ) !== ''
        ) {
            $lines[]
                = '<b>Имя пользователя:</b> @'
                . $this->escape(
                    ltrim(
                        $check->telegram_username,
                        '@',
                    ),
                );
        }

        /*
         * -------------------------------------------------------------
         * TELEGRAM USER ID
         * -------------------------------------------------------------
         */
        if (
            $check->telegram_user_id
        ) {
            $lines[]
                = '<b>ID пользователя:</b> '
                . $check->telegram_user_id;
        }

        /*
         * -------------------------------------------------------------
         * MATCH RESULT
         * -------------------------------------------------------------
         */
        if (
            is_array($match)
        ) {
            $this->appendMatchResult(
                lines: $lines,
                match: $match,
            );
        } elseif ($check->status?->isFinal()) {
            $this->appendPlainReason($lines, $check);
        }

        /*
         * -------------------------------------------------------------
         * ERROR
         * -------------------------------------------------------------
         */
        if (
            is_string(
                $check->error_message,
            )
            && trim(
                $check->error_message,
            ) !== ''
        ) {
            $lines[] = '';

            $lines[]
                = '<b>Ошибка:</b>';

            $lines[]
                = $this->escape(
                    mb_substr(
                        $check->error_message,
                        0,
                        1000,
                    ),
                );
        }

        return implode(
            "\n",
            $lines,
        );
    }

    private function appendMatchResult(
        array &$lines,
        array $match,
    ): void {
        $lines[] = '';

        /*
         * -------------------------------------------------------------
         * SCORE
         * -------------------------------------------------------------
         */
        $score
            = $this->normalizeScore(
                $match['score'] ?? 0,
            );

        $lines[]
            = '<b>Оценка совпадения:</b> '
            . $score;

        /*
         * -------------------------------------------------------------
         * LEVEL
         * -------------------------------------------------------------
         */
        $level
            = (string) (
                $match['level']
                ?? '-'
            );

        $lines[]
            = '<b>Уровень:</b> '
            . $this->levelLabel(
                $level,
            );

        /*
         * -------------------------------------------------------------
         * CONFIDENCE
         * -------------------------------------------------------------
         */
        $confidence
            = $match['confidence']
            ?? null;

        if (
            is_string($confidence)
            && trim($confidence) !== ''
        ) {
            $lines[]
                = '<b>Уверенность:</b> '
                . $this->confidenceLabel(
                    $confidence,
                );
        }

        /*
         * -------------------------------------------------------------
         * MATCHED PARTS
         * -------------------------------------------------------------
         *
         * Показываем только итоговые совпадения.
         *
         * Не используем matched_tokens,
         * потому что там могут быть технические
         * кандидаты и дубликаты.
         */
        $matchedParts
            = $match['matched_parts']
            ?? [];

        if (
            is_array($matchedParts)
            && $matchedParts !== []
        ) {
            $uniqueParts
                = $this->uniqueMatchedParts(
                    $matchedParts,
                );

            if ($uniqueParts !== []) {
                $lines[] = '';

                $lines[]
                    = '<b>Совпавшие части:</b>';

                foreach (
                    $uniqueParts as $part
                ) {
                    $from
                        = trim(
                            (string) (
                                $part['from']
                                ?? $part['actual']
                                ?? ''
                            ),
                        );

                    $to
                        = trim(
                            (string) (
                                $part['to']
                                ?? $part['expected']
                                ?? ''
                            ),
                        );

                    if (
                        $from === ''
                        && $to === ''
                    ) {
                        continue;
                    }

                    $partScore
                        = $this->normalizeScore(
                            $part['score']
                                ?? 0,
                        );

                    $lines[]
                        = '• '
                        . $this->escape(
                            $from,
                        )
                        . ' → '
                        . $this->escape(
                            $to,
                        )
                        . ' ('
                        . $partScore
                        . ')';
                }
            }
        }

        $this->appendReasons($lines, $match);
    }

    /**
     * "Причина:" at the end of the report: what the code found, and what
     * the AI decided when it was asked (DriverNameJudge) - in Russian, so
     * nobody needs the logs to see why a driver was (not) confirmed.
     *
     * @param list<string> $lines
     */
    private function appendReasons(array &$lines, array $match): void
    {
        $bullets = [];

        $score = round((float) ($match['score'] ?? 0), 1);
        $codeSaid = ($match['matched_by'] ?? null) === 'ai'
            ? (($match['ai']['code_matched'] ?? false) ? 'совпадает' : 'не совпадает')
            : (($match['matched'] ?? false) ? 'совпадает' : 'не совпадает');

        $why = [];

        foreach ((array) ($match['reasons'] ?? []) as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $why[] = $this->reasonLabel(trim($reason));
            }
        }

        $why = array_values(array_unique($why));

        $bullets[] = 'Проверка кодом: ' . $codeSaid . ' (' . $score . ')'
            . ($why !== [] ? ' — ' . implode('; ', $why) : '');

        $ai = $match['ai'] ?? null;

        if (is_array($ai)) {
            $verdict = match ($ai['verdict'] ?? null) {
                'match' => 'совпадает',
                'mismatch' => 'не совпадает',
                default => null,
            };
            $reason = trim((string) ($ai['reason'] ?? ''));

            if ($verdict === null) {
                $bullets[] = 'ИИ не ответил, решил код'
                    . (trim((string) ($ai['error'] ?? '')) !== '' ? ': ' . mb_substr(trim((string) $ai['error']), 0, 160) : '');
            } elseif (($ai['mode'] ?? null) === 'shadow') {
                $bullets[] = 'ИИ считает (пробный режим, решил код): ' . $verdict . ($reason !== '' ? ' — ' . $reason : '');
            } else {
                $bullets[] = 'Вердикт ИИ: ' . $verdict . ($reason !== '' ? ' — ' . $reason : '');
            }
        }

        $lines[] = '';
        $lines[] = '<b>Причина:</b>';

        foreach ($bullets as $bullet) {
            $lines[] = '• ' . $this->escape($bullet);
        }
    }

    /**
     * "Причина:" of a check that compared no names: the number is not in
     * Telegram, or the check failed.
     *
     * @param list<string> $lines
     */
    private function appendPlainReason(array &$lines, TelegramDriverCheck $check): void
    {
        $result = data_get($check->telegram_raw, 'resolver_result');

        $reason = match (true) {
            $result === 'phone_not_registered' => 'Номер не зарегистрирован в Telegram',
            in_array($result, TelegramDriverCheck::TECHNICAL_FAILURES, true),
            is_string($result) && str_starts_with($result, 'command_failed') => 'Техническая ошибка проверки — номер не удалось проверить',
            $check->telegram_user_id === null => 'Аккаунт Telegram по номеру не найден',
            default => 'В Telegram нет имени, сравнивать не с чем',
        };

        $lines[] = '';
        $lines[] = '<b>Причина:</b>';
        $lines[] = '• ' . $this->escape($reason);
    }

    private function telegramDisplayName(
        TelegramDriverCheck $check,
    ): string {
        $parts = [];

        $firstName
            = trim(
                (string) (
                    $check->telegram_first_name
                    ?? ''
                ),
            );

        $lastName
            = trim(
                (string) (
                    $check->telegram_last_name
                    ?? ''
                ),
            );

        if ($firstName !== '') {
            $parts[] = $firstName;
        }

        if ($lastName !== '') {
            $parts[] = $lastName;
        }

        return $parts !== []
            ? implode(
                ' ',
                $parts,
            )
            : '-';
    }

    /**
     * The status, with the one it replaced struck through.
     *
     * What gets struck is the verdict a reader would otherwise take for
     * the current one: the system's own verdict when a person overrode
     * it, or the previous run's verdict when a re-run changed it -
     * "❌ НЕ ПОДТВЕРЖДЕНО" crossed out, "✅ ПОДТВЕРЖДЕНО" after it.
     */
    private function statusLine(
        TelegramDriverCheck $check,
    ): string {
        $current = $check->status;

        $replaced = $check->isManuallyDecided()
            ? $check->system_status
            : $check->previousStatus();

        $line = '<b>Статус:</b> ';

        if (
            $replaced !== null
            && $replaced !== $current
            && $replaced->isFinal()
        ) {
            $line .= '<s>' . $this->statusLabel($replaced->value) . '</s> ';
        }

        return $line . $this->statusLabel($current?->value ?? 'unknown');
    }

    /**
     * Who changed the verdict, and why the check ran again.
     *
     * @return list<string>
     */
    private function decisionLines(
        TelegramDriverCheck $check,
    ): array {
        $lines = [];

        if ($check->isManuallyDecided()) {
            $lines[] = ($check->status === TelegramDriverCheckStatus::Confirmed
                ? '<b>Подтвердил вручную:</b> '
                : '<b>Отклонил вручную:</b> ')
                . $this->escape((string) $check->manual_by_name)
                . ' · '
                . $check->manual_at?->format('d.m.Y H:i');
        }

        $history = $check->history();
        $last = $history === [] ? null : $history[array_key_last($history)];

        if (! is_array($last)) {
            return $lines;
        }

        $by = is_string($last['by'] ?? null) && trim($last['by']) !== ''
            ? ' (' . $this->escape(trim($last['by'])) . ')'
            : '';

        if (($last['trigger'] ?? null) === RerunTelegramDriverCheck::TRIGGER_PHONE_CHANGED) {
            $lines[] = '<b>Номер изменён:</b> '
                . $this->escape((string) ($last['phone'] ?? '-'))
                . ' ⟶ '
                . $this->escape((string) ($check->phone_normalized ?? '-'))
                . $by;
        } elseif (($last['trigger'] ?? null) === RerunTelegramDriverCheck::TRIGGER_RECHECK) {
            $lines[] = '<b>Повторная проверка</b>' . $by;
        }

        return $lines;
    }

    private function statusLabel(
        string $status,
    ): string {
        return match ($status) {
            'confirmed'
            => '✅ ПОДТВЕРЖДЕНО',

            'not_confirmed'
            => '❌ НЕ ПОДТВЕРЖДЕНО',

            'pending'
            => '⏳ ОЖИДАЕТ ПРОВЕРКИ',

            'processing'
            => '🔄 ПРОВЕРЯЕТСЯ',

            default
            => '❓ НЕИЗВЕСТНЫЙ СТАТУС',
        };
    }

    private function levelLabel(
        string $level,
    ): string {
        return match ($level) {
            'very_strong'
            => 'очень сильное совпадение',

            'strong'
            => 'сильное совпадение',

            'likely'
            => 'вероятное совпадение',

            'possible'
            => 'возможное совпадение',

            'weak'
            => 'слабое совпадение',

            'no_match'
            => 'совпадений нет',

            'no_data'
            => 'недостаточно данных',

            default
            => $level,
        };
    }

    private function confidenceLabel(
        string $confidence,
    ): string {
        return match ($confidence) {
            'high'
            => 'высокая',

            'medium'
            => 'средняя',

            'low'
            => 'низкая',

            'none'
            => 'нет',

            default
            => $confidence,
        };
    }

    private function reasonLabel(
        string $reason,
    ): string {
        return match ($reason) {
            'identity_match'
            => 'Имя и данные Telegram достаточно хорошо совпадают.',

            'surname_and_first_name_match'
            => 'Совпали фамилия и имя.',

            'surname_and_username_match'
            => 'Совпали фамилия и имя пользователя Telegram.',

            'first_name_and_username_match'
            => 'Совпали имя и имя пользователя Telegram.',

            'strong_first_name_near_surname'
            => 'Имя совпадает уверенно, а фамилия имеет близкое написание.',

            'first_name_only'
            => 'Совпало только имя.',

            'surname_only'
            => 'Совпала только фамилия.',

            'username_only'
            => 'Совпало только имя пользователя Telegram.',

            'missing_name_data'
            => 'Недостаточно данных для проверки имени.',

            'exact_full_name'
            => 'Полное имя совпало.',

            'strong_name_match'
            => 'Обнаружено сильное совпадение имени.',

            'exact_core'
            => 'Основная часть имени совпала полностью.',

            'leet_normalized_exact'
            => 'Имя совпало после нормализации символов.',

            'ordered_subsequence'
            => 'Обнаружено частичное совпадение символов.',

            'ordered_contains'
            => 'Одно имя содержит основную часть другого.',

            'phonetic_equal'
            => 'Имена совпадают по произношению.',

            'fuzzy'
            => 'Обнаружено близкое написание имени.',

            'No match' => 'совпадений нет',
            'Exact match' => 'точное совпадение',
            'Transliteration variant' => 'то же имя в другой транслитерации',
            'Minor spelling variation' => 'небольшая разница в написании',
            'Partial name / nickname match' => 'совпала часть имени или уменьшительная форма',
            'Short partial-name match' => 'совпала только короткая часть имени',
            'Same name root, different name affix' => 'тот же корень имени, другое окончание',
            'Same name, different vowel spelling' => 'то же имя, другие гласные',
            'Same name root, different vowel spelling' => 'тот же корень имени, другие гласные',
            'All driver name tokens match exactly.' => 'всё ФИО совпало',
            'Initials match the driver name' => 'совпали инициалы',

            default
            => $this->humanizeReason(
                $reason,
            ),
        };
    }

    private function humanizeReason(
        string $reason,
    ): string {
        $reason
            = str_replace(
                [
                    '_',
                    '-',
                ],
                ' ',
                $reason,
            );

        $reason
            = preg_replace(
                '/\s+/u',
                ' ',
                $reason,
            )
            ?? $reason;

        return ucfirst(
            trim($reason),
        );
    }

    private function normalizeScore(
        mixed $score,
    ): string {
        $score
            = is_numeric($score)
            ? (float) $score
            : 0.0;

        $score
            = max(
                0.0,
                min(
                    100.0,
                    $score,
                ),
            );

        if (
            abs(
                $score - round($score),
            ) < 0.00001
        ) {
            return (string) ((int) round($score));
        }

        return rtrim(
            rtrim(
                number_format(
                    $score,
                    2,
                    '.',
                    '',
                ),
                '0',
            ),
            '.',
        );
    }

    private function uniqueMatchedParts(
        array $parts,
    ): array {
        $unique = [];

        $seen = [];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $field
                = trim(
                    (string) (
                        $part['field']
                        ?? ''
                    ),
                );

            $from
                = trim(
                    (string) (
                        $part['from']
                        ?? $part['actual']
                        ?? ''
                    ),
                );

            $to
                = trim(
                    (string) (
                        $part['to']
                        ?? $part['expected']
                        ?? ''
                    ),
                );

            $key
                = mb_strtolower(
                    implode(
                        '|',
                        [
                            $field,
                            $from,
                            $to,
                        ],
                    ),
                    'UTF-8',
                );

            if (
                isset(
                    $seen[$key],
                )
            ) {
                continue;
            }

            $seen[$key] = true;

            $unique[] = $part;
        }

        return $unique;
    }

    private function escape(
        string $value,
    ): string {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
