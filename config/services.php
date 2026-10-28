<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'warehouse' => [
        'api_url' => env('WAREHOUSE_API_URL'),
        'login' => env('WAREHOUSE_API_LOGIN'),
        'password' => env('WAREHOUSE_API_PASSWORD'),
    ],
    /*
     * The CRM's API (crm.zanjeer.uz): the penalty bot's requests are looked
     * up here. URL without "/v1"; the token is logged in and cached.
     */
    'crm' => [
        'api_url' => env('CRM_API_URL', 'https://crm.zanjeer.uz/api'),
        'email' => env('CRM_API_EMAIL'),
        'password' => env('CRM_API_PASSWORD'),
    ],
    
    'telegram' => [

        'api_id' => env('TELEGRAM_API_ID'),
        'api_hash' => env('TELEGRAM_API_HASH'),

        /*
         * Daily save of group members into the accounts' contacts
         * (telegram:sync-contacts). Kept deliberately slow: Telegram
         * restricts accounts that add contacts in bulk.
         */
        'contact_sync' => [
            /* When the daily run starts (routes/console.php) and the report expects it. */
            'schedule_at' => env('TELEGRAM_CONTACT_SYNC_AT', '03:30'),
            'daily_limit' => (int) env('TELEGRAM_CONTACT_SYNC_DAILY_LIMIT', 80),
            'delay_min' => (int) env('TELEGRAM_CONTACT_SYNC_DELAY_MIN', 3),
            'delay_max' => (int) env('TELEGRAM_CONTACT_SYNC_DELAY_MAX', 5),
        ],
        /*
         * Groups the driver check listener watches, as a comma (or whitespace)
         * separated list: one link, several, or none at all.
         *
         * These are only the seed values - on startup they are imported into
         * telegram_driver_check_chats, which is what the listener actually
         * reads, so chats can be added from the panel without a deploy.
         */
        'driver_check_chat_links' => array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split(
                        '/[\s,;]+/',
                        (string) env(
                            'TELEGRAM_DRIVER_CHECK_CHAT_LINKS',
                            (string) env('TELEGRAM_DRIVER_CHECK_CHAT_LINK', '')
                        )
                    ) ?: []
                ),
                static fn (string $link): bool => $link !== ''
            )
        ),
        'driver_check_account_id' => env('TELEGRAM_DRIVER_CHECK_ACCOUNT_ID'),
        'driver_check_notification_chat_id' => env('TELEGRAM_DRIVER_CHECK_NOTIFICATION_CHAT_ID'),
        /*
         * Shared with the gateway project, which receives the Telegram
         * webhook and forwards updates to POST /api/telegram/webhook. It
         * arrives as X-Telegram-Bot-Api-Secret-Token or as a bearer
         * token. The bot token itself lives in config/telegram.php.
         */
        'bot_webhook_secret' => env('TELEGRAM_BOT_WEBHOOK_SECRET'),

        /*
         * Where problems are told to the developer (DeveloperAlerts): a bot
         * and the chat it writes to. Empty - they are only recorded on the
         * "How it works" page and in the log. The same problem is told once
         * per window_minutes.
         */
        'alerts' => [
            'bot_token' => env('TELEGRAM_ALERT_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_ALERT_CHAT_ID'),
            'window_minutes' => (int) env('TELEGRAM_ALERT_WINDOW_MINUTES', 30),
        ],
    ],

];
