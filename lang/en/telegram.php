<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Shared panel chrome
    |--------------------------------------------------------------------------
    |
    | Labels used by the driver-check components: filters, sorting, paging,
    | empty and error states. One copy, so every page says the same thing.
    |
    */

    'ui' => [

        'filters' => 'Filters',
        'filters_show' => 'More filters',
        'filters_hide' => 'Hide filters',
        'filters_active' => 'Active filters',
        'reset' => 'Reset',
        'apply' => 'Apply',
        'search' => 'Search',
        'search_clear' => 'Clear search',
        'sort' => 'Sort',
        'sort_asc' => 'Ascending',
        'sort_desc' => 'Descending',
        'order' => 'Order',
        'per_page' => 'Per page',
        'showing' => 'Showing',
        'of' => 'of',
        'page' => 'Page',
        'previous' => 'Previous',
        'next' => 'Next',
        'loading' => 'Loading...',
        'refresh' => 'Refresh',
        'retry' => 'Try again',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'open' => 'Open',
        'close' => 'Close',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'saving' => 'Saving...',
        'created' => 'Created',
        'period' => 'Period',
        'period_custom' => 'Custom range',
        'details' => 'Details',
        'error' => 'Error',
        'never' => 'Never',
        'show_more' => 'Show more',
        'show_less' => 'Show less',
        'results' => 'results',

        'duration' => [
            'd' => 'd',
            'h' => 'h',
            'm' => 'min',
            's' => 's',
        ],
    ],

    'menu' => [
        'groups' => [
            'check' => 'Driver check',
            'settings' => 'Settings',
            'monitoring' => 'Monitoring',
        ],
        'items' => [
            'operation_users' => 'Operator statistics',
            'drivers' => 'Drivers',
            'resolved_phones' => 'Telegram numbers',
            'penalties' => 'CRM penalties',
            'people' => 'Everyone (people)',
            'operators' => 'Operators',
            'sales' => 'Sales',
            'penalty_settings' => 'Penalty settings',
            'auto_replies' => 'Auto replies',
            'personal_answers' => 'Personal answers',
            'chats' => 'Chats',
            'file_routes' => 'Files',
            'scheduled_messages' => 'Scheduled messages',
            'system' => 'How it works',
            'listeners' => 'Listeners',
            'sessions' => 'Telegram sessions',
            'watchdog' => 'Processes',
            'contact_sync' => 'Group contacts',
            'queue' => 'Queue',
        ],
    ],

    'operators' => [

        'title' => 'Operator management',

        'description' => 'Link operators to Telegram by hand: reports are copied into their private chat',

        'refresh' => 'Refresh',

        'loading' => 'Loading...',

        'create' => 'Add operator',

        'search_placeholder' => 'Name, @username or Telegram ID',

        'per_page' => 'Per page',

        'stats' => [
            'total' => 'Operators',
            'active' => 'Active',
            'linked' => 'With Telegram',
            'dm_enabled' => 'Receive direct messages',
            'failing' => 'Delivery failing',
        ],

        'filters' => [
            'title' => 'Filters',
            'reset' => 'Reset',
            'status' => 'Status',
            'status_all' => 'All',
            'status_active' => 'Active',
            'status_inactive' => 'Inactive',
            'dm' => 'Direct messages',
            'dm_all' => 'All',
            'dm_on' => 'Enabled',
            'dm_off' => 'Disabled',
            'linked' => 'Telegram',
            'linked_all' => 'All',
            'linked_yes' => 'Linked',
            'linked_no' => 'Not linked',
            'role' => 'Role',
            'role_all' => 'All',
        ],

        'roles' => [
            'operation' => 'Operation',
            'sales' => 'Sales',
        ],

        'table' => [
            'operator' => 'Operator',
            'telegram' => 'Telegram',
            'status' => 'Status',
            'dm' => 'Direct',
            'drivers' => 'Drivers',
            'checks' => 'Checks',
            'last_sent' => 'Last delivery',
            'actions' => 'Actions',
            'no_username' => 'No username',
            'no_id' => 'No ID',
            'never' => 'Never sent',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'dm_on' => 'Enabled',
            'dm_off' => 'Disabled',
            'dm_unreachable' => 'No contact',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'penalties' => 'Penalties',
            'last_penalty' => 'Last penalty',
            'no_penalties' => 'No penalties',
            'open_penalties' => 'Open penalties',
            'style' => 'How we write',
            'respectful' => 'Respectful',
        ],

        'form' => [
            'create_title' => 'New operator',
            'edit_title' => 'Edit operator',
            'name' => 'Operator name',
            'name_hint' => 'Must match the "Пользователь:" line of the group message',
            'name_normalized' => 'Matching key',
            'telegram_username' => 'Telegram username',
            'telegram_username_hint' => 'Without the "@". Tried first - it works even if the account has never met the operator',
            'telegram_id' => 'Telegram ID',
            'telegram_id_hint' => 'Fallback: only resolves once the account has seen this user',
            'is_active' => 'Active',
            'is_active_hint' => 'An inactive operator receives nothing',
            'dm_enabled' => 'Send reports to the private chat',
            'dm_enabled_hint' => 'A copy of the group report is sent to the operator directly',
            'switches' => 'What goes to their private chat',
            'switches_hint' => 'Applies while sending to the private chat is on above.',
            'switches_off' => 'Off: :n',
            'switch' => [
                'penalties_enabled' => [
                    'label' => 'CRM penalties',
                    'hint' => 'The penalty forward. Off - no penalties reach them at all.',
                ],
                'penalty_comments_enabled' => [
                    'label' => 'Penalty comment',
                    'hint' => 'The text after the forward: "Moshina chiqmadimi?".',
                ],
                'greet_first_enabled' => [
                    'label' => 'Greet first',
                    'hint' => '"Assalomu alaykum" before the day\'s first penalty.',
                ],
                'nudge_enabled' => [
                    'label' => 'Nudge',
                    'hint' => 'When they ignore several penalties in a row.',
                ],
                'auto_replies_enabled' => [
                    'label' => 'Auto replies',
                    'hint' => 'Answers to their messages: "+", "ok", greetings.',
                ],
            ],
            'role' => 'Role',
            'role_hint' => 'The role decides which page the person is on. Matching by name is shared: one person cannot be on both.',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving...',
            'delete' => 'Delete',
            'delete_hint' => 'Only someone without drivers, checks and penalties can be deleted.',
            'language' => 'Message language',
            'language_auto' => 'By role: :language',
            'language_hint' => 'The language penalty comments are written in.',
            'respectful' => 'Respectful',
            'respectful_hint' => 'For people who are older: penalty comments come in the polite version.',
            'address' => 'How to call them (:language)',
            'address_short' => 'How to call them',
            'address_hint' => '"Ali aka" for someone older, "jigar" for a close friend, "uka" for someone younger. Goes into the texts as {address}; left empty, it is left out.',
            'address_placeholder' => [
                'uz' => 'e.g. Ali aka',
                'ru' => 'e.g. Али ака',
            ],
        ],

        'validation' => [
            'duplicate_name' => 'An operator with this name already exists',
            'username_format' => 'A username may contain latin letters, digits and "_", 5 to 32 characters',
        ],

        'confirm' => [
            'delete_title' => 'Delete?',
            'delete_text' => 'This cannot be undone.',
            'delete' => 'Delete',
            'cancel' => 'Cancel',
        ],

        'messages' => [
            'created' => 'Operator added',
            'updated' => 'Changes saved',
            'deleted' => 'Deleted',
            'moved' => 'Saved and moved to ":role"',
        ],

        'deleted' => 'Operator deleted',

        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the operators',
            'save' => 'Could not save the operator',
            'delete' => 'Could not delete',
            'has_history' => 'This person already has drivers, checks or penalties and cannot be deleted - the history would lose its owner. Turn the private messages off instead.',
            'dm_last_error' => 'Last delivery error',
        ],

        'empty' => [
            'title' => 'No operators yet',
            'description' => 'Operators are created automatically from group messages. You can also add one by hand.',
        ],

        'pagination' => [
            'showing' => 'Showing',
            'to' => '—',
            'of' => 'of',
            'previous' => 'Previous',
            'next' => 'Next',
        ],

        'tabs' => [
            'all' => 'All',
            'operation' => 'Operators',
            'sales' => 'Sales',
        ],

        'pages' => [
            'operation' => [
                'title' => 'Operators',
                'description' => 'Operators from the driver-check groups: their reports are copied to their private chat. CRM penalties naming "Ответственный (Operation)" land here too.',
                'create' => 'Add operator',
                'edit_title' => 'Edit operator',
                'total' => 'Operators',
                'name_hint' => 'Must match the «Пользователь:» line of the group message',
                'dm_enabled_hint' => 'A copy of the group report and the CRM penalties are sent to the operator directly',
                'empty_title' => 'No operators',
                'empty_description' => 'Operators are created automatically from the group messages. You can also add one by hand.',
            ],
            'sales' => [
                'title' => 'Sales',
                'description' => 'Sales managers from the CRM penalties ("Ответственный (Sales)"): each penalty is forwarded to their private chat with a comment.',
                'create' => 'Add sales manager',
                'edit_title' => 'Edit sales manager',
                'total' => 'Sales managers',
                'name_hint' => 'Must match the name in the «Ответственный (Sales):» line of the penalty',
                'dm_enabled_hint' => 'CRM penalties are forwarded to their private chat with a comment',
                'empty_title' => 'No sales managers yet',
                'empty_description' => 'Sales managers are created automatically from the CRM penalties. Add one by hand to fill in their Telegram right away.',
            ],
            'all' => [
                'title' => 'Everyone',
                'description' => 'Operators and sales managers together: everybody the reports and penalties are written to in private.',
                'create' => 'Add a person',
                'edit_title' => 'Edit the person',
                'total' => 'People in total',
                'name_hint' => 'As in the bot message ("Пользователь:" or "Ответственный:")',
                'dm_enabled_hint' => 'Reports and penalties are sent to the person in a private message',
                'empty_title' => 'No people yet',
                'empty_description' => 'People are created from the bot messages by themselves. You can add them by hand too.',
            ],
        ],
    ],

    'penalties' => [
        'title' => 'CRM penalties',
        'description' => 'Penalties the CRM bot posts for requests stuck in a status: who they went to, which comment followed and whether it arrived.',
        'search_placeholder' => 'Request number, name or @username',
        'settings' => [
            'title' => 'Penalty delivery',
            'enabled' => 'Forward penalties',
            'enabled_hint' => 'Each penalty is forwarded to the person responsible. Off - nothing goes out: no forward, no comment. Penalties are still recorded and counted.',
            'comments' => 'Comment after the penalties',
            'comments_hint' => 'One comment follows each batch of penalties. Off - the forward only, no extra message.',
            'on' => 'On',
            'off' => 'Off',
            'saved' => 'Setting saved',
            'failed' => 'Could not save the setting',
            'off_banner' => 'Penalty delivery is off: new penalties are recorded but sent to nobody. Turning it back on does not send the old ones.',
            'comments_off_banner' => 'Comments are off: penalties are forwarded without an extra message.',
            'operation_enabled' => 'Operators',
            'operation_enabled_hint' => 'Penalties naming "Ответственный (Operation)"',
            'sales_enabled' => 'Sales',
            'sales_enabled_hint' => 'Penalties naming "Ответственный (Sales)"',
            'by_role' => 'Who gets them',
        ],
        'off_link' => 'Turn on in settings',
        'delivery_on' => 'Delivery on',
        'delivery_off' => 'Delivery off',
        'comments_off' => 'no comments',
        'level_n' => 'Level :n',
        'open_settings' => 'Settings',
        'stats' => [
            'total' => 'Penalties',
            'sent' => 'Delivered',
            'in_progress' => 'In progress',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'critical' => 'Top level',
            'people' => 'People',
        ],
        'tabs' => [
            'all' => 'All',
            'operation' => 'Operation',
            'sales' => 'Sales',
        ],
        'filters' => [
            'period' => 'Period',
            'period_all' => 'All time',
            'period_today' => 'Today',
            'period_week' => '7 days',
            'period_month' => '30 days',
            'period_custom' => 'Custom',
            'period_from' => 'From',
            'period_to' => 'To',
            'role' => 'Role',
            'status' => 'Status',
            'status_all' => 'All',
            'level' => 'Level',
            'level_all' => 'All',
            'person' => 'Person',
            'person_clear' => 'Show everyone',
        ],
        'statuses' => [
            'pending' => 'Pending',
            'forwarded' => 'Forwarded, comment pending',
            'sent' => 'Delivered',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
        ],
        'reasons' => [
            'outside_hours' => 'Came in outside working hours - not sent',
            'responsible_missing' => 'The penalty names nobody responsible',
            'responsible_unreachable' => 'The person has no Telegram contact or private messages are off',
            'person_penalties_off' => 'Penalties are switched off on the person\'s card',
            'person_comments_off' => 'Forwarded without a comment: switched off on the person\'s card',
            'forward_failed' => 'Telegram refused the forward',
            'comment_failed' => 'The comment was not sent',
            'disabled' => 'Penalty delivery was switched off',
            'comment_disabled' => 'Forwarded without a comment: comments are off',
            'role_disabled' => 'Delivery is off for this role',
            'level_off' => 'This role gets nothing at this level',
            'forward_only' => 'Forward only: no comment at this level',
            'quiet' => 'Forward only: this time without "this one too"',
        ],
        'levels' => [
            '0' => 'Calm',
            '1' => 'Reminder',
            '2' => 'Strict',
            '3' => 'Harsh',
        ],
        'table' => [
            'request' => 'Request',
            'responsible' => 'Responsible',
            'repeat' => 'Repeat',
            'level' => 'Level',
            'status' => 'Status',
            'created' => 'Received',
            'actions' => 'Actions',
            'details' => 'Details',
            'no_responsible' => 'Not named',
            'crm' => 'Open in CRM',
        ],
        'detail' => [
            'title' => 'Penalty for request',
            'message' => 'Bot message',
            'comment' => 'Comment',
            'comment_on_last' => 'One comment goes out per batch - it is stored on the batch\'s strongest penalty.',
            'batch' => 'Penalties in the batch',
            'metrics' => 'Person\'s history',
            'hour_count' => 'Last hour',
            'today_count' => 'Today',
            'week_count' => 'This week',
            'minutes_since_last' => 'Minutes since the previous batch',
            'crm_status' => 'CRM status',
            'time_in_status' => 'Time in status',
            'attempts' => 'Attempts',
            'error' => 'Error',
            'peer' => 'Sent to',
            'forwarded_at' => 'Forwarded',
            'sent_at' => 'Comment',
            'retry_hint' => 'Failures are retried automatically while the penalty is fresh. If the person has no Telegram, fill it in on the people page.',
            'variant' => 'Which text',
            'variant_value' => ':language, :tone',
            'reply' => 'Their reply',
            'reply_unread' => 'not recognised',
            'sales_turn' => 'Sent to sales: the CRM has a carrier price',
            'sales_turn_price' => 'Carrier price',
            'sales_turn_named' => 'The bot named as responsible',
            'reply_unanswered' => 'Not answered: no kind of the auto replies matched it.',
            'nudge' => 'Nudged: nobody answered',
        ],
        'empty' => [
            'title' => 'No penalties',
            'description' => 'Penalties show up once the CRM bot posts into a watched chat.',
        ],
        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the penalties',
        ],
    ],

    'penalty_settings' => [
        'title' => 'Penalty settings',
        'description' => 'What to tell a person on the 1st, 2nd, 3rd and later penalty for one request. The number is the bot\'s own. Texts in two languages, plain and respectful - for people who are older.',
        'back' => 'Back to penalties',
        'sections' => [
            'hours' => 'Working hours',
            'delivery' => 'Delivery',
            'levels' => 'Texts by penalty number',
            'levels_hint' => '"⚠️ Штраф по запросу" is the first time, "🆘 Повторное отправление штрафа №N" the N-th. The last level also covers everything after it.',
            'timing' => 'Timing and retries',
            'placeholders' => 'Placeholders',
            'placeholders_hint' => 'Click to insert into the phrase you edited last.',
            'language' => 'Text language',
            'how' => 'How the text is chosen',
            'how_text' => 'Language and tone come from the person\'s card: Russian for sales and Uzbek for operators by default; "Respectful" is switched on there too. An empty set is filled from the nearest one: the other tone, a lower level, and only as a last resort the other language.',
        ],
        'hours' => [
            'hint' => 'Penalties outside these hours are recorded but sent to nobody. Tashkent time.',
            'from' => 'From',
            'to' => 'To',
            'always' => 'Around the clock',
            'clear' => 'No limit',
        ],
        'languages' => [
            'uz' => 'O\'zbekcha',
            'ru' => 'Русский',
        ],
        'tones' => [
            'plain' => 'Plain',
            'plain_hint' => 'Peers and younger people',
            'respectful' => 'Respectful',
            'respectful_hint' => 'People who are older',
        ],
        'level' => [
            'title' => 'Level :n',
            'name' => 'Name',
            'name_placeholder' => 'e.g. Second time',
            'from' => 'From penalty',
            'from_hint' => 'The number in "Повторное отправление штрафа №N"',
            'first' => 'First penalty',
            'from_summary' => 'Penalty №:n',
            'from_summary_last' => 'Penalty №:n and later',
            'add_phrase' => 'Phrase',
            'remove_phrase' => 'Remove phrase',
            'add' => 'Add level',
            'remove' => 'Remove level',
            'empty' => 'Empty - :fallback is used',
            'fallback_tone' => 'the plain one',
            'fallback_lower' => 'the level below',
            'phrases_count' => ':n phrases',
            'random_hint' => 'With several phrases a random one is picked, never the one the person got last time.',
        ],
        'timing' => [
            'batch_quiet_seconds' => 'Wait for a repeat, sec',
            'batch_quiet_seconds_hint' => 'Penalties for one person within this time, different requests too, get one comment - the strongest one\'s (the highest number).',
            'max_attempts' => 'Delivery attempts',
            'max_attempts_hint' => 'How many times to try when Telegram refuses.',
            'retry_minutes' => 'Retry for, min',
            'retry_minutes_hint' => 'After that the penalty is stale and is not sent.',
        ],
        'placeholders' => [
            'name' => 'Person\'s name',
            'request' => 'Request number',
            'repeat_number' => 'Penalty number',
            'status_limit' => 'Time allowed in status (2 ч / 2 soat)',
            'time_in_status' => 'Time spent in status',
            'crm_status' => 'CRM status',
            'address' => 'How to call them (from the card)',
        ],
        'actions' => [
            'save' => 'Save',
            'saving' => 'Saving...',
            'discard' => 'Discard changes',
            'reset' => 'Restore defaults',
            'reset_confirm' => 'Click again to restore the default settings',
            'dirty' => 'Unsaved changes',
            'customised' => 'Custom settings',
            'default' => 'Default settings',
        ],
        'messages' => [
            'saved' => 'Penalty settings saved',
            'reset' => 'Default settings restored',
            'failed' => 'Could not save',
            'leave' => 'Your changes are not saved. Leave the page?',
        ],
        'validation' => [
            'first_level_phrase' => 'The first level needs at least one phrase',
            'from_order' => 'Level :level must start at penalty №:min or later',
            'fix' => 'Fix the highlighted fields',
        ],
        'roles' => [
            'operation' => 'Operators',
            'sales' => 'Sales',
        ],
        'roles_hint' => 'Operators and sales have levels, texts and delivery rules of their own.',
        'modes' => [
            'all' => 'Forward + comment',
            'forward' => 'Forward only',
            'off' => 'Send nothing',
        ],
        'mode_hint' => 'What this role gets at this level',
        'copy_from' => 'Copy from ":role"',
        'copy_confirm' => 'Click again: the ":role" levels replace these',
        'copied' => 'Copied from ":role". Remember to save.',
    ],

    'personal_answers' => [
        'title' => 'Personal answers',
        'description' => 'One person\'s own voice messages, GIFs and texts: for a penalty, a "good morning", an auto reply',
        'progress' => 'With voice messages: :done of :total',
        'how' => 'Pick a situation and add this person\'s answers. They come up as often as the shared ones (like one more shared phrase) or, with "Only theirs", instead of them. A voice message is .ogg, as Telegram records it; a GIF comes from Telegram or a file.',
        'add' => 'Add',
        'add_placeholder' => 'Situation: a penalty, a greeting, an auto reply...',
        'no_slots' => 'No personal answers yet: the person gets the shared ones.',
        'no_items' => 'Empty: add a text, a voice message or a GIF. An empty situation is not saved.',
        'remove_slot' => 'Remove the situation',
        'remove' => 'Remove',
        'only' => 'Only theirs',
        'only_on' => 'The shared answers of this situation are not sent to them - only these',
        'only_off' => 'Added to the shared ones: they come up as often',
        'add_text' => 'Text',
        'add_voice' => 'Voice message',
        'add_gif' => 'GIF file',
        'text_placeholder' => 'The answer, {address} works too',
        'placeholders_title' => 'Placeholders',
        'placeholders_hint' => 'Go into the text edited last. The request, the times and the status exist for a penalty only.',
        'types' => [
            'text' => 'Text',
            'voice' => '🎤',
            'gif' => 'GIF',
        ],
        'roles' => [
            'operation' => 'Operator',
            'sales' => 'Sales',
        ],
        'languages' => [
            'uz' => 'O\'zbekcha',
            'ru' => 'Русский',
        ],
        'groups' => [
            'penalty' => 'Penalty',
            'greeting' => 'Greeting',
            'reply' => 'Auto reply',
            'silence' => 'Nudge',
            'opening' => 'Greeting before a penalty',
        ],
        'situations' => [
            'penalty' => ':name (from the :from.)',
            'level' => 'Level from the :n.',
            'greeting' => 'Greeting :n',
            'reply' => 'Kind :n',
            'silence' => 'After ignored penalties',
            'opening' => 'Before the day\'s first penalty',
            'gone' => 'This situation is gone',
            'gone_hint' => 'The kind, greeting or level was removed in the settings: these answers are not sent. Remove them or keep them for its return.',
        ],
        'people' => [
            'search' => 'Name or @username',
            'role' => 'Role',
            'all' => 'Everyone',
            'without_voice' => 'No voice message',
            'none' => 'Nobody found',
        ],
        'person' => [
            'respectful' => 'formal',
            'plain' => 'informal',
            'address' => 'called ":address"',
            'muted' => 'Private messages are off on their card: nothing is sent.',
        ],
        'empty' => [
            'title' => 'Pick a person on the left',
            'description' => 'An operator who got a voice message with their name for the first penalty, say.',
        ],
        'placeholders' => [
            'address' => 'How to call them (from the card)',
            'name' => 'The person\'s name',
            'request' => 'The request',
            'repeat_number' => 'Which time the penalty is sent',
            'status_limit' => 'Time allowed in the status',
            'time_in_status' => 'Time in the status',
            'crm_status' => 'The CRM status',
        ],
        'actions' => [
            'dirty' => 'Unsaved changes: :name',
            'save' => 'Save',
            'saving' => 'Saving...',
            'discard' => 'Discard',
        ],
        'messages' => [
            'saved' => 'Personal answers saved',
            'failed' => 'Could not load',
            'leave' => 'There are unsaved changes. Leave without saving?',
        ],
        'validation' => [
            'slot' => 'Unknown situation: :slot',
        ],
    ],

    'auto_replies' => [
        'title' => 'Auto replies',
        'description' => 'What an operator or a sales manager is told when they write to the bot\'s account in private: "+", "ok", "хоп" -> "Rahmat, Ali aka". Everything is kept in one JSON file and applies to the next message at once.',
        'sections' => [
            'kinds' => 'What they write, what we answer',
            'kinds_hint' => 'Kinds are tried top to bottom; the first whose keyword is in the message wins. The answer comes in the person\'s language and tone, from their card.',
            'language' => 'Answer language',
            'behaviour' => 'When to answer',
            'placeholders' => 'Placeholders',
            'placeholders_hint' => 'Click to insert into the answer you edited last.',
            'test' => 'Try a message',
            'file' => 'File',
            'silence' => 'When they stay silent',
            'opening' => 'Greeting before a penalty',
            'follow_up' => '"This one too"',
            'greetings' => 'Greetings',
        ],
        'tones' => [
            'plain' => 'Plain',
            'plain_hint' => 'Peers and younger people',
            'respectful' => 'Respectful',
            'respectful_hint' => 'People who are older',
        ],
        'kind' => [
            'title' => 'Kind :n',
            'name' => 'Name',
            'keywords' => 'What they write',
            'keywords_hint' => 'Separated by commas or new lines. A "*" at the end takes any ending: клиент* - клиента, клиентом. Case, apostrophes (bo\'ldi = boldi), ё/е, ў/у, қ/к and stretched letters (okkk, ++) do not matter.',
            'answers' => 'What we answer',
            'add_answer' => 'Answer',
            'remove_answer' => 'Remove answer',
            'empty' => 'Empty - the other tone, then the other language is used',
            'add' => 'Add kind',
            'remove' => 'Remove kind',
            'move_up' => 'Move up',
            'move_down' => 'Move down',
            'none' => 'No kinds: nothing is answered.',
            'random_hint' => 'The answer is picked at random among the kind\'s texts, GIFs and voice messages.',
            'max_words' => 'Up to words',
            'max_words_hint' => 'Empty - :n, as in the settings. More for kinds that expect an explanation.',
        ],
        'fields' => [
            'enabled' => 'Answer private messages',
            'enabled_hint' => 'Off: messages are still kept on the penalties, nobody is answered.',
            'scope_any' => 'Any message of theirs',
            'scope_any_hint' => 'An operator or a sales manager writes "ok" - they are answered, penalty or not.',
            'scope_penalty' => 'Only a reply to a penalty',
            'scope_penalty_hint' => 'Answered only when they write soon after a penalty was forwarded to them.',
            'penalty_window_minutes' => 'A penalty\'s reply, min',
            'penalty_window_minutes_hint' => 'A message this soon after a penalty is its reply: kept on it and answered once.',
            'cooldown_minutes' => 'Pause between answers, min',
            'cooldown_minutes_hint' => 'Not answered again in this time, so "ok", "ok", "ok" gets one "thanks". 0 - no pause.',
            'max_words' => 'At most words',
            'max_words_hint' => 'A longer message is a conversation, not an "ok": no answer.',
        ],
        'silence' => [
            'enabled' => 'Nudge when nobody answers',
            'enabled_hint' => 'Someone ignoring the penalties: after this many in a row without a word back, a nudge. In working hours; the count starts over after it.',
            'after_penalties' => 'After unanswered penalties',
            'after_penalties_hint' => 'Any private message of theirs resets the count.',
            'answers' => 'The nudge',
        ],
        'opening' => [
            'enabled' => 'Greet first',
            'enabled_hint' => 'Before the day\'s first penalty to a person - "Assalomu alaykum, Ali aka" as its own message first, the penalty a couple of seconds later. Not when they greeted us first today; their "Va alaykum assalom" is not answered.',
            'answers' => 'The greeting',
        ],
        'follow_up' => [
            'enabled' => 'Do not repeat the same thing',
            'enabled_hint' => 'When the person got a comment of the same level a moment ago ("update the status"), the next penalty goes with a short "This one too" / "And this", a burst with "Don\'t forget these too". Reads like a person.',
            'window_minutes' => 'A moment ago is minutes',
            'window_minutes_hint' => 'Counted from the last comment to this person.',
            'one' => 'One penalty',
            'many' => 'Several at once',
        ],
        'greetings' => [
            'enabled' => 'Greet back',
            'enabled_hint' => '"Good morning. Done" - first "Good morning, Anna!" in a reply of its own, then the kind\'s answer a couple of seconds later. A greeting alone is greeted back too; a greeting before a question is not answered. Once a day per person.',
            'title' => 'Greeting :n',
            'add' => 'Add a greeting',
            'remove' => 'Remove the greeting',
            'none' => 'No greetings: nobody is greeted back.',
            'order_hint' => 'Tried top to bottom: the first greeting found in the message picks the answer. A text, a GIF or a voice message - one at random.',
            'fillers' => 'Words that do not count after a greeting',
            'fillers_hint' => '"Assalomu alaykum aka 🙂" is a greeting alone: it is greeted back. Commas or new lines between them.',
        ],
        'media' => [
            'title' => 'GIFs and voice messages',
            'gifs' => 'GIFs',
            'gif_hint' => 'For every language · from Telegram or .gif, .mp4',
            'voices' => 'Voice messages: :language',
            'voice_hint' => 'This language only · .ogg',
            'add_gif' => 'GIF',
            'add_voice' => 'Voice message',
            'remove' => 'Remove',
            'uploading' => 'Uploading...',
            'failed' => 'The file was not uploaded',
            'add_telegram' => 'From Telegram',
            'file_badge' => 'File',
            'file_badge_hint' => 'Uploaded as a file: may arrive as a video',
            'telegram_badge' => 'Telegram',
            'telegram_badge_hint' => 'A GIF from Telegram: arrives as a real GIF',
            'telegram_title' => 'GIFs from Telegram',
            'telegram_search' => 'Search GIFs: ok, thanks, thumbs up',
            'telegram_search_hint' => 'The same search as the GIF tab in Telegram. An empty search shows the account\'s saved GIFs.',
            'telegram_saved' => 'Saved GIF',
            'telegram_searching' => 'Searching Telegram...',
            'telegram_empty' => 'Nothing found',
            'telegram_more' => 'More',
            'telegram_timeout' => 'The listener did not answer - the search goes through it. Is it running?',
            'telegram_failed' => 'Telegram did not answer: :error',
            'telegram_gone' => 'This GIF cannot be found any more - search again',
            'telegram_pick' => 'Pick',
        ],
        'placeholders' => [
            'address' => 'How to call them (from the card)',
            'name' => 'Person\'s name',
            'request' => 'The penalty\'s request ("—" without one)',
        ],
        'test' => [
            'placeholder' => 'What they might write',
            'match' => 'Kind: :kind',
            'none' => 'Nothing matches - no answer',
            'too_long' => 'Longer than :n words - no answer',
            'answer' => 'They get',
            'as' => 'As',
            'media' => 'Plus GIFs: :gifs, voice messages: :voices - they may come up too',
            'greeting' => 'Greeting: :greeting - greeted back first',
            'greeting_only' => 'Greeting: :greeting - only greeted back',
            'greeted_once' => 'Once a day: a person greeted today gets only the kind\'s answer',
        ],
        'file' => [
            'customised' => 'Saved to the file',
            'default' => 'Defaults - no file yet',
            'download' => 'Download JSON',
            'reset' => 'Back to defaults',
            'reset_confirm' => 'Click again: the file is deleted',
            'broken' => 'The file cannot be read (:error). Nothing is answered until it is fixed by hand or saved again from here.',
        ],
        'actions' => [
            'save' => 'Save',
            'saving' => 'Saving...',
            'discard' => 'Discard changes',
            'dirty' => 'Unsaved changes',
        ],
        'messages' => [
            'saved' => 'Saved to the file',
            'reset' => 'The file is deleted, the defaults are back',
            'failed' => 'Something went wrong',
            'leave' => 'There are unsaved changes',
        ],
        'validation' => [
            'keywords' => 'A kind needs at least one keyword',
            'answers' => 'A kind needs at least one answer: a text, a GIF or a voice message',
            'fix' => 'Fix the highlighted fields',
            'silence' => 'A nudge that is on needs at least one text, GIF or voice message',
            'opening' => 'A greeting that is on needs at least one text, GIF or voice message',
            'follow_up' => 'A "This one too" that is on needs at least one text for a single penalty',
            'media_gif' => 'A GIF is a .gif or .mp4 file',
            'media_gif_sound' => 'This .mp4 has a sound track: Telegram would show it as a video, not a GIF. Upload one without sound',
            'media_voice' => 'A voice message is an .ogg (Opus) file, as Telegram records it',
            'greeting_keywords' => 'A greeting needs at least one keyword',
            'greeting_answers' => 'A greeting needs at least one answer: a text, a GIF or a voice message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watched chats
    |--------------------------------------------------------------------------
    |
    | The groups the listener follows. The list may hold one chat, several or
    | none at all; it is read by the running listener, not by the panel.
    |
    */

    'chats' => [

        'title' => 'Watched chats',

        'description' => 'The groups the driver-check listener follows. None, one or several.',

        'notice' => 'Only the listener can resolve a link, so a new chat needs up to a minute before it is watched.',

        'create' => 'Add chat',

        'search_placeholder' => 'Link, name or chat ID',

        'stats' => [
            'total' => 'Chats',
            'active' => 'Enabled',
            'watching' => 'Watching',
            'failing' => 'Errors',
        ],

        'filters' => [
            'status' => 'Status',
            'status_all' => 'All',
            'status_active' => 'Enabled',
            'status_inactive' => 'Paused',
            'resolved' => 'Resolution',
            'resolved_all' => 'All',
            'resolved_yes' => 'Resolved',
            'resolved_no' => 'Pending',
        ],

        'table' => [
            'chat' => 'Chat',
            'peer' => 'Chat ID',
            'status' => 'Status',
            'checks' => 'Checks',
            'counts' => 'Counts',
            'penalties' => 'Penalties',
            'files' => 'Files',
            'last_message' => 'Last message',
            'actions' => 'Actions',
            'no_link' => 'Added as an ID',
            'no_id' => 'Not resolved yet',
            'never' => 'Nothing yet',
            'edit' => 'Edit',
            'source_env' => 'From .env',
        ],

        'status' => [
            'watching' => 'Watching',
            'pending' => 'Resolving',
            'failed' => 'Error',
            'paused' => 'Paused',
        ],

        'form' => [
            'create_title' => 'New chat',
            'edit_title' => 'Edit chat',
            'chat' => 'Link or chat ID',
            'chat_hint' => 'An invite link (t.me/+...), a @username, or a numeric id (-100...)',
            'title' => 'Name',
            'title_hint' => 'Panel only. Left empty, the listener fills it in from Telegram',
            'is_active' => 'Watch this chat',
            'is_active_hint' => 'Turned off, the listener ignores the group without forgetting it',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving...',
            'delete' => 'Remove',
        ],

        'validation' => [
            'format' => 'Enter a t.me link, a @username or a numeric chat id',
            'duplicate' => 'This chat is already on the list',
        ],

        'confirm' => [
            'delete_title' => 'Remove this chat?',
            'delete_text' => 'The listener stops watching it. The checks it already produced are kept.',
            'delete' => 'Remove',
            'cancel' => 'Cancel',
        ],

        'messages' => [
            'created' => 'Chat added',
            'updated' => 'Changes saved',
            'deleted' => 'Chat removed',
        ],

        'deleted' => 'Chat removed',

        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the chats',
            'load_failed' => 'Could not load the chats',
            'save' => 'Could not save the chat',
            'delete' => 'Could not remove the chat',
            'resolve' => 'Resolution error',
        ],

        'empty' => [
            'title' => 'No chats yet',
            'description' => 'Add the group whose driver messages should be checked. Without one the listener runs but stays silent.',
        ],
    ],

    'listeners' => [
        'title' => 'Listeners',
        'description' => 'The accounts that run a process of their own next to Driver Check: CEO, Head Operation and others. Each has its own set of duties.',
        'notice' => 'One account, one process: only it may hold the Telegram session, so these accounts are not used for number checks, mailings and contact sync. You choose the name and the key yourself. All the accounts are started with one button on the "Processes" page; a listener switched on here is picked up by itself.',
        'owners' => [
            'title' => 'Who does what',
            'description' => 'Each duty is done by one account. Driver Check does everything by default; pick another account and the duty moves to it within a few seconds.',
            'fixed' => [
                'name' => 'Reading the groups and the check reports',
                'hint' => 'Reads the groups and records the driver checks and the penalties from the group. Stays with Driver Check',
            ],
            'duties' => [
                'penalty_intake' => [
                    'name' => 'Penalty intake',
                    'hint' => 'Reads the groups the bot posts penalties into and records them. Whoever is chosen below (by role) delivers them. The account must be a member of those groups',
                ],
                'penalties' => [
                    'name' => 'Penalties',
                    'hint' => 'Forwarding a penalty to the person responsible, the comment after a burst, the nudge and the answers to their private replies. The account must be in the groups the penalties are forwarded from',
                ],
                'file_routes' => [
                    'name' => 'Files from the bots',
                    'hint' => 'Forwarding the notifications bot\'s morning files into the groups by kind. The account must be in the chat with the bot',
                ],
            ],
            'roles' => [
                'title' => 'Penalties by role',
                'hint' => 'The sales managers\' penalties can go out from the head of sales\' account and the operators\' from the head of operations\'. That account also answers their private replies. "Default" follows the "Penalties" duty above.',
                'default' => 'Default (as "Penalties")',
                'now' => 'Sent now by:',
                'names' => [
                    'sales' => 'Sales managers (Sales)',
                    'operation' => 'Operators (Operation)',
                ],
            ],
            'files' => [
                'name' => 'Files by kind',
                'hint' => 'Each kind of file has its own sender account and its own groups',
                'link' => 'Open',
            ],
            'scheduled' => [
                'name' => 'Scheduled messages',
                'hint' => 'The sender is chosen for each message separately',
                'link' => 'Open',
            ],
            'fallback' => 'The chosen listener is off or has no account: Driver Check does the duty meanwhile.',
            'idle' => 'This listener\'s process is not running: the duty is not done until it starts.',
            'saved' => 'Saved',
        ],

        'processes' => [
            'title' => 'Processes',
            'running' => 'The watchdog is running',
            'stopped' => 'The watchdog is not running',
            'start' => 'Start all',
            'restart_all' => 'Restart all',
            'stop' => 'Stop all',
            'restart_one' => 'Restart',
            'hint' => 'One watchdog starts and watches every enabled listener and Driver Check. A listener you switch on below is picked up by itself within seconds. "Stop all" stops everything this watchdog started.',
            'no_worker' => 'The queue worker is not running, so "Start all" will not work. Start the watchdog on the server once:',
            'moved' => 'Starting, restarting and stopping all the sessions is on the "Processes" page.',
            'moved_link' => 'Open',
            'manage' => 'Set up listeners and accounts',
            'none' => 'No listeners yet.',
            'accounts_running' => ':running of :total running',
            'no_account' => 'No account chosen',
            'driver_check_details' => 'Driver Check: details',
            'open_sessions' => 'Open sessions',
            'watchdog_up' => 'Watchdog is running',
            'watchdog_down' => 'Watchdog is not running',
            'last_error' => 'Last start error',
            'summary' => [
                'loading' => ['title' => 'Loading…', 'hint' => ''],
                'empty' => ['title' => 'No accounts switched on', 'hint' => 'Switch a listener on on the Listeners page or start Driver Check.'],
                'all' => ['title' => 'Every account is running', 'hint' => ':running of :total running.'],
                'partial' => ['title' => 'Not every account is running', 'hint' => ':running of :total running. The reason is shown on the account below.'],
                'none' => ['title' => 'Nothing is running', 'hint' => '0 of :total running. Press "Start all".'],
            ],
            'blockers' => [
                'no_account' => 'The listener has no account chosen.',
                'not_authorized' => 'The account is not authorized: log in to it on the sessions page.',
                'no_session' => 'The server has no session file for this account: authorize it again on the sessions page.',
            ],
            'confirm' => [
                'stop_title' => 'Stop everything?',
                'stop_text' => 'All Telegram processes will be stopped, Driver Check included: messages from the groups will not be processed until you press "Start all".',
                'restart_title' => 'Restart all?',
                'restart_text' => 'Every account restarts: message processing breaks for a few seconds.',
                'confirm' => 'Confirm',
            ],
            'exit_code' => 'the process ended with code :code and no message',
            'tried' => 'The watchdog started this account :when but the process is not running and left no error. Run it by hand on the server: php artisan telegram:listener <key> - the reason shows there.',
            'never_tried' => 'The watchdog has not tried to start this account yet. It is most likely running an old version of the code: press "Stop all", then "Start all".',
            'start_timeout' => 'The start did not happen within 20 seconds. It goes through the queue: check that a queue worker (queue:work or queue:listen) is running the telegram queue, and look at storage/logs/listeners-watchdog.log.',
            'messages' => [
                'done' => 'Done',
                'started' => 'Started, everything comes up in a few seconds',
                'start_queued' => 'Start queued, everything comes up in a few seconds',
                'already_running' => 'The watchdog is already running',
                'restart_requested' => 'Restart requested, the processes come back within seconds',
                'stop_requested' => 'Stop requested',
                'not_running' => 'The watchdog is not running: press "Start all"',
                'unknown' => 'No such listener',
            ],
        ],

        'create' => 'Add listener',
        'table' => [
            'account' => 'Account',
            'duties' => 'Duties',
            'seen' => 'Last activity',
            'edit' => 'Edit',
            'no_account' => 'No account chosen',
            'no_duties' => 'Does nothing',
            'never' => 'Not yet',
            'run_hint' => 'Not running. Start it with the "Start all" button on the "Processes" page.',
        ],
        'state' => [
            'running' => 'Running',
            'starting' => 'Starting',
            'stopped' => 'Not running',
            'off' => 'Off',
        ],
        'duties' => [
            'scheduled_messages' => 'Scheduled messages',
        ],
        'duty_hints' => [
            'scheduled_messages' => 'Writes the messages that have this account chosen into their groups',
        ],
        'form' => [
            'create_title' => 'New listener',
            'edit_title' => 'Edit listener',
            'key' => 'Key',
            'key_hint' => 'Latin letters, digits and "_". Names the process and does not change later',
            'name' => 'Name',
            'account' => 'Account',
            'account_hint' => 'Accounts from Telegram sessions that nobody has taken yet. The Driver Check account is not shown',
            'no_account' => 'None',
            'duties' => 'Duties',
            'is_enabled' => 'On',
            'is_enabled_hint' => 'A listener that is off stops, and its account is free for the other processes again',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving...',
            'delete' => 'Delete',
        ],
        'confirm' => [
            'delete_title' => 'Delete the listener?',
            'delete_text' => 'The process stops, and its scheduled messages move to the Driver Check account.',
            'delete' => 'Delete',
            'cancel' => 'Cancel',
        ],
        'messages' => [
            'created' => 'Listener added',
            'updated' => 'Changes saved',
            'deleted' => 'Listener deleted',
            'copied' => 'Command copied',
        ],
        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the listeners',
            'save' => 'Could not save',
            'delete' => 'Could not delete',
            'driver_check_account' => 'This is the Driver Check account, it has its own process',
        ],
        'empty' => [
            'title' => 'No listeners yet',
            'description' => 'Add the CEO or Head Operation account: log it in on the Telegram sessions page first.',
        ],
    ],

    'scheduled' => [
        'title' => 'Scheduled messages',
        'description' => 'The listener account writes into the chosen groups by itself at the set time, for example "Отчет эсдан чикмасин" every day at 17:00.',
        'notice' => 'Messages go out from the listener account, which must be a member of the groups. The time is the server time. If the listener was down, the message goes out when it starts, but no later than 2 hours after its time.',
        'create' => 'Add message',
        'table' => [
            'groups' => 'Groups',
            'next' => 'Next send',
            'last' => 'Last send',
            'edit' => 'Edit',
            'send_now' => 'Send now',
            'sender' => 'From',
            'no_groups' => 'No groups chosen',
            'never' => 'Not sent yet',
            'queued' => 'Sending…',
        ],
        'status' => [
            'active' => 'On',
            'paused' => 'Off',
        ],
        'last_status' => [
            'sent' => 'Sent',
            'partial' => 'Partly',
            'failed' => 'Failed',
        ],
        'next' => [
            'off' => 'Off',
            'no_groups' => 'No groups',
        ],
        'days' => [
            'every_day' => 'Every day',
            'short' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ],
        'form' => [
            'create_title' => 'New message',
            'edit_title' => 'Edit message',
            'name' => 'Name',
            'name_hint' => 'For the panel only',
            'text' => 'Message text',
            'time' => 'Time',
            'days' => 'Days of the week',
            'groups' => 'Groups',
            'groups_hint' => 'Tick the groups the message goes to. The groups from Files are shown; another can be added by its ID.',
            'add_group' => 'Add',
            'sender' => 'Sent from',
            'sender_hint' => 'Which account writes it. Other accounts appear once a listener has this duty switched on.',
            'group_invalid' => 'A group ID is a number, for example -1004431008937',
            'is_active' => 'Send',
            'is_active_hint' => 'Switched off, the message is not sent',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving...',
            'delete' => 'Delete',
        ],
        'confirm' => [
            'send_title' => 'Send now?',
            'send_text' => 'The message goes to the chosen groups within a few seconds. The schedule does not change.',
            'send' => 'Send',
            'delete_title' => 'Delete the message?',
            'delete_text' => 'It will no longer be sent.',
            'delete' => 'Delete',
            'cancel' => 'Cancel',
        ],
        'messages' => [
            'created' => 'Message added',
            'updated' => 'Changes saved',
            'deleted' => 'Message deleted',
            'queued' => 'Sending started',
        ],
        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the messages',
            'save' => 'Could not save',
            'delete' => 'Could not delete',
            'send' => 'Could not send',
        ],
        'empty' => [
            'title' => 'No messages yet',
            'description' => 'Add the first one, for example "Отчет эсдан чикмасин" at 17:00 to the groups you need.',
        ],
    ],

    'file_routes' => [

        'title' => 'Files',

        'description' => 'The files the notifications bot sends every morning: which kind is forwarded to which groups.',

        'notice' => 'A file\'s kind is found by a keyword in its caption or file name, kinds are tried top to bottom. The listener account must be a member of the chosen groups, otherwise Telegram refuses the forward.',

        'source' => 'Source chat',

        'create' => 'Add kind',

        'table' => [
            'name' => 'Kind',
            'keywords' => 'Keywords',
            'targets' => 'Groups',
            'sent' => 'Sent',
            'last_sent' => 'Last',
            'sender' => 'Sent from',
            'status' => 'Status',
            'actions' => 'Actions',
            'edit' => 'Edit',
            'no_targets' => 'No group chosen',
            'never' => 'Not yet',
        ],

        'status' => [
            'active' => 'On',
            'paused' => 'Off',
        ],

        'recent' => [
            'title' => 'Recent files',
            'description' => 'The last 50 forwards. A refused one is retried every minute (up to 3 times).',
            'time' => 'Time',
            'file' => 'File',
            'route' => 'Kind',
            'target' => 'Group',
            'status' => 'Status',
            'empty' => 'No files yet',
            'no_route' => 'Unknown',
        ],

        'forward_status' => [
            'sent' => 'Sent',
            'failed' => 'Failed',
            'unmatched' => 'No kind',
        ],

        'form' => [
            'create_title' => 'New file kind',
            'edit_title' => 'Edit file kind',
            'name' => 'Name',
            'name_hint' => 'For the panel only',
            'keywords' => 'Keywords',
            'keywords_hint' => 'Comma separated. A file whose caption or name contains one of them is of this kind. Case, dashes and brackets do not matter',
            'targets' => 'Group ids',
            'targets_hint' => 'One per line, e.g. -5524265426 or -1004431008937',
            'sender' => 'Sender account',
            'sender_hint' => 'The account this kind of file goes out from. It must be in the bot\'s chat and in the target groups',
            'sender_default' => 'Default (as "Files from bots")',
            'is_active' => 'Forward',
            'is_active_hint' => 'When off, files of this kind are not sent anywhere',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving...',
            'delete' => 'Delete',
        ],

        'confirm' => [
            'delete_title' => 'Delete this file kind?',
            'delete_text' => 'Such files will no longer be forwarded. The ones sent stay in the journal.',
            'delete' => 'Delete',
            'cancel' => 'Cancel',
        ],

        'messages' => [
            'created' => 'File kind added',
            'updated' => 'Changes saved',
            'deleted' => 'File kind deleted',
        ],

        'validation' => [
            'chat_id' => 'A group id is a number, e.g. -1004431008937',
        ],

        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the file kinds',
            'save' => 'Could not save',
            'delete' => 'Could not delete',
        ],

        'empty' => [
            'title' => 'No file kinds',
            'description' => 'Add them with php artisan db:seed --class=TelegramFileRouteSeeder or the "Add kind" button.',
        ],
    ],

    'sessions' => [

        'title' => 'Telegram sessions',

        'description' => 'Every MadelineProto account the listener and the phone lookup run on: log in, check, log out.',

        'notice' => 'MadelineProto only runs from the console, so every action starts a background command. The status updates by itself within a few seconds.',

        'create' => 'Add account',

        'search_placeholder' => 'Phone, name, @username or Telegram ID',

        'primary' => 'Listener',

        'primary_hint' => 'This account listens to the chats (TELEGRAM_DRIVER_CHECK_ACCOUNT_ID)',

        'stats' => [
            'authorized' => 'Active',
            'pending' => 'Login unfinished',
            'problem' => 'Problems',
            'logged_out' => 'Logged out',
        ],

        'filters' => [
            'state' => 'State',
            'state_all' => 'All',
        ],

        'table' => [
            'account' => 'Account',
            'phone' => 'Phone',
            'status' => 'Status',
            'processes' => 'Processes',
            'last_checked' => 'Checked',
            'authorized_at' => 'Logged in',
            'actions' => 'Actions',
        ],

        'fields' => [
            'telegram_id' => 'Telegram ID',
            'name' => 'Name',
            'username' => 'Username',
            'session_file' => 'Session file',
            'session_file_yes' => 'On disk',
            'session_file_no' => 'Not on disk',
        ],

        'state' => [
            'listening' => 'Listening to chats',
            'stopped' => 'Listener stopped',
            'active' => 'Active',
            'warning' => 'Active, with an error',
            'no_file' => 'Session file missing',
            'sending_code' => 'Sending code',
            'awaiting_code' => 'Waiting for code',
            'verifying' => 'Verifying',
            'awaiting_password' => 'Waiting for 2FA password',
            'checking' => 'Checking',
            'logging_out' => 'Logging out...',
            'stale' => 'Stuck',
            'code_invalid' => 'Wrong code',
            'failed' => 'Login failed',
            'revoked' => 'Session revoked',
            'logged_out' => 'Logged out',
            'new' => 'Not authorized',
        ],

        'state_hint' => [
            'listening' => 'The listener is running and holds this session. It cannot be checked from the panel: its profile is refreshed every time the listener starts.',
            'stopped' => 'The session is authorized, but the listener is not running. Messages from the chats are not processed.',
            'active' => 'The session works and is available to the processes.',
            'warning' => 'The session is authorized, but the last request returned an error. Press "Check" to find out whether it is still alive.',
            'no_file' => 'The account is authorized in the database, but its session file is not on disk: every process will fail on it. Log out and log in again.',
            'sending_code' => 'A background command is asking Telegram for the code.',
            'awaiting_code' => 'Telegram has sent the code. Enter it to continue.',
            'verifying' => 'A background command is checking what you entered.',
            'awaiting_password' => 'Two-step verification is on for this account. Its cloud password is needed.',
            'checking' => 'A background command is asking Telegram whether the session is alive.',
            'logging_out' => 'A background command is ending the session in Telegram and deleting the file.',
            'stale' => 'The background command never answered. It most likely crashed - the action can be repeated.',
            'code_invalid' => 'The code did not match. Telegram does not allow another try with the same login: request a new code.',
            'failed' => 'The login failed. You can start over.',
            'revoked' => 'Telegram no longer accepts this session: it was ended on the phone, or the account is banned. Log in again.',
            'logged_out' => 'The session has ended. The account can be logged in again or deleted.',
            'new' => 'No login has been started yet.',
        ],

        'processes' => [
            'names' => [
                'resolver_phone' => 'Phone lookup',
                'send_message' => 'Mailing',
                'driver_check' => 'Driver check',
                'group_join' => 'Group join',
                'group_invite' => 'Add to groups',
            ],
            'states' => [
                'ready' => 'Ready',
                'busy' => 'Busy',
                'stuck' => 'Stuck',
                'failing' => 'Failing',
                'disabled' => 'Disabled',
            ],
            'none' => 'Not used yet',
            'none_hint' => 'No process has taken this account yet. A row appears the first time a process picks it.',
            'successes' => 'Successes',
            'failures' => 'Failures',
            'streak' => 'In a row',
            'disabled_reason' => 'Reason',
            'stuck_hint' => 'Marked busy :time and never released. Disable and enable the process to clear the flag.',
            'enable' => 'Enable',
            'disable' => 'Disable',
            'disabled_manually' => 'Disabled manually in the panel',
        ],

        'actions' => [
            'continue' => 'Continue login',
            'login_again' => 'Log in again',
            'check' => 'Check',
            'logout' => 'Log out',
            'delete' => 'Delete',
        ],

        'login' => [
            'title' => 'New account',
            'steps' => [
                'phone' => 'Phone',
                'code' => 'Code',
                'password' => '2FA password',
            ],
            'phone' => 'Phone number',
            'phone_hint' => 'International format, with the country code',
            'send_code' => 'Get code',
            'sending' => 'Requesting the code...',
            'verifying' => 'Verifying...',
            'waiting_hint' => 'The command runs in the background. You may close this window - the login continues from the list.',
            'code' => 'Code from Telegram',
            'code_hint' => 'Arrives in the Telegram app on this number (or by SMS)',
            'code_warning' => 'Do not forward the code to anyone, not even to Saved Messages: Telegram invalidates it at once.',
            'password' => 'Cloud password',
            'password_hint' => 'The two-step verification password of this account',
            'hint' => 'Hint',
            'verify' => 'Confirm',
            'resend' => 'Send the code again',
            'done' => 'Account authorized',
        ],

        'confirm' => [
            'logout_title' => 'Log out of this account?',
            'logout_text' => 'The session is ended in Telegram and its file deleted. Processes stop using this account.',
            'logout_primary' => 'This is the listener account: once logged out, the driver check stops receiving messages from the chats.',
            'delete_title' => 'Delete this account?',
            'delete_text' => 'The record and its process statistics are deleted. The account holds no live session, so nothing changes in Telegram.',
        ],

        'messages' => [
            'authorized' => 'Account authorized',
            'check_started' => 'Check started',
            'logout_started' => 'Logout started',
            'deleted' => 'Account deleted',
            'process_enabled' => 'Process enabled',
            'process_disabled' => 'Process disabled',
        ],

        'validation' => [
            'phone' => 'Enter the number in international format, e.g. +998901234567',
            'code' => 'The code consists of digits only',
        ],

        'state_errors' => [
            'listener_running' => 'The listener is running on this session right now. Its profile is refreshed when the listener starts.',
            'process_busy' => 'A process is using this account right now. Try again once it is free.',
            'already_authorized' => 'This account is already authorized.',
            'busy' => 'A background command is already working on this account. Please wait a moment.',
            'not_waiting_code' => 'The account is not waiting for a code. Refresh the page.',
            'not_waiting_password' => 'The account is not waiting for a password. Refresh the page.',
            'not_authorized' => 'The account is not authorized.',
            'still_authorized' => 'Log out of the account before deleting it.',
        ],

        'telegram_errors' => [
            'PHONE_CODE_INVALID' => 'Wrong code. Request a new one.',
            'PHONE_CODE_EXPIRED' => 'The code has expired. Request a new one.',
            'PASSWORD_HASH_INVALID' => 'Wrong password. Try again.',
            'PASSWORD_EXPIRED' => 'The password was not picked up in time. Enter it again.',
            'PHONE_NUMBER_INVALID' => 'Telegram does not accept this number.',
            'PHONE_NUMBER_BANNED' => 'This number is banned in Telegram.',
            'PHONE_NUMBER_FLOOD' => 'Too many login attempts for this number. Try later.',
            'FLOOD_WAIT' => 'Telegram asks to wait before the next attempt.',
            'FLOOD_WAIT_SECONDS' => 'Telegram asks to wait :seconds s.',
            'AUTH_KEY_UNREGISTERED' => 'The session was ended in Telegram.',
            'SESSION_REVOKED' => 'The session was ended in Telegram.',
            'USER_DEACTIVATED' => 'The account is deleted or banned.',
            'SESSION_NOT_FOUND' => 'The session file is not on disk.',
            'NOT_LOGGED_IN' => 'The session on disk is not logged in.',
            'ACCOUNT_NOT_REGISTERED' => 'No Telegram account is registered on this number.',
        ],

        'errors' => [
            'title' => 'Error',
            'load' => 'Could not load the sessions',
            'load_failed' => 'Could not load the sessions',
            'action' => 'Could not complete the action',
        ],

        'empty' => [
            'title' => 'No accounts yet',
            'description' => 'Add a Telegram account: the listener and the phone lookup run on it.',
        ],
    ],

    'contact_sync' => [
        'title' => 'Group contacts',
        'description' => 'Once a day the accounts open the given groups and save the members into their contacts. One person goes to one account only.',
        'actions' => [
            'run' => 'Run now',
            'running' => 'Running…',
        ],
        'errors' => [
            'title' => 'Could not load',
            'load_failed' => 'Could not load the data.',
            'action' => 'The action failed.',
        ],
        'totals' => [
            'users' => 'People found',
            'added' => 'Saved to contacts',
            'failed' => 'Failed',
            'unassigned' => 'Not assigned yet',
            'accounts_needed' => 'in one day: :n accounts at :limit each',
        ],
        'groups' => [
            'title' => 'Groups',
            'add' => 'Add',
            'empty' => 'No groups yet. Paste a link above.',
            'run' => 'Run',
            'pause' => 'Pause',
            'resume' => 'Resume',
            'delete' => 'Delete',
            'people' => 'people',
            'waiting' => 'waiting: :n',
            'waiting_hint' => 'In nobody\'s contacts yet: the sync will save them',
            'not_waiting' => 'not waiting: :n',
            'not_waiting_hint' => 'With an @username (reached by the link, kept in the database), already in an account\'s contacts, bots',
            'in_telegram' => '(in Telegram: :n)',
            'in_telegram_hint' => 'How many members Telegram named when the group was read; it did not list them all',
            'fetch' => 'Fetch people',
            'fetching' => 'Fetching…',
            'fetch_hint' => 'Read who is in the group into the database, without saving anybody into contacts',
            'confirm_delete' => 'Delete the group? The people found stay.',
        ],
        'accounts' => [
            'title' => 'Accounts',
            'hint' => 'Daily limit per account:',
            'account' => 'Account',
            'contacts' => 'Contacts',
            'today' => 'Today',
            'failed' => 'Failed',
            'skipped' => 'Already there',
            'last_added' => 'Last added',
            'primary' => 'main',
            'held' => 'On hold',
            'enable' => 'Enable',
        ],
        'log' => [
            'title' => 'Log',
            'all_events' => 'All events',
            'all_accounts' => 'All accounts',
            'empty' => 'Nothing yet.',
        ],
        'events' => [
            'started' => 'Started',
            'members' => 'Members',
            'added' => 'Added',
            'skipped' => 'Skipped',
            'failed' => 'Failed',
            'flood' => 'Telegram limit',
            'finished' => 'Finished',
            'joined' => 'Joined',
            'disabled' => 'Disabled',
            'stop' => 'Stop pressed',
        ],
        'messages' => [
            'already_running' => 'A sync is already running.',
            'run_queued' => 'Run queued.',
            'not_running' => 'Nothing is running now.',
            'stop_queued' => 'The run is stopping. If it is stuck, the server stops it in 2 minutes.',
            'bad_link' => 'Invalid group link.',
            'group_deleted' => 'Group deleted.',
            'account_enabled' => 'The account is back in the sync.',
            'fetch_queued' => 'The group\'s people are being fetched into the database.',
            'fetch_running' => 'This group\'s people are being fetched already.',
        ],
    ],

    'watchdog' => [

        'title' => 'Processes',

        'description' => 'All Telegram processes: the sessions of every listener under one watchdog, and the Driver Check listener - whether each is up, on which account and what it did last.',

        'actions' => [
            'start' => 'Start',
            'restart' => 'Restart listener',
            'restart_short' => 'Restart',
            'stop' => 'Stop',
        ],

        'state' => [
            'ok' => 'Running',
            'unsupervised' => 'Listener unsupervised',
            'restarting' => 'Listener restarting',
            'down' => 'Stopped',
            'misconfigured' => 'Main account not ready',
            'unknown' => 'State unknown',
        ],

        'state_hint' => [
            'ok' => 'The watchdog is supervising the listener and the main account is authorized.',
            'unsupervised' => 'The listener runs, but no watchdog does: if the listener dies, nothing brings it back. Press "Start".',
            'restarting' => 'The watchdog runs, but the listener does not right now: it has just died or is waiting out a pause before the next attempt.',
            'down' => 'Neither the watchdog nor the listener runs. Messages from the chats are not processed.',
            'misconfigured' => 'TELEGRAM_DRIVER_CHECK_ACCOUNT_ID is not set, or the account is missing or not authorized. Check it on the Telegram sessions page.',
            'unknown' => 'The lock files in storage/app/telegram cannot be read by the web server.',
        ],

        'processes' => [
            'title' => 'Processes',
            'watchdog' => 'Watchdog',
            'watchdog_hint' => 'Starts the listener and brings it back after a crash',
            'spare' => 'Spare watchdog',
            'spare_hint' => 'Waits, and takes over if the main one dies',
            'listener' => 'Listener',
            'listener_hint' => 'telegram:start-loop: listens to the chats as the main account',
            'running' => 'Running',
            'stopped' => 'Not running',
            'unknown' => 'Unknown',
            'pid' => 'PID',
        ],

        'account' => [
            'title' => 'Main account',
            'missing' => 'No account configured',
            'missing_hint' => 'Set TELEGRAM_DRIVER_CHECK_ACCOUNT_ID in .env.',
            'not_found' => 'The account in TELEGRAM_DRIVER_CHECK_ACCOUNT_ID does not exist',
            'authorized' => 'Authorized',
            'not_authorized' => 'Not authorized',
            'session_missing' => 'Session file missing on disk',
            'status' => 'Status',
            'open_sessions' => 'Sessions',
        ],

        'activity' => [
            'title' => 'Activity',
            'last_check' => 'Last message',
            'last_report' => 'Last report',
            'checks_today' => 'Checks today',
            'pending' => 'In progress',
        ],

        'worker' => [
            'title' => 'Queue worker',
            'alive' => 'Responding',
            'dead' => 'Not responding',
            'dead_hint' => '"Start" puts a job on the queue. While the worker does not respond, nobody runs it.',
            'start_queued' => 'Start waiting in the queue: :count',
            'open_queue' => 'Queue',
        ],

        'restart' => [
            'title' => 'Last crash',
            'reason' => 'Reason',
            'exit_code' => 'Exit code',
            'uptime' => 'Ran for',
            'restarts' => 'Restart #',
            'at' => 'When',
            'detail' => 'Details',
            'marker' => 'The listener reported a problem',
        ],

        'signals_unavailable' => 'Restart and stop only work on the Linux server the watchdog runs on.',

        'auto_refresh' => 'Refreshes every 5 seconds',

        'confirm' => [
            'restart_title' => 'Restart the listener?',
            'restart_text' => 'The listener gets SIGTERM and shuts down cleanly; the watchdog starts a new one within seconds. No messages are processed meanwhile.',
            'stop_title' => 'Stop the watchdog and the listener?',
            'stop_text' => 'The watchdog stops the listener, then itself. The driver check stays off until it is started again.',
            'confirm' => 'Yes, continue',
        ],

        'messages' => [
            'restart_queued' => 'Restart handed to the queue worker; the listener restarts within seconds',
            'stop_queued' => 'Stop handed to the queue worker; the watchdog stops within seconds',
            'start_queued' => 'Watchdog start queued',
            'restart_sent' => 'The listener is restarting',
            'stop_sent' => 'The watchdog is stopping',
        ],

        'errors' => [
            'title' => 'Error',
            'load_failed' => 'Could not load the state',
            'action' => 'Could not complete the action',
            'no_watchdog' => 'No watchdog runs: a stopped listener would stay down. Start the watchdog first.',
            'not_running' => 'The process is not running.',
            'pid_unknown' => 'The process PID could not be confirmed. No signal was sent.',
            'signal_failed' => 'No signal sent: the process sending it has no rights over the watchdog. It was most likely started by hand as another user.',
        ],
    ],

    'queue' => [

        'title' => 'Queue',

        'description' => 'The panel\'s background jobs: the bot, the resolver, account logins, mailings. Everything goes through the telegram queue.',

        'worker' => [
            'alive' => 'Worker running',
            'dead' => 'Worker not responding',
            'never' => 'The worker has never reported in',
            'dead_hint' => 'Jobs pile up and nothing runs them. On the server: php artisan queue:work --queue=telegram',
            'never_hint' => 'The pulse appears once the worker restarts with the new code (php artisan queue:restart).',
            'pulse' => 'Last pulse',
            'listens' => 'Listens to',
            'last_processed' => 'Last processed',
            'last_failed' => 'Last failed',
        ],

        'stats' => [
            'ready' => 'Ready',
            'delayed' => 'Delayed',
            'reserved' => 'Running',
            'failed' => 'Failed',
            'failed_day' => 'last 24h: :count',
        ],

        'queues' => [
            'title' => 'Queues',
            'queue' => 'Queue',
            'stuck' => 'Stuck',
            'oldest' => 'Longest wait',
            'main' => 'Main',
            'not_listened' => 'Nobody listens',
            'not_listened_hint' => 'Nobody will run the jobs in this queue. Move them to telegram.',
            'move' => 'Move to telegram',
        ],

        'classes' => [
            'title' => 'Waiting to run',
            'job' => 'Job',
            'total' => 'Total',
            'empty' => 'The queue is empty',
        ],

        'tabs' => [
            'pending' => 'Waiting',
            'failed' => 'Failed',
        ],

        'filters' => [
            'search' => 'Job name',
            'search_failed' => 'Job or error text',
            'queue' => 'Queue',
            'queue_all' => 'All queues',
            'state' => 'State',
            'state_all' => 'All',
        ],

        'state' => [
            'ready' => 'Ready',
            'delayed' => 'Delayed',
            'reserved' => 'Running',
            'stuck' => 'Stuck',
        ],

        'table' => [
            'job' => 'Job',
            'queue' => 'Queue',
            'state' => 'State',
            'attempts' => 'Attempts',
            'created' => 'Queued',
            'available' => 'Runs at',
            'error' => 'Error',
            'failed_at' => 'Failed',
            'actions' => 'Actions',
        ],

        'actions' => [
            'retry' => 'Retry',
            'retry_all' => 'Retry all',
            'delete' => 'Delete',
            'flush' => 'Delete all',
            'details' => 'Details',
        ],

        'detail' => [
            'title' => 'Failed job',
            'exception' => 'Error',
            'uuid' => 'UUID',
        ],

        'confirm' => [
            'move_title' => 'Move the jobs to telegram?',
            'move_text' => 'Every waiting job in the ":queue" queue moves to telegram and gets run by the worker.',
            'retry_all_title' => 'Retry every failed job?',
            'retry_all_text' => 'Every failed job goes back onto the telegram queue. A job that failed on bad data will most likely fail again.',
            'delete_title' => 'Delete this failed job?',
            'delete_text' => 'The job is deleted without being run again.',
            'flush_title' => 'Delete every failed job?',
            'flush_text' => 'Every failed job is deleted without being run again. This cannot be undone.',
            'confirm' => 'Yes, continue',
        ],

        'messages' => [
            'retried' => 'Back on the queue: :count',
            'deleted' => 'Deleted: :count',
            'moved' => 'Moved to telegram: :count',
        ],

        'errors' => [
            'title' => 'Error',
            'load_failed' => 'Could not load the queue',
            'action' => 'Could not complete the action',
            'retry' => 'The job could not be rebuilt: :error',
        ],

        'empty' => [
            'pending_title' => 'No waiting jobs',
            'pending_description' => 'Everything that was queued has run.',
            'failed_title' => 'No failed jobs',
            'failed_description' => 'Every job ran without an error.',
        ],

        'auto_refresh' => 'The summary refreshes every 10 seconds',
    ],

    'operation_users' => [

        'title' => 'Operators',

        'description' => 'Statistics of Telegram users and driver verification',

        'telegram' => 'Telegram',

        'refresh' => 'Refresh',

        'loading' => 'Loading...',

        'open_user' => 'Open user',

        'per_page' => 'Per page',

        'filters' => [

            'title' => 'Filters',

            'description' => 'Search and sort operators',

            'reset' => 'Clear',

            'search' => 'Search',

            'search_placeholder' => 'Name, username, Telegram ID...',

            'telegram_id' => 'Telegram ID',

            'telegram_id_placeholder' => 'Enter Telegram ID',

            'username' => 'Username',

            'username_placeholder' => '@username',

            'sort' => 'Sort',

            'created' => 'Created date',

            'updated' => 'Updated date',

            'name' => 'Name',

            'drivers' => 'Drivers',

            'checks' => 'Checks',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'match_rate' => 'Match rate',

            'average_score' => 'Average score',

            'last_check' => 'Last check',

            'ascending' => 'Ascending',

            'descending' => 'Descending',

            'apply' => 'Search',

            'more_filters' => 'More filters',

            'less_filters' => 'Hide filters',

            'status' => 'Check status',

            'status_all' => 'Any status',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'processing' => 'Processing',

            'period' => 'Period',

            'period_today' => 'Today',

            'period_week' => '7 days',

            'period_month' => '30 days',

            'period_custom' => 'Custom range',

            'period_all' => 'All time',

            'period_from' => 'From',

            'period_to' => 'To',

            'score_range' => 'Match score',

            'score_from' => 'From',

            'score_to' => 'To',

            'has_telegram' => 'Has Telegram',

            'has_driver' => 'Has driver',

            'any' => 'Any',

            'yes' => 'Yes',

            'no' => 'No',
        ],

        'export' => [

            'title' => 'Export to Excel',

            'operators' => 'Operators summary',

            'details' => 'Detailed checks',

            'hint' => 'Export respects the current filters and period.',
        ],

        'stats' => [

            'users' => 'Operators',

            'drivers' => 'Drivers',

            'checks' => 'Checks',

            'avg_match' => 'Average match',
        ],

        'table' => [

            'user' => 'Operator',

            'telegram' => 'Telegram',

            'drivers' => 'Drivers',

            'checks' => 'Checks',

            'result' => 'Result',

            'match' => 'Match',

            'score' => 'Score',

            'id' => 'ID',

            'best' => 'Best',

            'last_check' => 'Last check',

            'no_username' => 'Username not available',

            'no_telegram_id' => 'Telegram ID not available',
        ],

        'result' => [

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'processing' => 'Processing',
        ],

        'empty' => [

            'title' => 'No operators found',

            'description' => 'Try changing the filter or search query.',
        ],

        'pagination' => [

            'showing' => 'Showing',

            'page' => 'Page',

            'of' => 'of',

            'previous' => 'Previous',

            'next' => 'Next',
        ],

        'errors' => [

            'load_failed' => 'Failed to load operators',

            'unknown' => 'An unknown error occurred',
        ],

        'dates' => [

            'never' => 'Never',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation User
    |--------------------------------------------------------------------------
    */

    'operation_user' => [

        'title' => 'Operator',

        'back' => 'Operators',

        'refresh' => 'Refresh',

        'loading' => 'Loading...',

        'id' => 'ID',

        'no_username' => 'Username not available',

        'unknown' => 'Unknown',

        /*
        |--------------------------------------------------------------------------
        | Operator stats
        |--------------------------------------------------------------------------
        */

        'stats' => [

            'drivers' => 'Drivers',

            'checks' => 'Checks',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'processing' => 'Processing',

            'match_rate' => 'Match rate',
        ],

        /*
        |--------------------------------------------------------------------------
        | Driver filters
        |--------------------------------------------------------------------------
        */

        'filters' => [

            'title' => 'Filters',

            'all' => 'All time',

            'last_week' => '7 days',

            'last_month' => '30 days',

            'from' => 'From',

            'to' => 'To',

            'clear' => 'Clear',

            'status' => 'Status',

            'status_all' => 'Any status',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'processing' => 'Processing',
        ],

        'export' => [

            'button' => 'Export to Excel',

            'hint' => 'Export respects the current filters and period.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Drivers
        |--------------------------------------------------------------------------
        */

        'drivers' => [
            'phone' => 'Phone',

            'title' => 'Drivers',

            'per_page' => 'Per page',

            'phones' => 'phone',

            'checks' => 'check',

            'show_phones' => 'Show phones',

            'hide_phones' => 'Hide phones',

            'no_drivers' => 'No drivers available',

            'no_drivers_description' => 'No drivers have been assigned to this operator yet.',

            'no_resolved_phones' => 'No linked phone numbers available',

            'no_username' => 'Username not available',

            'unknown_date' => 'Date unknown',

            /*
            |--------------------------------------------------------------------------
            | Driver statuses
            |--------------------------------------------------------------------------
            */

            'confirmed' => 'Confirmed',

            'confirmed_description' => 'Driver information has been confirmed.',

            'not_confirmed' => 'Not confirmed',

            'not_confirmed_description' => 'Driver information has not been confirmed.',

            'pending' => 'Pending',

            'pending_description' => 'Waiting for the driver to be confirmed.',

            'unknown_description' => 'Driver status is unknown.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        'pagination' => [

            'page' => 'Page',

            'of' => 'of',

            'previous' => 'Previous',

            'next' => 'Next',
        ],

        /*
        |--------------------------------------------------------------------------
        | Errors
        |--------------------------------------------------------------------------
        */

        'errors' => [

            'load_operator' => 'Failed to load operator',

            'load_drivers' => 'Failed to load drivers',

            'unknown' => 'An unknown error occurred',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers (list page)
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'title' => 'Drivers',

        'description' => 'All drivers checked via Telegram, across all operators',

        'refresh' => 'Refresh',

        'loading' => 'Loading...',

        'filters' => [

            'title' => 'Filters',

            'description' => 'Search and sort drivers',

            'reset' => 'Clear',

            'search' => 'Search',

            'search_placeholder' => 'Driver name...',

            'sort' => 'Sort',

            'created' => 'Created date',

            'name' => 'Name',

            'checks' => 'Checks',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'best_match_score' => 'Best score',

            'last_check' => 'Last check',

            'ascending' => 'Ascending',

            'descending' => 'Descending',

            'apply' => 'Search',

            'status' => 'Driver status',

            'status_all' => 'Any status',

            'confirmed_status' => 'Confirmed',

            'not_confirmed_status' => 'Not confirmed',

            'pending_status' => 'Pending',

            'check_status' => 'Check status',

            'period' => 'Period',

            'period_today' => 'Today',

            'period_week' => '7 days',

            'period_month' => '30 days',

            'period_custom' => 'Custom range',

            'period_all' => 'All time',

            'period_from' => 'From',

            'period_to' => 'To',

            'score_range' => 'Match score',

            'score_from' => 'From',

            'score_to' => 'To',
        ],

        'stats' => [

            'total' => 'Drivers',

            'checks' => 'Checks',

            'confirmed' => 'Confirmed',

            'avg_match' => 'Average match',
        ],

        'table' => [

            'driver' => 'Driver',

            'operator' => 'Operator',

            'status' => 'Status',

            'checks' => 'Checks',

            'result' => 'Result',

            'score' => 'Score',

            'last_check' => 'Last check',

            'phones' => 'Phones',

            'id' => 'ID',

            'no_operator' => 'No operator',
        ],

        'status' => [

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'pending' => 'Pending',

            'unknown' => 'Unknown',
        ],

        'export' => [

            'button' => 'Export to Excel',

            'hint' => 'Export respects the current filters and period.',
        ],

        'empty' => [

            'title' => 'No drivers found',

            'description' => 'Try changing the filter or search query.',
        ],

        'pagination' => [

            'showing' => 'Showing',

            'page' => 'Page',

            'of' => 'of',

            'previous' => 'Previous',

            'next' => 'Next',
        ],

        'errors' => [

            'load_failed' => 'Failed to load drivers',

            'unknown' => 'An unknown error occurred',
        ],

        'dates' => [

            'never' => 'Never',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolved phones (list page)
    |--------------------------------------------------------------------------
    */

    'resolved_phones' => [

        'title' => 'Telegram numbers',

        'description' => 'All phone numbers resolved via Telegram, with their check results',

        'refresh' => 'Refresh',

        'loading' => 'Loading...',

        'filters' => [

            'title' => 'Filters',

            'description' => 'Search and sort resolved numbers',

            'reset' => 'Clear',

            'search' => 'Search',

            'search_placeholder' => 'Phone, username, name...',

            'sort' => 'Sort',

            'created' => 'Created date',

            'resolved' => 'Resolved date',

            'phone' => 'Phone',

            'checks' => 'Checks',

            'confirmed' => 'Confirmed',

            'not_confirmed' => 'Not confirmed',

            'ascending' => 'Ascending',

            'descending' => 'Descending',

            'apply' => 'Search',

            'has_username' => 'Has username',

            'has_driver' => 'Linked to driver',

            'any' => 'Any',

            'yes' => 'Yes',

            'no' => 'No',

            'stale' => 'Stale (> 7 days)',

            'period' => 'Period',

            'period_today' => 'Today',

            'period_week' => '7 days',

            'period_month' => '30 days',

            'period_custom' => 'Custom range',

            'period_all' => 'All time',

            'period_from' => 'From',

            'period_to' => 'To',
        ],

        'stats' => [

            'total' => 'Numbers',

            'with_username' => 'With username',

            'with_driver' => 'Linked to a driver',

            'checks' => 'Checks',
        ],

        'table' => [

            'phone' => 'Phone',

            'telegram' => 'Telegram',

            'driver' => 'Driver',

            'operator' => 'Operator',

            'account' => 'Resolver account',

            'resolved_at' => 'Resolved',

            'checks' => 'Checks',

            'result' => 'Result',

            'id' => 'ID',

            'no_username' => 'Username not available',

            'no_driver' => 'No driver',
        ],

        'export' => [

            'button' => 'Export to Excel',

            'hint' => 'Export respects the current filters and period.',
        ],

        'empty' => [

            'title' => 'No numbers found',

            'description' => 'Try changing the filter or search query.',
        ],

        'pagination' => [

            'showing' => 'Showing',

            'page' => 'Page',

            'of' => 'of',

            'previous' => 'Previous',

            'next' => 'Next',
        ],

        'errors' => [

            'load_failed' => 'Failed to load numbers',

            'unknown' => 'An unknown error occurred',
        ],

        'dates' => [

            'never' => 'Never',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | How it works (driver-check/system)
    |--------------------------------------------------------------------------
    */
    'system' => [
        'title' => 'How it works',
        'description' => 'Every independent piece of the Telegram side, which account does it now, and the problems found so far.',
        'count' => 'Independent logics: :total, of which can be handed to another account: :movable.',
        'count_hint' => 'Each works on its own: a failure or a move of one does not stop the others.',
        'movable' => 'Can be redirected',
        'fixed' => 'Fixed',
        'done_by' => 'Done by:',
        'own_accounts' => 'Its own accounts',
        'change' => 'Change',
        'open' => 'Open',
        'errors' => [
            'title' => 'Could not load the page',
            'load' => 'Could not load the data',
        ],
        'state' => [
            'running' => 'Running',
            'stopped' => 'Not running',
            'off' => 'Off',
        ],
        'logics' => [
            'driver_checks' => [
                'name' => 'Driver checks',
                'what' => 'Reads the groups, records the driver and transport messages, matches the results and warns when there are not enough accounts to check with.',
                'note' => 'Stays with Driver Check and cannot be moved.',
            ],
            'penalty_intake' => [
                'name' => 'Penalty intake',
                'what' => 'Reads the groups the bot posts CRM penalties into, records the penalty, finds the person responsible and their role.',
                'note' => 'The account must be a member of those groups.',
            ],
            'penalties_sales' => [
                'name' => 'Sales penalties (Sales)',
                'what' => 'Forwards the penalty to the sales manager, writes the comment after a burst, nudges the silent ones and answers their private replies.',
                'note' => '',
            ],
            'penalties_operation' => [
                'name' => 'Operator penalties (Operation)',
                'what' => 'The same for operators: forwarding, the comment after a burst, the nudge and the answers to private messages.',
                'note' => '',
            ],
            'file_routes' => [
                'name' => 'Files from the bot',
                'what' => 'The notifications bot\'s morning files go to groups by kind. Each kind has its own sender account and its own groups.',
                'note' => 'The number next to an account is how many kinds of files it sends.',
            ],
            'scheduled_messages' => [
                'name' => 'Scheduled messages',
                'what' => 'Writes into groups at the set time; the sender is chosen for each message.',
                'note' => 'The number next to an account is how many active messages it writes.',
            ],
            'contact_sync' => [
                'name' => 'Contact sync',
                'what' => 'Once a day saves the group members into the accounts\' contacts. A separate job on its own accounts.',
                'note' => '',
            ],
            'phone_resolver' => [
                'name' => 'Phone lookup',
                'what' => 'Finds a driver\'s Telegram by phone number through the account pool. The listener accounts are not used.',
                'note' => '',
            ],
            'group_join' => [
                'name' => 'Group join',
                'what' => 'The marked accounts, each in its own process under a separate watchdog, find groups by the keywords, check them, join them without stopping and collect their members. After a flood wait they go on by themselves.',
                'note' => '',
            ],
        ],
        'flows' => [
            'penalty' => [
                'title' => 'The way of a penalty',
                'steps' => [
                    'The bot posts the penalty in a group. The "Penalty intake" account (Driver Check by default) reads and records it.',
                    'The person responsible and their role - Sales or Operation - are found by name.',
                    'The account assigned to that role forwards the penalty. When it is another account, the penalty is handed over within seconds.',
                    'The same account writes the comment after a burst, nudges the silent ones and answers private replies.',
                    'No username and no Telegram ID, or Telegram refused: an alert comes. Save the ID on the person (after writing to them from that account).',
                ],
            ],
            'file' => [
                'title' => 'The way of a file',
                'steps' => [
                    'The notifications bot sends a file to the chat the receiving account can see.',
                    'The kind is found by a keyword in the caption or the file name.',
                    'The account chosen for the kind (or the general owner of "Files from the bot") forwards it to that kind\'s groups.',
                    'When it fails: retried every minute up to 3 times, then an alert.',
                ],
            ],
        ],
        'processes' => [
            'title' => 'Processes',
            'description' => 'Each account runs in its own process; the dot shows whether it is alive.',
            'none' => 'No listeners yet.',
            'run_all' => 'One process that starts and restarts all listeners together with Driver Check:',
            'run_note' => 'The separate commands (telegram:watchdog and telegram:listener-watchdog <key>) still work; duplicates are not started.',
        ],
        'alerts' => [
            'title' => 'Problems to know about',
            'description' => 'A penalty not delivered, a file not sent, a listener that keeps dying: each is written here. The same one within half an hour counts once.',
            'not_configured' => 'The alert bot is not set (TELEGRAM_ALERT_BOT_TOKEN and TELEGRAM_ALERT_CHAT_ID in .env): problems are only recorded.',
            'sent' => 'sent',
            'not_sent' => 'not sent',
            'empty' => 'No problems so far',
        ],
    ],

];
