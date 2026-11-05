<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

/**
 * How old a Telegram account is, by its user id. Telegram does not tell
 * when an account was registered, but hands the ids out in order - the
 * smaller, the older; after the 32-bit ids ran out new ones went on from
 * 5 billion. The years of the bands are approximate, the order is not.
 */
final class AccountAge
{
    public const UNKNOWN = 'unknown';

    /**
     * Upper bounds of the bands, oldest first: the page names each with its
     * approximate years.
     *
     * @var array<string, int>
     */
    private const BANDS = [
        'lt1' => 1_000_000_000,
        'b1_5' => 5_000_000_000,
        'b5_6' => 6_000_000_000,
        'b6_7' => 7_000_000_000,
        'b7_8' => 8_000_000_000,
    ];

    /** Everybody past the last bound. */
    private const NEWEST = 'b8';

    /**
     * The bands oldest first, for the page to list them in order.
     *
     * @return list<string>
     */
    public static function bands(): array
    {
        return [...array_keys(self::BANDS), self::NEWEST, self::UNKNOWN];
    }

    public static function band(int|string|null $telegramUserId): string
    {
        $id = (int) $telegramUserId;

        if ($id <= 0) {
            return self::UNKNOWN;
        }

        foreach (self::BANDS as $band => $below) {
            if ($id < $below) {
                return $band;
            }
        }

        return self::NEWEST;
    }
}
