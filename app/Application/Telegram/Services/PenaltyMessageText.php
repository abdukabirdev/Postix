<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

/**
 * The CRM bot's penalty, in our own words, for the account to write to the
 * person instead of forwarding the bot's message (2026-11-05: the person
 * must not read the word "штраф", and the bot's Russian reads clumsy).
 *
 *   🆘 Повторное отправление штрафа №2 по запросу #EGS21095
 *   ⚠️ Штраф по запросу #EGS21211
 *   Время на статус: 14 ч
 *   Стоит в статусе: 1 д 22 ч 4 мин
 *   Кубатура: 96 м³
 *   Ответственный (Sales): KIM ELENA GEORGIEVNA
 *   XASANOV FARXOD TAXIROVICH: 331 / 1
 *   Групп / сообщений в Telegram: 0 / 0
 *   Открыть запрос (https://crm…)
 *
 * becomes
 *
 *   🆘 Повторное напоминание №2 по запросу #EGS21095
 *   ⚠️ Напоминание по запросу #EGS21211
 *   Норма в статусе: 14 ч
 *   Уже в статусе: 1 д 22 ч 4 мин
 *   Объём: 96 м³
 *   Ответственный (отдел продаж): KIM ELENA GEORGIEVNA
 *   Размещение в Telegram (XASANOV FARXOD TAXIROVICH): групп — 331, сообщений — 1
 *   Размещение в Telegram: групп — 0, сообщений — 0
 *   Открыть запрос - a link
 *
 * Line by line, in the bot's order: a line it does not know (a new one the
 * bot adds, the CRM status, the dates) goes as it is. HTML, for the link.
 */
final class PenaltyMessageText
{
    /**
     * The bot's labels that read wrong, and ours.
     */
    private const LABELS = [
        'время на статус' => 'Норма в статусе',
        'стоит в статусе' => 'Уже в статусе',
        'кубатура' => 'Объём',
    ];

    private const ROLES = [
        'operation' => 'операционный отдел',
        'operations' => 'операционный отдел',
        'sales' => 'отдел продаж',
    ];

    public function html(string $text): string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];

        return implode("\n", array_map(fn (string $line): string => $this->line(trim($line)), $lines));
    }

    private function line(string $line): string
    {
        if ($line === '') {
            return '';
        }

        /* "🆘 Повторное отправление штрафа №2 по запросу #EGS21095" */
        if (preg_match('/^(.*?)(?:Повторное\s+отправление\s+)?штрафа\s*№\s*(\d+)\s*по\s+запросу\s*#\s*([A-Za-z0-9_-]+)(.*)$/iu', $line, $m) === 1) {
            return $this->e($m[1]) . 'Повторное напоминание №' . $m[2] . ' по запросу #' . $this->e($m[3]) . $this->e($m[4]);
        }

        /* "⚠️ Штраф по запросу #EGS21211" */
        if (preg_match('/^(.*?)Штраф\s+по\s+запросу\s*#\s*([A-Za-z0-9_-]+)(.*)$/iu', $line, $m) === 1) {
            return $this->e($m[1]) . 'Напоминание по запросу #' . $this->e($m[2]) . $this->e($m[3]);
        }

        /* "Ответственный (Sales): KIM ELENA GEORGIEVNA" */
        if (preg_match('/^Ответственный\s*\(\s*([^)]+?)\s*\)\s*:\s*(.*)$/iu', $line, $m) === 1) {
            $role = self::ROLES[mb_strtolower($m[1])] ?? $m[1];

            return 'Ответственный (' . $this->e($role) . '): ' . $this->e($m[2]);
        }

        /* "Открыть запрос (https://crm…)" */
        if (preg_match('~^(Открыть\s+запрос)\s*\(\s*(https?://[^\s)]+)\s*\)$~iu', $line, $m) === 1) {
            return '<a href="' . $this->e($m[2]) . '">' . $this->e($m[1]) . '</a>';
        }

        /* "Групп / сообщений в Telegram: 0 / 0" - nobody posted it */
        if (preg_match('~^Групп\s*/\s*сообщений\s+в\s+Telegram\s*:\s*(\d+)\s*/\s*(\d+)$~iu', $line, $m) === 1) {
            return 'Размещение в Telegram: групп — ' . $m[1] . ', сообщений — ' . $m[2];
        }

        /* "XASANOV FARXOD TAXIROVICH: 331 / 1" - who posted it, in how many groups, how many messages */
        if (preg_match('~^([^:]+?)\s*:\s*(\d+)\s*/\s*(\d+)$~u', $line, $m) === 1) {
            return 'Размещение в Telegram (' . $this->e($m[1]) . '): групп — ' . $m[2] . ', сообщений — ' . $m[3];
        }

        if (preg_match('/^([^:]+?)\s*:\s*(.*)$/u', $line, $m) === 1 && isset(self::LABELS[mb_strtolower($m[1])])) {
            return self::LABELS[mb_strtolower($m[1])] . ': ' . $this->e($m[2]);
        }

        return $this->e($line);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
