<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramListener;
use Throwable;

/**
 * Every account a listener holds: Driver Check's (TELEGRAM_DRIVER_CHECK_ACCOUNT_ID)
 * and those of the enabled rows of telegram_listeners.
 *
 * A MadelineProto session tolerates one full process, so no other part of
 * the system - the phone resolver, the contact sync, the sender pool - may
 * open these accounts. They all ask here. Not cached: a handful of rows,
 * and a listener switched on in the panel is off limits at once.
 */
final class ListenerAccounts
{
    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        /*
         * Within one web request the answer is kept: a page that asks it for
         * every account of a list would otherwise make a query for each. A
         * process that lives long (a command, a worker) never keeps it.
         */
        $memo = ! app()->runningInConsole() && app()->bound('request');

        if ($memo && ($kept = request()->attributes->get('listener_account_ids')) !== null) {
            return $kept;
        }

        $ids = self::ids_();

        if ($memo) {
            request()->attributes->set('listener_account_ids', $ids);
        }

        return $ids;
    }

    /**
     * What the request kept is thrown away: a listener was just changed.
     */
    public static function forgetMemo(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove('listener_account_ids');
        }
    }

    /**
     * @return list<int>
     */
    private static function ids_(): array
    {
        $ids = self::rowIds();

        $primary = config('services.telegram.driver_check_account_id');

        if ($primary !== null && $primary !== '') {
            $ids[] = (int) $primary;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Only the accounts of the listeners of the table.
     *
     * @return list<int>
     */
    public static function rowIds(): array
    {
        try {
            return TelegramListener::query()
                ->where('is_enabled', true)
                ->whereNotNull('telegram_account_id')
                ->pluck('telegram_account_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        } catch (Throwable) {
            /* The table is not there yet (migration pending): no listeners. */
            return [];
        }
    }

    public static function has(int $accountId): bool
    {
        return in_array($accountId, self::ids(), true);
    }
}
