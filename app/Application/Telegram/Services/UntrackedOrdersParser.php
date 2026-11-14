<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

/**
 * The CRM bot's "orders nobody tracks yet", posted a few times a day:
 *
 *   📍 Не назначенные слежения — 13.11.2026 16:00
 *   Заказов без слежения за сегодня: 14
 *
 *   По компаниям:
 *   • EGS GROUP: 9
 *   • TRANSCEKA: 3
 *
 *   По оперейшнам:
 *   • ABDUKARIM TOSHMUQUMOV: 10
 *   • PULATOV AFZAL AHMADJON O'G'LI : 3
 *
 *   Открыть слежение (https://crm.zanjeer.uz/orders/trackings?status=-10)
 */
final class UntrackedOrdersParser
{
    /** The first line; groups 1 and 2 are the date and the time, when written. */
    public const HEADER = '/(?:^|\R)\h*\S*\h*Не\h*назначенн\S*\h+слежени\S*(?:\h*[—–-]\h*(\d{2}\.\d{2}\.\d{4})\h+(\d{1,2}:\d{2}))?/iu';

    public static function matches(string $text): bool
    {
        return preg_match(self::HEADER, $text) === 1;
    }

    /**
     * @return array{reported_at: string|null, total: int|null, url: string|null,
     *               companies: array<string, int>, operations: list<array{name: string, count: int}>}
     */
    public function parse(string $text): array
    {
        $parsed = [
            'reported_at' => null,
            'total' => null,
            'url' => null,
            'companies' => [],
            'operations' => [],
        ];

        if (preg_match(self::HEADER, $text, $m) === 1 && ($m[1] ?? '') !== '') {
            $parsed['reported_at'] = $m[1] . ' ' . str_pad($m[2], 5, '0', STR_PAD_LEFT);
        }

        if (preg_match('/без\h+слежени\S*[^:\n]*:\h*(\d+)/iu', $text, $m) === 1) {
            $parsed['total'] = (int) $m[1];
        }

        if (preg_match('/\((https?:\/\/[^\s)]+)\)/u', $text, $m) === 1) {
            $parsed['url'] = $m[1];
        }

        /* The block a "• NAME: N" line belongs to: the last "По …:" heading above it. */
        $block = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\h*По\h+(\S+?)\h*:\h*$/iu', $line, $m) === 1) {
                $heading = mb_strtolower($m[1]);
                $block = str_starts_with($heading, 'компан') ? 'companies' : (str_starts_with($heading, 'оперейш') || str_starts_with($heading, 'операц') || str_starts_with($heading, 'operation') ? 'operations' : null);

                continue;
            }

            if ($block === null || preg_match('/^\h*[•·\-*]\h*(.+?)\h*:\h*(\d+)\h*$/u', $line, $m) !== 1) {
                continue;
            }

            $name = (string) preg_replace('/\h+/u', ' ', trim($m[1]));

            if ($name === '') {
                continue;
            }

            if ($block === 'companies') {
                $parsed['companies'][$name] = (int) $m[2];
            } else {
                $parsed['operations'][] = ['name' => $name, 'count' => (int) $m[2]];
            }
        }

        return $parsed;
    }
}
