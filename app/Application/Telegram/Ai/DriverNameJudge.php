<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Models\Driver\TelegramDriverCheck;
use Throwable;

/**
 * The second opinion of the driver check.
 *
 * The code (TelegramNameMatcher) checks first; when it finds the names the
 * same, nobody else is asked. When the Telegram account has a name or a
 * username and the code still says "different", Claude decides:
 *
 *   live    its verdict is the verdict - "match" confirms the driver
 *   shadow  only written into the report ("ИИ считает: ..."), the code decides
 *
 * The answer goes into the match array as `ai`, so the group report, its
 * later edits and the operator's copy all show it, and a re-run clears it
 * with the rest of name_match.
 */
final class DriverNameJudge
{
    public const AREA = 'driver_check';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'verdict' => ['type' => 'string', 'enum' => ['match', 'mismatch']],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['verdict', 'reason'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly AiClient $client,
        private readonly AiSettings $settings,
        private readonly AiPrompts $prompts,
    ) {}

    /**
     * Whether the AI has to look at this result: the code said "different",
     * there was something to compare, and the area is on.
     *
     * @param array<string, mixed> $match
     */
    public function wanted(array $match): bool
    {
        if (($match['matched'] ?? false) === true) {
            return false;
        }

        if (($match['level'] ?? null) === 'no_data' || ($match['decision'] ?? null) === 'no_data') {
            return false;
        }

        if (trim((string) ($match['expected_name'] ?? '')) === '') {
            return false;
        }

        $telegram = trim((string) ($match['telegram_name'] ?? '') . ' ' . (string) ($match['telegram_username'] ?? ''));

        if ($telegram === '') {
            return false;
        }

        return $this->settings->effective(self::AREA) !== null;
    }

    /**
     * Asks Claude and returns the match array with its `ai` part; in live
     * mode a "match" also turns `matched` on. Never throws: a failed call
     * leaves the code's verdict and says why in `ai.error`.
     *
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    public function judge(
        int $checkId,
        array $match,
        ?string $firstName,
        ?string $lastName,
        ?string $username,
    ): array {
        $mode = $this->settings->effective(self::AREA);

        if ($mode === null) {
            return $match;
        }

        try {
            $answer = $this->client->ask(
                area: self::AREA,
                kind: 'name',
                mode: $mode,
                system: [$this->prompts->get('driver_check')],
                user: $this->question($match, $firstName, $lastName, $username),
                schema: self::SCHEMA,
                subjectType: 'driver_check',
                subjectId: $checkId,
            );
        } catch (Throwable $e) {
            $match['ai'] = ['mode' => $mode, 'error' => $e->getMessage()];

            return $match;
        }

        $verdict = $answer->data['verdict'] ?? null;
        $verdict = in_array($verdict, ['match', 'mismatch'], true) ? $verdict : null;

        $match['ai'] = [
            'mode' => $mode,
            'code_matched' => (bool) ($match['matched'] ?? false),
            'verdict' => $verdict,
            'reason' => $verdict !== null ? mb_substr(trim((string) ($answer->data['reason'] ?? '')), 0, 300) : null,
            'error' => $verdict === null ? ($answer->error ?? 'нет ответа') : null,
            'call_id' => $answer->call?->id,
        ];

        if ($mode === AiSettings::MODE_LIVE && $verdict !== null) {
            $match['matched'] = $verdict === 'match';
            $match['matched_by'] = 'ai';
            $answer->call?->update(['used' => true]);
        }

        return $match;
    }

    /**
     * The question: the driver of our database, what Telegram shows, what
     * the code counted. Short on purpose.
     *
     * @param array<string, mixed> $match
     */
    public function question(array $match, ?string $firstName, ?string $lastName, ?string $username): string
    {
        $parts = [];

        foreach ((array) ($match['matched_parts'] ?? []) as $part) {
            if (is_array($part) && isset($part['from'], $part['to'])) {
                $parts[] = $part['from'] . ' → ' . $part['to'] . ' (' . round((float) ($part['score'] ?? 0), 1) . ')';
            }
        }

        $dash = static fn (?string $value): string => trim((string) $value) !== '' ? trim((string) $value) : '—';

        return implode("\n", [
            'ФИО водителя в базе: ' . $dash((string) ($match['expected_name'] ?? '')),
            'Telegram, имя: ' . $dash($firstName),
            'Telegram, фамилия: ' . $dash($lastName),
            'Telegram, @username: ' . ($username ? '@' . ltrim($username, '@') : '—'),
            'Автоматическая проверка: ' . round((float) ($match['score'] ?? 0), 1) . ' из 100'
                . ($parts !== [] ? ', совпавшие части: ' . implode('; ', $parts) : ', совпадений нет'),
        ]);
    }

    /**
     * Writes the final verdict onto a check that waited for the AI (the
     * cached path, JudgeDriverNameJob) - the same fields the code writes.
     *
     * @param array<string, mixed> $match
     */
    public function finish(TelegramDriverCheck $check, array $match): bool
    {
        $matched = (bool) ($match['matched'] ?? false);

        $raw = is_array($check->telegram_raw) ? $check->telegram_raw : [];
        $raw['name_match'] = $match;

        $check->update([
            'telegram_raw' => $raw,
            'status' => $matched ? TelegramDriverCheckStatus::Confirmed : TelegramDriverCheckStatus::NotConfirmed,
            'checked_at' => now(),
        ]);

        $check->driver?->update(['status' => $matched ? 'confirmed' : 'not_confirmed']);

        return $matched;
    }
}
