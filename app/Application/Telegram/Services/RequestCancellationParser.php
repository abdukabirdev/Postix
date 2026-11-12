<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

/**
 * The CRM bot's "a request was cancelled":
 *
 *   ❌ Запрос отменён (EGS21300)
 *   Маршрут: 🇨🇳 Changsha, Хунань, Китай  → 🇺🇿 Наманган, Узбекистан
 *   Причина: Не прошла ставка
 *   Клиент: ARXIDEYA MCHJ (ИНН: 304975524)
 *   Sales: RAJABOVA MAKHBUBA ERMAMAT KIZI
 *   Operation: SALOHIDDINOV FAZLIDDIN SALOHIDDIN OGLI
 *   Отменил: RAJABOVA MAKHBUBA ERMAMAT KIZI
 *   Дата создания: 09.11.2026 11:51
 *   Дата отмены: 11.11.2026 14:28
 *   Открыть запрос (https://crm…)
 */
final class RequestCancellationParser
{
    /** The first line; group 1 is the request number. */
    public const HEADER = '/(?:^|\R)\h*\S*\h*Запрос\h+отмен[её]н\h*\(\h*#?\h*([A-Za-z0-9_-]+)\h*\)/u';

    private const LABELS = [
        'маршрут' => 'route',
        'причина' => 'reason',
        'клиент' => 'client',
        'sales' => 'sales_name',
        'operation' => 'operation_name',
        'отменил' => 'cancelled_by',
        'дата создания' => 'created_at',
        'дата отмены' => 'cancelled_at',
    ];

    public static function matches(string $text): bool
    {
        return preg_match(self::HEADER, $text) === 1;
    }

    /**
     * @return array{request_number: string|null, route: string|null, reason: string|null, client: string|null,
     *               sales_name: string|null, operation_name: string|null, cancelled_by: string|null,
     *               created_at: string|null, cancelled_at: string|null}
     */
    public function parse(string $text): array
    {
        $parsed = ['request_number' => null] + array_fill_keys(array_values(self::LABELS), null);

        if (preg_match(self::HEADER, $text, $m) === 1) {
            $parsed['request_number'] = mb_strtoupper($m[1]);
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\h*([^:]+?)\h*:\h*(.+?)\h*$/u', $line, $m) !== 1) {
                continue;
            }

            $key = self::LABELS[mb_strtolower($m[1])] ?? null;

            if ($key !== null && $parsed[$key] === null) {
                $parsed[$key] = $m[2];
            }
        }

        return $parsed;
    }
}
