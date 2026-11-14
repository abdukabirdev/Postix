<?php

namespace App\Application\Telegram\Services;

use App\Enums\Drivers\TelegramDriverMessageType;

final class TelegramMessageTypeDetector
{
    public function detect(
        string $text,
    ): TelegramDriverMessageType {
        $text = trim($text);

        if (
            str_contains(
                $text,
                '👤 Создан новый водитель',
            )
        ) {
            return TelegramDriverMessageType::CREATED_DRIVER;
        }

        if (
            str_contains(
                $text,
                '👤 Изменён водитель',
            )
            || str_contains(
                $text,
                '👤 Изменен водитель',
            )
            || str_contains(
                $text,
                '👤 Изменены данные водителя',
            )
        ) {
            return TelegramDriverMessageType::UPDATED_DRIVER;
        }

        if (
            str_contains(
                $text,
                '🚛 Создан новый транспорт',
            )
        ) {
            return TelegramDriverMessageType::CREATED_TRANSPORT;
        }

        if (
            str_contains(
                $text,
                '🚛 Изменены данные транспорта',
            )
        ) {
            return TelegramDriverMessageType::UPDATED_TRANSPORT;
        }

        /*
         * "⚠️ Штраф по запросу #…" the first time,
         * "🆘 Повторное отправление штрафа №N по запросу #…" after that.
         */
        if (
            preg_match(
                '/(?:^|\R)\s*\S*\s*(?:Штраф по запросу|Повторное отправление штрафа\s*№\s*\d+\s*по запросу)\s*#/u',
                $text,
            ) === 1
        ) {
            return TelegramDriverMessageType::PENALTY;
        }

        /* "❌ Запрос отменён (EGS21300)" */
        if (RequestCancellationParser::matches($text)) {
            return TelegramDriverMessageType::REQUEST_CANCELLED;
        }

        /* "📍 Не назначенные слежения — 13.11.2026 16:00" */
        if (UntrackedOrdersParser::matches($text)) {
            return TelegramDriverMessageType::UNTRACKED_ORDERS;
        }

        return TelegramDriverMessageType::UNKNOWN;
    }
}
