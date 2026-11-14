<?php

/*
|--------------------------------------------------------------------------
| Client checks (CRM penalties) - the defaults
|--------------------------------------------------------------------------
|
| A bot posts a penalty when a request sits in its status too long. The
| listener forwards it to the person responsible and sends that person one
| comment, from the account it runs on.
|
| These are only the starting values: the panel ("Настройки штрафов")
| saves its own copy to the database (ClientCheckRulesStore), and from then
| on that copy is what is used. "Restore defaults" comes back here.
|
| What is said depends on how many times the bot has sent the penalty -
| "⚠️ Штраф по запросу #…" is the first time, "🆘 Повторное отправление
| штрафа №N по запросу #…" the N-th - and on the person: their language
| (Russian for sales, Uzbek for operators, unless changed on their card)
| and whether they are written to with respect (people older than the one
| writing).
|
*/

/*
 * Sales: the plain and the respectful sets say the same (see `sales` below).
 */
$salesUz = [
    'Статусни янгиланг, илтимос',
    'Илтимос, статусни янгилаб куйинг',
    'Заявка статусини янгиланг, илтимос',
    'Статусни янгилаб куйинг, {address}, илтимос',
    'Статусни янгилашни унутманг, илтимос',
    'Статусни янгилаб куйинг',
    'Заявка статусини узгартириб куйинг, илтимос',
    '{address}, статусни янгиланг',
    'Статус эскириб колди, янгилаб куйинг',
    'Илтимос, статусни тугрилаб куйинг',
    'Статусни янгилашни эсдан чикарманг',
    'Заявка статуси янгиланмаган, илтимос',
    'Статусни Занджирда янгилаб куйинг',
    '{address}, илтимос, статус',
    'Статус буйича караб куйинг, илтимос',
    'Статусни узгартириб куйсангиз',
    'Клиент жавоб берган булса, статусни янгиланг',
    'Статус хали эски, илтимос',
    'Статусга караб куйинг, {address}',
    'Янгилаб куйинг статусни, илтимос',
];

$salesRu = [
    'Обновите, пожалуйста, статус',
    'Обновите статус, пожалуйста',
    'Поменяйте, пожалуйста, статус запроса',
    'Статус обновите, {address}, пожалуйста',
    'Не забудьте обновить статус, пожалуйста',
    /* As written by hand (2026-10-07). */
    'обновите статус',
    'Обновите статус, {address}',
    'Статус устарел, обновите, пожалуйста',
    'Поменяйте статус, пожалуйста',
    'Статус, пожалуйста',
    'Обновите статус в Занджире, пожалуйста',
    'Не забудьте про статус',
    'Статус запроса не обновлён, пожалуйста',
    'Посмотрите статус, пожалуйста',
    '{address}, статус обновите, пожалуйста',
    'Если клиент ответил, обновите статус',
    'Статус бы обновить',
    'Обновите, пожалуйста, статус по запросу',
    'Статус всё ещё старый, {address}',
    'Поправьте статус, пожалуйста',
];

return [

    /*
     * The bot often posts several penalties in a burst. One person's
     * penalties within this many seconds of each other get one comment -
     * different requests too - the strongest one's: the highest repeat
     * number whose level has a comment, the latest on a tie.
     *
     * 5, not 20: the comment is meant to follow the forward within seconds
     * (2026-10-07). Together with the listener's 2-second tick
     * (TelegramDriverCheckHandler::CLIENT_CHECK_PERIOD) it arrives about
     * 5-7 seconds after the forward.
     */
    'batch_quiet_seconds' => 5,

    /*
     * Failed steps are retried this many times in total, and only while the
     * penalty is younger than retry_minutes.
     */
    'max_attempts' => 3,

    'retry_minutes' => 30,

    /*
     * A penalty in one of these statuses is looked up in the CRM API
     * (services.crm): when the request already has a carrier price, the
     * operator has done their part and it is the sales manager's turn -
     * the penalty goes to the request's sales manager, whoever the bot
     * names as responsible. No price, no answer from the CRM: as before.
     * Compared without case. Empty: never looked up.
     */
    'sales_turn_statuses' => ['Актуальный'],

    /*
     * Penalties are only handled inside these hours (app timezone,
     * Asia/Tashkent): what the bot posts before 09:00 or from 18:00 on is
     * recorded and ignored - nothing is forwarded, no comment follows.
     * Both null: every hour counts.
     */
    'working_hours' => [
        'from' => '09:00',
        'to' => '18:00',
    ],

    /*
     * "❌ Запрос отменён (EGS21300)" from the bot: the person who got the
     * request's last penalty is asked why, in a reply to that penalty in
     * their private chat (RequestCancellationAsker). Only within the
     * working hours above; one that waited longer than `wait_hours` (came
     * in the evening, the account was down) is given up. Not a part of the
     * panel's rules - read from here.
     *
     * Phrases per language (the person's, as for the comments); one is
     * picked at random. Placeholders as in `roles` below: {request},
     * {address}, {name}. Uzbek is written in Latin and goes out in Cyrillic
     * when auto_replies.uz_script says so.
     */
    'cancellation' => [
        'enabled' => true,
        'wait_hours' => 24,
        'max_attempts' => 3,
        'phrases' => [
            'ru' => [
                'Запрос #{request} отменили. Почему отменили?',
                '{address}, почему отменили запрос #{request}?',
                'Запрос #{request} отменён, по какой причине?',
            ],
            'uz' => [
                "Zayavka #{request} bekor qilinibdi. Nima sababdan bekor qilindi?",
                "{address}, #{request} zayavka nega bekor qilindi?",
                "#{request} bekor bo'libdi, sababi nima edi?",
            ],
        ],
    ],

    /*
     * "📍 Не назначенные слежения — 13.11.2026 16:00" from the bot (12, 14,
     * 16, 18 o'clock): every operator of its "По оперейшнам" list is hurried
     * in their private chat (UntrackedOrdersReminder), from the account
     * that delivers their penalties. The owner, 2026-11-14: "их надо
     * поторопить и упрекнуть - слежения бер, 5 та заказни слеженияси йук".
     *
     * Taken from every place of "Отслеживаемые чаты": the bot writes it to
     * the account in a private chat (@zanjeer_notifications_bot - that is
     * production, add it there), a group is where the owner tests by hand.
     * Every message is a report of its own.
     *
     * Level = the n-th report of the day that names the person: the 1st
     * hurries, the 2nd reproaches ("still"), the 3rd and every later one
     * reproaches harder. Only `min_count` orders or more; within `hours`
     * (the 18:00 report still goes); what has not gone in `wait_minutes`
     * is given up. A newer report replaces one not sent yet. Off with the
     * penalties (panel) or the person's "penalties" switch.
     *
     * Placeholders: {count} - the number, {orders} - "5 заказов" in Russian,
     * "5 та заказ" in Uzbek, {address}, {name} as in `roles` below. Per
     * level, language and tone (respectful for people older than the one
     * writing); one is picked at random, never the one the person got last.
     */
    'untracked' => [
        'enabled' => (bool) env('CLIENT_CHECKS_UNTRACKED', true),
        'min_count' => 1,
        'hours' => [
            'from' => '09:00',
            'to' => '18:30',
        ],
        'wait_minutes' => 90,
        'max_attempts' => 3,
        'levels' => [
            [
                'uz' => [
                    'plain' => [
                        'Слежения бер, {count} та заказ слежениясиз турибди',
                        '{count} та заказни слеженияси йук, тезрок бер',
                        '{address}, {count} та заказга слежения берилмаган, тезрок куй',
                        '{count} та заказга слежения керак, бериб юбор',
                        'Слежениялар йук, {count} та заказ, тезрок назначить кил',
                        'Заказларга слежения бер, {count} таси слежениясиз',
                        '{count} та заказ слежениясиз турибди, караб юбор',
                        'Слежения назначить килиб юбор, {count} та заказ колди',
                        '{address}, слежения бер, {count} та заказ кутяпти',
                        'Тезрок слежения бер, {count} та заказда йук',
                    ],
                    'respectful' => [
                        'Слежения бериб юборинг илтимос, {count} та заказ слежениясиз турибди',
                        '{count} та заказни слеженияси йук, тезрок бериб юборинг',
                        '{address}, {count} та заказга слежения берилмаган, караб юборинг',
                        'Илтимос, {count} та заказга слежения назначить килиб юборинг',
                        '{count} та заказга слежения керак эди, илтимос',
                        'Слежениялар йук, {count} та заказ, тезрок куйиб юборинг',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Назначь слежение, {orders} без слежения',
                        '{address}, у тебя {orders} без слежения, давай быстрее',
                        'Слежение не назначено: {orders}, поставь побыстрее',
                        '{orders} висят без слежения, назначь',
                    ],
                    'respectful' => [
                        'Назначьте, пожалуйста, слежение: {orders} без слежения',
                        '{address}, у вас {orders} без слежения, поставьте побыстрее',
                        'Слежение не назначено: {orders}, назначьте, пожалуйста',
                    ],
                ],
            ],
            [
                'uz' => [
                    'plain' => [
                        'Хали хам {count} та заказ слежениясиз, нега бермаяпсан?',
                        'Слежения хали йук, {count} та заказ турибди, тезрок бер',
                        '{address}, яна {count} та заказ слежениясиз, айтгандим-ку',
                        'Нима гап, слежения хали берилмабди, {count} та заказ',
                        'Слежения бер деб айтдим-ку, {count} та хали хам йук',
                        'Хали хам слежения йук, {count} та заказ, тезлаштир',
                        '{count} та заказ хали хам слежениясиз, нима киляпсан?',
                        '{address}, хали хам {count} та заказни слеженияси йук',
                    ],
                    'respectful' => [
                        'Хали хам {count} та заказ слежениясиз турибди, тезрок бериб юборинг',
                        '{address}, слежения хали берилмабди, {count} та заказ, илтимос тезлаштиринг',
                        'Слежениялар хали хам йук, {count} та заказ, караб юборинг илтимос',
                        'Яна {count} та заказ слежениясиз, илтимос эътибор беринг',
                        'Айтгандим, {count} та заказни слеженияси хали хам йук',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Всё ещё {orders} без слежения, почему не назначаешь?',
                        'Слежение так и не назначено, {orders}, давай быстрее',
                        '{address}, я же говорил, {orders} всё ещё без слежения',
                    ],
                    'respectful' => [
                        'Всё ещё {orders} без слежения, назначьте, пожалуйста, побыстрее',
                        '{address}, слежение так и не назначено, {orders}, ускорьтесь, пожалуйста',
                    ],
                ],
            ],
            [
                'uz' => [
                    'plain' => [
                        'Кун буйи {count} та заказ слежениясиз турибди, бу нима?',
                        'Неча марта айтиш керак, {count} та заказга слежения бер',
                        '{address}, хали хам слежения йук, {count} та заказ. Бугун берасанми?',
                        'Кун тугаяпти, {count} та заказ хали слежениясиз',
                        'Слежения хали хам берилмади, {count} та заказ, бу кетишда булмайди',
                        'Эрталабдан бери айтяпман, {count} та заказни слеженияси йук',
                        '{address}, {count} та заказ кун буйи слежениясиз, жиддийрок бул',
                    ],
                    'respectful' => [
                        'Кун буйи {count} та заказ слежениясиз турибди, илтимос бугун тугатиб беринг',
                        '{address}, бир неча марта айтдим, {count} та заказга слежения керак',
                        'Хали хам {count} та заказ слежениясиз, илтимос, бугун колдирманг',
                        'Эрталабдан бери {count} та заказни слеженияси йук, илтимос, эътибор беринг',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Весь день {orders} без слежения, сколько можно?',
                        'Сколько раз говорить, {orders} без слежения, назначь',
                        '{address}, день заканчивается, а {orders} всё ещё без слежения',
                    ],
                    'respectful' => [
                        'Весь день {orders} без слежения, пожалуйста, закройте сегодня',
                        '{address}, уже несколько раз напоминаю: {orders} без слежения',
                    ],
                ],
            ],
        ],
    ],

    /*
     * The call by name (PingSilentByName): a penalty nobody answered for
     * `after_minutes` gets the person's name alone - "Зуба", "Рушана апа" -
     * from their card ("Личные ответы" -> "Оклик по имени", else how the
     * card says to call them; with neither, nothing goes). The owner does it
     * by hand: 45% answered within 10 minutes, 79% within the hour, against
     * 15% for the nudges (2026-11-13). So it stays rare: once per penalty, at
     * most `max_per_day` a day per person, `gap_minutes` apart, in the
     * penalties' working hours, never right after a nudge.
     */
    'name_ping' => [
        'enabled' => (bool) env('CLIENT_CHECKS_NAME_PING', true),
        'after_minutes' => 20,
        'max_per_day' => 2,
        'gap_minutes' => 120,
    ],

    /*
     * Who counts as someone who never answers (SilentPeople, telegram:silent-people,
     * the report /api/telegram/report/silent-people): this many penalties
     * forwarded in this many days, and not a word or a reaction back.
     */
    'silent_people' => [
        'days' => 14,
        'min_penalties' => 30,
    ],

    /*
     * Penalty comments that were shipped once and are no more: telegram:merge-defaults
     * takes them out of the copy saved in the panel. 2026-11-13: sent 45 and
     * 36 times at the last level, answered never (the level's mean is 11%).
     */
    'retired' => [
        'Нима килай, бошкага олайми?',
        'Булмаса айт, бошкага утказаман',
    ],

    /*
     * The same, by what the text says rather than letter for letter (it may
     * have been copied with other quotes or a name after it): 2026-11-13 the
     * owner wants no "at least write +" ("Хотя бы «+» напишите", "Хеч
     * булмаса + ёзиб куйинг") anywhere - a "+" in a text we send is only
     * that. Regular expressions.
     */
    'retired_matching' => [
        '/\+/u',
    ],

    /*
     * A ladder per role: operators and sales managers are told different
     * things. Each level starts at a repeat number (`from`) and says what
     * goes out at it (`mode`: 'all' - the forward and a comment,
     * 'forward' - the forward only, 'off' - nothing). The last level covers
     * everything after it ("4+").
     *
     * Placeholders: {address}, {name}, {request}, {repeat_number},
     * {status_limit}, {time_in_status}, {crm_status}. Telegram HTML is
     * allowed. {address} is how the person's card says to call them ("Ali
     * aka", "jigar"); with none written it is left out, with its comma.
     *
     * Respectful and plain are written apart: respectful is not a politer
     * copy of plain, it may say something else.
     */
    'roles' => [

        'operation' => [
            'levels' => [
                [
                    'name' => 'Первый раз',
                    'from' => 1,
                    'phrases' => [
                        'uz' => [
                            'plain' => [
                                'Нарх бериб юбор, {status_limit} вакт утди',
                                'Нарх ташла, {status_limit} булди',
                                'Нарх керак, {address}, {status_limit} утиб кетди',
                                'Заявкага нарх бер, {status_limit} вакт утди',
                                'Нархни ташлаб юбор, {status_limit} утди',
                                '{address}, нарх керак, {status_limit} булди',
                                'Заявкага нарх куй, вакт утди',
                                'Нарх бер, {status_limit} булиб колди',
                                'Нарх йук хали, {status_limit} утди',
                                'Нархни киритиб куй, {status_limit} булди',
                                'Шу заявкага нарх керак, {address}',
                                'Нарх бериб куй, вакти утиб кетди',
                                'Нархни юбор, {status_limit} вакт берилганди',
                                'Заявка нархсиз турибди, {status_limit} утди',
                                'Нарх бергин, {address}, {status_limit} булди',
                                'Тезрок нарх бер, {status_limit} утди',
                                'Нархни Занджирга кирит, {status_limit} булди',
                                'Нарх керак эди, {status_limit} утиб кетди',
                                '{address}, заявкага нарх ташла, вакт утди',
                                'Нархини бер, {status_limit} утди-ку',
                            ],
                            'respectful' => [
                                'Нарх бериб юборинг, илтимос, {status_limit} вакт утди',
                                'Нарх бера оласизми, {address}? {status_limit} утди',
                                'Илтимос, нархни юборинг, {status_limit} вакт булди',
                                'Нархингизни кутяпмиз, {status_limit} утиб кетди',
                                'Нархни юбориб куйсангиз, {status_limit} утди',
                                '{address}, нарх керак эди, {status_limit} булди',
                                'Илтимос, заявкага нарх беринг, {status_limit} утди',
                                'Нархни киритиб куйсангиз, илтимос, вакт утди',
                                'Заявка нархсиз турибди, {address}, {status_limit} утди',
                                'Нарх бериб юборсангиз, {status_limit} булиб колди',
                                'Илтимос, нархни Занджирга киритиб куйинг, {status_limit} утди',
                                'Нарх буйича караб берсангиз, {status_limit} утди',
                                '{address}, нархни кутяпмиз, вакт утиб кетди',
                                'Нарх бериб куйсангиз, илтимос, {status_limit} булди',
                                'Шу заявкага нарх керак, {address}, илтимос',
                                'Илтимос, нархни тезрок юборинг, {status_limit} утди',
                                'Нарх бера оласизми? {status_limit} утиб кетди',
                                '{status_limit} утди, нархни юбориб куйсангиз',
                                'Нарх масаласида караб беринг, илтимос, {address}',
                                'Нархни киритсангиз, {address}, {status_limit} булди',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Дай цену, уже {status_limit} прошло',
                                'Скинь цену, {status_limit} уже прошло',
                                'Нужна цена, {address}, прошло {status_limit}',
                                'Дай цену по запросу, уже {status_limit}',
                                'Скинь цену, {address}, {status_limit} прошло',
                                'Цену по запросу дай, {status_limit} уже',
                                'Поставь цену, уже {status_limit} прошло',
                                'Нужна цена, {status_limit} уже прошло',
                                'Запрос без цены, прошло {status_limit}',
                                'Цену внеси, {status_limit} прошло',
                                'Давай цену, {status_limit} уже вышло',
                                'По запросу цена нужна, {address}',
                                'Цену закинь, время вышло',
                                '{address}, дай цену, {status_limit} прошло',
                                'Цена нужна, время уже вышло',
                                'Внеси цену в Занджир, {status_limit} прошло',
                                'Цену бы, {status_limit} уже прошло',
                                'Скинь цену по запросу, время прошло',
                                'Цены нет, а {status_limit} уже прошло',
                                'Давай цену по запросу, {address}',
                            ],
                            'respectful' => [
                                'Дайте, пожалуйста, цену, уже {status_limit} прошло',
                                'Подскажите, пожалуйста, цену, {address}, прошло {status_limit}',
                                'Пришлите, пожалуйста, цену, уже {status_limit} прошло',
                                'Ждём вашу цену, уже {status_limit} прошло',
                                'Дайте цену, пожалуйста, {status_limit} уже прошло',
                                '{address}, нужна цена, прошло {status_limit}',
                                'Внесите, пожалуйста, цену, {status_limit} прошло',
                                'Скиньте, пожалуйста, цену по запросу, {status_limit} прошло',
                                'Поставьте, пожалуйста, цену, время уже вышло',
                                'Запрос без цены, {address}, прошло {status_limit}',
                                'Подскажите цену, пожалуйста, уже {status_limit}',
                                'Нужна ваша цена, {status_limit} уже прошло',
                                'Цену, пожалуйста, {address}, {status_limit} прошло',
                                'Пришлите цену, пожалуйста, {status_limit} уже прошло',
                                'Внесите цену в Занджир, пожалуйста, {status_limit} прошло',
                                'Дайте, пожалуйста, цену по запросу, время вышло',
                                '{address}, подскажите цену, уже {status_limit}',
                                'Ждём цену, {address}, прошло {status_limit}',
                                'Посмотрите, пожалуйста, цену, {status_limit} прошло',
                                'Цена нужна, пожалуйста, {status_limit} уже прошло',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Второй раз',
                    'from' => 2,
                    'phrases' => [
                        'uz' => [
                            'plain' => [
                                'Нарх берасанми?',
                                'Нарх кани?',
                                'Хали хам нарх йукми?',
                                'Мошина топилмадими?',
                                'Мошина чикмадими?',
                                'Нарх буладими?',
                                'Нима булди, нарх кани?',
                                'Мошина борми?',
                                'Нарх качон булади?',
                                'Хали хам мошина йукми?',
                                '{address}, нарх кани?',
                                'Нарх чикмадими?',
                                'Мошина топдингми?',
                                'Хали кидиряпсанми?',
                                'Нарх ташлайсанми?',
                                'Нима гап, нарх борми?',
                                'Нарх булмадими хали?',
                                'Мошина кидиряпсанми?',
                                'Топилдими мошина?',
                                '{address}, мошина чикдими?',
                            ],
                            'respectful' => [
                                'Мошина чикмадими?',
                                'Нарх буладими?',
                                'Хали мошина топилмадими?',
                                'Нарх качон булади, {address}?',
                                'Нарх борми, {address}?',
                                'Мошина топилдими?',
                                'Нарх чикмадими хали?',
                                'Хали хам кидиряпсизми?',
                                'Мошина буладими, {address}?',
                                'Нарх качонга булади?',
                                'Хали нарх йукми?',
                                'Мошина топа олдингизми?',
                                'Нарх бера оласизми?',
                                'Нима булди, мошина чикмадими?',
                                '{address}, нарх буладими?',
                                'Мошина борми, {address}?',
                                'Нарх буйича нима гап?',
                                'Хали мошина чикмадими, {address}?',
                                'Нарх булса юбориб куйсангиз',
                                'Мошина топилса, нархини юборинг',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Цену дашь?',
                                'Где цена?',
                                'Цены всё ещё нет?',
                                'Машину не нашёл?',
                                'Цена будет?',
                                'Машина не вышла?',
                                'Ну что, цена есть?',
                                'Что по цене?',
                                'Цена есть?',
                                'Когда цена?',
                                'Всё ещё ищешь?',
                                '{address}, где цена?',
                                'Машина есть?',
                                'Что с машиной?',
                                'Цену скинешь?',
                                'Цены так и нет?',
                                'Нашлась машина?',
                                'Что там по запросу?',
                                '{address}, что по машине?',
                                'Ну что там с ценой?',
                            ],
                            'respectful' => [
                                'Машина не нашлась?',
                                'Цена будет?',
                                'Машину пока не нашли?',
                                'Когда будет цена, {address}?',
                                'Что по цене, {address}?',
                                'Машина нашлась?',
                                'Цены пока нет?',
                                'Всё ещё ищете?',
                                'Цена будет, {address}?',
                                'Когда примерно будет цена?',
                                'Удалось найти машину?',
                                'Есть цена?',
                                'Что с машиной, подскажите?',
                                'Цену дадите?',
                                '{address}, что по запросу?',
                                'Машина есть, {address}?',
                                'Как там с ценой?',
                                'Пока не нашлась машина?',
                                'Подскажите, цена будет?',
                                'Если есть цена, скиньте, пожалуйста',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Третий раз',
                    'from' => 3,
                    'phrases' => [
                        'uz' => [
                            'plain' => [
                                'Барака топкур, канча кутиш мумкин?',
                                'Яна канча кутамиз?',
                                'Клиент кутяпти, тезрок бул',
                                'Нарх хали хам йук, нима булди?',
                                'Качонгача кутамиз?',
                                'Клиент кутиб колди, тезрок',
                                'Хали хам нарх йук, {address}?',
                                'Бу заявка канча туради?',
                                'Тезлаштир, клиент кутяпти',
                                'Уч марта ёздим, нарх кани?',
                                'Нима гап, нега нарх йук?',
                                'Клиент сураяпти, нима дейман?',
                                'Кутиш колмади, нарх бер',
                                '{address}, бу заявка хали турибди',
                                'Жуда чузилиб кетди, нарх бер',
                                'Клиентга нима дейин? Нарх керак',
                                'Яна кутамизми?',
                                'Муаммо борми? Айт',
                                'Тезрок бул, {address}',
                                'Канча вакт утди, хали нарх йук',
                            ],
                            'respectful' => [
                                'Барака топинг, яна канча кутайлик?',
                                'Клиент кутяпти, тезрок булармикан?',
                                'Нарх хали хам йук, бирор муаммо борми?',
                                'Яна канча кутишимиз керак, {address}?',
                                'Клиент кутяпти, илтимос, тезрок',
                                'Хали хам нарх йук, {address}, нима булди?',
                                'Клиентга нима дейлик? Нарх керак',
                                'Бирор муаммо булса, айтинг',
                                'Жуда чузилиб кетди, илтимос, нарх беринг',
                                'Клиент сураяпти, {address}, нарх буладими?',
                                'Яна канча кутамиз, илтимос?',
                                'Илтимос, тезлаштиринг, клиент кутиб колди',
                                'Бу заявка хали турибди, {address}',
                                'Учинчи марта ёзяпман, нарх буладими?',
                                'Нарх качон булишини айтинг, илтимос',
                                'Клиент безовта буляпти, {address}',
                                'Тезрок караб берсангиз, илтимос',
                                'Кийинчилик булса айтинг, {address}',
                                'Анча вакт утди, нарх буладими?',
                                'Илтимос, шу заявкани тезрок ёпинг',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Ну сколько можно ждать?',
                                'Долго ещё?',
                                'Клиент ждёт, давай быстрее',
                                'Цены всё ещё нет, что случилось?',
                                'Сколько ещё ждать?',
                                'Клиент ждёт, давай',
                                '{address}, цены всё нет?',
                                'Третий раз пишу, где цена?',
                                'Что не так, почему нет цены?',
                                'Клиент спрашивает, что ему сказать?',
                                'Затянулось, давай цену',
                                'Ещё ждём?',
                                'Проблема какая-то? Скажи',
                                'Быстрее, {address}',
                                'Сколько можно, клиент ждёт',
                                'Запрос висит, давай быстрее',
                                'Клиенту что отвечать?',
                                'Долго, давай цену уже',
                                'Время идёт, цены нет',
                                'Что с запросом, {address}?',
                            ],
                            'respectful' => [
                                'Подскажите, пожалуйста, сколько ещё ждать?',
                                'Клиент ждёт, можно побыстрее?',
                                'Цены всё ещё нет, какая-то проблема?',
                                'Сколько ещё ждать, {address}, подскажите?',
                                'Клиент ждёт, можно побыстрее, пожалуйста?',
                                'Цены всё нет, {address}, что случилось?',
                                'Что сказать клиенту? Нужна цена',
                                'Если есть проблема, скажите',
                                'Затянулось, дайте цену, пожалуйста',
                                'Клиент спрашивает, {address}, цена будет?',
                                'Сколько ещё ждём, подскажите?',
                                'Ускорьте, пожалуйста, клиент ждёт',
                                'Запрос всё ещё висит, {address}',
                                'Третий раз пишу, цена будет?',
                                'Подскажите, когда будет цена',
                                'Клиент уже нервничает, {address}',
                                'Посмотрите, пожалуйста, побыстрее',
                                'Если сложно, скажите, {address}',
                                'Прошло много времени, цена будет?',
                                'Закройте, пожалуйста, запрос побыстрее',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => '4 и больше',
                    'from' => 4,
                    'phrases' => [
                        'uz' => [
                            'plain' => [
                                'Бошкага берайми?',
                                'Ёпасанми ёки бошкага берайми?',
                                'Уддалай олмаяпсанми? Бошкага берайми?',
                                'Кила олмасанг айт, бошкага бераман',
                                'Бошкага бераман, майли?',
                                'Бошка хамкасбга берайми?',
                                'Ёполмасанг бошкага бераман',
                                'Нима киламиз, {address}? Бошкага берайми?',
                                'Ёки узинг ёпасанми?',
                                'Куп кутдик, бошкага берайми?',
                                'Бу заявкани бошкага утказайми?',
                                'Жавоб бер, бошкага берайми?',
                                'Энди бошкага берамиз шекилли',
                                'Ёпа оласанми узи?',
                                'Кила олмасанг муаммо йук, айт',
                                '{address}, ёпасанми ёки бошкага?',
                                'Бошкага берсам буладими?',
                                'Булди, бошкага берайми?',
                            ],
                            'respectful' => [
                                'Нима киласиз, ёпа оласизми ёки бошкага берамизми?',
                                'Бошкага берайликми?',
                                'Ёпасизми ёки бошка хамкасбга берайликми?',
                                'Уддалай олмасангиз, бошкага берайликми?',
                                'Кила олмасангиз айтинг, {address}, бошкага берамиз',
                                'Бошкага утказсак буладими?',
                                'Ёпа оласизми, {address}, ёки бошкага берайликми?',
                                'Кийин булса айтинг, бошкага берамиз',
                                'Бошка хамкасбга утказайликми?',
                                'Нима киламиз, {address}?',
                                'Куп кутдик, бошкага берайликми?',
                                'Узингиз ёпасизми ёки бошкагами?',
                                'Бу заявкани бошкага утказайликми, {address}?',
                                'Жавоб берсангиз, бошкага берайликми?',
                                'Вактингиз булмаса, бошкага берамиз',
                                'Ёпишга улгурасизми?',
                                'Муаммо булса айтинг, бошкага утказамиз',
                                'Бошкага берсак карши эмасмисиз?',
                                '{address}, кандай киламиз?',
                                'Илтимос, айтинг: ёпасизми ёки бошкагами?',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Что делать, отдать другому?',
                                'Отдать другому?',
                                'Закроешь или отдать другому?',
                                'Не успеваешь? Передать другому?',
                                'Если не получается, скажи, отдам другому',
                                'Отдам другому, ок?',
                                'Не выходит - скажи, передам',
                                'Передать коллеге?',
                                'Не закроешь - отдам другому',
                                'Что делаем, {address}? Отдать другому?',
                                'Или сам закроешь?',
                                'Долго ждём, отдать другому?',
                                'Этот запрос передать?',
                                'Ответь, отдать другому?',
                                'Похоже, придётся отдать другому',
                                'Закроешь вообще?',
                                'Не можешь - не страшно, скажи',
                                '{address}, закроешь или другому?',
                                'Можно отдам другому?',
                                'Всё, отдаю другому?',
                            ],
                            'respectful' => [
                                'Как поступим, передать запрос другому?',
                                'Передадим другому?',
                                'Закроете или передадим коллеге?',
                                'Если не получается, давайте передадим другому?',
                                'Если не успеваете, {address}, скажите, передадим другому',
                                'Можно передадим другому?',
                                'Закроете, {address}, или передадим?',
                                'Если сложно, скажите, передадим',
                                'Передадим коллеге?',
                                'Как поступим, {address}?',
                                'Долго ждём, передадим другому?',
                                'Сами закроете или другому?',
                                'Этот запрос передать, {address}?',
                                'Ответьте, пожалуйста, передать другому?',
                                'Если нет времени, передадим',
                                'Успеете закрыть?',
                                'Если есть проблема, скажите, передадим',
                                'Не против, если передадим другому?',
                                '{address}, что решим?',
                                'Скажите, пожалуйста: закроете или передать?',
                            ],
                        ],
                    ],
                ],
            ],
        ],

        /*
         * Sales: one text for every penalty, and respectful to everyone -
         * the plain set says the same, so the respect switch on a sales
         * card changes nothing.
         */
        'sales' => [
            'levels' => [
                [
                    'name' => 'Sales',
                    'from' => 1,
                    'mode' => 'all',
                    'phrases' => [
                        'uz' => [
                            'plain' => $salesUz,
                            'respectful' => $salesUz,
                        ],
                        'ru' => [
                            'plain' => $salesRu,
                            'respectful' => $salesRu,
                        ],
                    ],
                ],
            ],
        ],

    ],

];
