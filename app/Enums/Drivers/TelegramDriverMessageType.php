<?php

namespace App\Enums\Drivers;

enum TelegramDriverMessageType: string
{
    case CREATED_DRIVER = 'created_driver';

    case UPDATED_DRIVER = 'updated_driver';

    case CREATED_TRANSPORT = 'created_transport';

    case UPDATED_TRANSPORT = 'updated_transport';

    /**
     * A CRM penalty: a request stuck in its status too long. Handled as a
     * client check (ProcessClientCheckMessage), never as a driver check.
     */
    case PENALTY = 'penalty';

    /**
     * "❌ Запрос отменён (EGS…)": the person who got the request's last
     * penalty is asked why (ProcessRequestCancellation).
     */
    case REQUEST_CANCELLED = 'request_cancelled';

    /**
     * "📍 Не назначенные слежения — 13.11.2026 16:00": every operator of
     * the list is hurried to give their orders a tracking
     * (ProcessUntrackedOrders).
     */
    case UNTRACKED_ORDERS = 'untracked_orders';

    case UNKNOWN = 'unknown';
}