<?php

/*
|--------------------------------------------------------------------------
| Auto replies
|--------------------------------------------------------------------------
|
| What the listener answers when an operator or a sales manager writes to
| it in a private chat: "+", "ok", "хоп" -> "Rahmat, Ali aka".
|
| Everything lives in one JSON file (`path`), written from the admin panel
| (Driver check -> Auto replies) and read on every message, so a change
| applies at once, without restarting the listener. The file can be edited
| by hand too; until it exists, `defaults` below are used, and "reset" in
| the panel deletes it.
|
| The file:
|
|   enabled                 - the switch: false answers nothing
|   only_after_penalty      - true: only a message after a penalty is
|                             answered; false: any message of theirs
|   penalty_window_minutes  - a message this soon after a penalty is that
|                             penalty's reply, kept on it in the journal
|   cooldown_minutes        - after an answer, the same person is not
|                             answered again for this long (a penalty's
|                             own reply is always answered once)
|   max_words               - a longer message is a conversation, not an
|                             "ok": it is not answered
|   replies                 - the kinds, tried top to bottom, the first
|                             whose keyword is in the message wins:
|       id                  - what personal answers ("Личные ответы",
|                             PersonalAnswers) point at; kept when the
|                             kind is renamed or moved (greetings too)
|       name                - shown in the panel and the journal
|       keywords            - what they write; case, Uzbek apostrophes
|                             (bo'ldi = boldi), ё/е, ў/у, қ/к, ғ/г, ҳ/х
|                             and stretched letters (okkk, ++) do not
|                             count; a "*" at the end takes any ending
|                             (клиент* - клиента, клиентом)
|       max_words           - optional: this kind takes longer messages
|                             than the file's max_words (an explanation
|                             like "клиент трубку не берёт")
|       answers             - what we write back, per language (uz, ru)
|                             and tone (plain, respectful - from the
|                             person's card); a random one is picked.
|                             Placeholders: {address} - how the card says
|                             to call them, left out with its comma when
|                             empty; {name}; {request} - the penalty's
|                             request, "—" without one.
|       media               - gifs: [{file, name}] for every language; one
|                             found in Telegram has telegram: {id,
|                             access_hash, file_reference} too and goes
|                             as that document, a real GIF (the file is
|                             its preview); voices: {uz: [...], ru: [...]}, only in the
|                             person's own language. Files are uploaded in
|                             the panel. The answer is one random pick out
|                             of the texts, the GIFs and the voices.
|   silence                 - the nudge for someone who ignores the
|       penalties: enabled, after_penalties (this many in a row without a
|       word back from them), answers and media (as above). In the
|       penalties' working hours; the count starts over after it.
|   greetings               - "Доброе утро. Готово": greeted back first, in
|       a reply of its own, then the kind's answer `greeting_pause` later.
|       enabled; list - tried top to bottom, the first found picks the
|       answer: name, keywords, answers (as above, texts only); fillers -
|       words that do not count after a greeting ("aka", "всем", "🙂"): a
|       greeting with only these is greeted back alone. A greeting before
|       a question is not answered, as any question. Once a day per person.
|   opening                 - we greet first: before the day's first
|       penalty to a person, "Assalomu alaykum, Ali aka", then the penalty
|       `greeting_pause` later. enabled, answers and media (as above). Not
|       when they greeted us first that day (greetings above).
|   follow_up               - "Это тоже": a penalty comment of the same level
|       as the one this person got less than `window_minutes` ago goes as a
|       short follow-up instead of the full phrase again. enabled,
|       window_minutes, answers (one penalty: "Это тоже", "Bu ham"),
|       answers_many (a burst: "Вот эти тоже не забудьте", "Bular ham") -
|       per language and tone, as above.
|
*/

return [

    /*
     * The script Uzbek texts go out in: "cyrillic" ("Рахмат", "Салом") or
     * "latin" (as the phrases are written). The phrases themselves stay in
     * Latin - they are converted on the way out (UzbekScript), for every
     * text the bot writes in Uzbek: replies, greetings, nudges, penalty
     * comments.
     */
    'uz_script' => env('AUTO_REPLIES_UZ_SCRIPT', 'cyrillic'),

    /*
     * How often a text calls the person by the card's address ("Пулатов,
     * нарх керак"), in percent: the rest leave it out with its comma, as
     * when none is written. Every text the bot writes - replies,
     * greetings, nudges, penalty comments. 20 - one message in five.
     */
    'address_chance' => (int) env('AUTO_REPLIES_ADDRESS_CHANCE', 20),

    /*
     * A person does not answer every "+" and does not say "это тоже" every
     * time: how often each of these is written at all, in percent; the rest
     * pass in silence. 100 - always.
     *
     *   reply     - the thanks for "+", "обновила" (the kind's answer)
     *   greeting  - greeting back the one who greeted us
     *   opening   - our greeting before the day's first penalty; the day
     *               goes without one when it is not
     *   follow_up - "это тоже" with a penalty after a fresh comment of its
     *               level; the rest go as the bare forward
     */
    'send_chance' => [
        'reply' => (int) env('AUTO_REPLIES_REPLY_CHANCE', 85),
        'greeting' => (int) env('AUTO_REPLIES_GREETING_CHANCE', 85),
        'opening' => (int) env('AUTO_REPLIES_OPENING_CHANCE', 50),
        'follow_up' => (int) env('AUTO_REPLIES_FOLLOW_UP_CHANCE', 50),
        'reaction' => (int) env('AUTO_REPLIES_REACTION_CHANCE', 80),
    ],

    /*
     * A reaction on our message (👍, 🤝) is read as a reply: it spares the
     * nudge "why are you silent", and an "ok" one is thanked now and then
     * (send_chance.reaction above, the same cooldown as a "+").
     *
     *   agree         - the emoji that mean "ok, seen, thanks"; any other
     *                   (👎, 😡, 😢 ...) is a reply too, but nothing is
     *                   written back
     *   custom_agrees - Premium custom emoji cannot be told apart: true
     *                   takes them for "ok"
     */
    'reactions' => [
        'agree' => [
            '👍', '👌', '✅', '🤝', '🙏', '❤', '💯', '🔥', '👏', '😊', '☺', '🫡', '🥰', '😍', '🤗', '🎉', '✍', '🤩', '⚡', '😎', '🤓', '🏆', '💪',
        ],
        'custom_agrees' => true,

        /*
         * Besides the kind's own answers, an "ok" reaction is answered with
         * these - emoji alone and short words - per language; {address} as
         * in the answers.
         */
        'answers' => [
            'uz' => [
                '🙏', '🤝', '👍', '🫡', '👌', '😊',
                'Рахмат 🙏', 'Рахмат 🤝', 'Рахмат ❤️', 'Рахмат, {address} 🙏',
                'Катта рахмат 🙏', 'Зур 👍', 'Хуп 👌', 'Яхши, рахмат 😊',
            ],
            'ru' => [
                '🙏', '🤝', '👍', '🫡', '👌', '😊',
                'Спасибо 🙏', 'Спасибо 🤝', 'Спасибо ❤️', 'Спасибо, {address} 🙏',
                'Принято 🫡', 'Отлично 👍', 'Хорошо 👌', 'Благодарю 🙏', 'Супер, спасибо 😊',
            ],
        ],
    ],

    'path' => env('AUTO_REPLIES_PATH', storage_path('app/telegram/auto-replies.json')),

    /*
     * The GIFs and voice messages uploaded in the panel (AutoReplyMedia).
     */
    'media_path' => env('AUTO_REPLIES_MEDIA_PATH', storage_path('app/telegram/auto-replies-media')),

    /*
     * An answer in a second gives the bot away: it waits a random few
     * seconds, "typing…" (or "recording a voice message…") meanwhile.
     * Seconds; both 0 answers at once.
     */
    'reply_delay' => [
        'min' => 5,
        'max' => 6,
    ],

    /*
     * Between the greeting and the kind's answer after it (greetings
     * below): a second message is typed faster than the first.
     */
    'greeting_pause' => [
        'min' => 2,
        'max' => 3,
    ],

    /*
     * Between a penalty and its short "это тоже" in a second wave
     * (PenaltyBurst): the next penalty waits for it.
     */
    'burst_pause' => [
        'min' => 1,
        'max' => 2,
    ],

    /*
     * A message of more words than this is never answered, whatever kind
     * it looks like ("Клиент пока не ответил, он в Китае... что мне
     * написать?"): it is a conversation for the person in charge. It is
     * still kept on the penalty it replies to. Not in the panel: it holds
     * over every kind's own max_words.
     */
    'silent_over_words' => 15,

    'defaults' => [

        'enabled' => true,

        'only_after_penalty' => false,

        'penalty_window_minutes' => 60,

        'cooldown_minutes' => 10,

        'max_words' => 5,

        /*
         * One kind: whatever they answer - "+", "обновила", "ёпаман" - a
         * thank-you fits, and a different one each time. GIFs and voice
         * messages are added in the panel.
         */
        'replies' => [
            [
                'name' => 'Благодарность',
                'max_words' => 8,
                'keywords' => [
                    /* agreed */
                    '+',
                    'ok',
                    'okay',
                    'okey',
                    'ок',
                    'окей',
                    'оке',
                    'хорошо',
                    'ладно',
                    'понял',
                    'поняла',
                    'hop',
                    'xop',
                    'хоп',
                    'xo\'p',
                    'хўп',
                    'tushundim',
                    'тушундим',
                    'tushunarli',
                    'тушунарли',
                    '👍',
                    '👌',
                    /* done */
                    'обновил',
                    'обновила',
                    'обновлено',
                    'поменял',
                    'поменяла',
                    'изменил',
                    'изменила',
                    'сделал',
                    'сделала',
                    'готово',
                    'yangiladim',
                    'янгиладим',
                    'ozgartirdim',
                    'узгартирдим',
                    'qildim',
                    'килдим',
                    'boldi',
                    'булди',
                    'done',
                    '✅',
                    /* will do */
                    'yopaman',
                    'ёпаман',
                    'qilaman',
                    'киламан',
                    'hozir',
                    'хозир',
                    'сейчас',
                    'щас',
                    'обновлю',
                    'поменяю',
                    'сделаю',
                    'исправлю',
                    'ozgartiraman',
                    'узгартираман',
                    'yangilayman',
                    'янгилайман',
                    'beraman',
                    'бераман',
                    /* working on it: "обрабатываю" (2026-10-13) */
                    'обрабатываю',
                    'обрабатываем',
                    'обработаю',
                    'занимаюсь',
                    'в процессе',
                    'qilyapman',
                    'киляпман',
                    'jarayonda',
                    'жараёнда',
                ],
                'answers' => [
                    'uz' => [
                        'plain' => [
                            'Рахмат, {address}',
                            'Рахмат!',
                            'Рахмат',
                            'Рахмат 👍',
                            'Зур, рахмат',
                            'Яхши, рахмат',
                            'Хуп, рахмат',
                            'Ок, рахмат',
                            'Зур 👍',
                        ],
                        'respectful' => [
                            'Рахмат, {address}!',
                            'Рахмат!',
                            'Рахмат',
                            'Рахмат сизга',
                            'Катта рахмат',
                            'Зур, рахмат',
                            'Хуп, рахмат',
                            'Рахмат 👍',
                        ],
                    ],
                    'ru' => [
                        'plain' => [
                            'Спасибо, {address}',
                            'Спасибо!',
                            'Спасибо',
                            'Спасибо 👍',
                            'Отлично, спасибо',
                            'Хорошо, спасибо',
                            'Ок, спасибо',
                            'Принято 👍',
                        ],
                        'respectful' => [
                            'Спасибо, {address}!',
                            'Спасибо!',
                            'Спасибо',
                            'Спасибо большое',
                            'Спасибо вам',
                            'Отлично, спасибо',
                            'Хорошо, спасибо',
                            'Принято, спасибо',
                        ],
                    ],
                ],
                'media' => ['gifs' => [], 'voices' => ['uz' => [], 'ru' => []]],
            ],
        ],

        /*
         * Someone ignores the penalties: after this many in a row with not
         * a word back, a nudge - what was done by hand on 2026-10-06
         * ("Рушана апа, илтимос", "Ukam, men bularni prosto takka
         * yozmayman"). Only in the penalties' working hours; the count
         * starts over after it.
         */
        'silence' => [
            'enabled' => true,
            'after_penalties' => 5,
            'answers' => [
                'uz' => [
                    'plain' => [
                        'Жавоб кутяпман, {address}',
                        'Мен буларни шунчаки ёзмайман, {address}',
                        'Жавоб борми, {address}?',
                        'Куряпсизми, {address}?',
                        '{address}, жарималар тупланиб колди',
                        'Хеч булмаса + ёзиб куйинг',
                        'Жарималарни куриб чикинг, {address}',
                        'Статуслар хали хам турибди, {address}',
                        'Жавоб ёзиб куйинг, {address}',
                    ],
                    'respectful' => [
                        'Илтимос, {address}',
                        'Илтимос, жавоб беринг, {address}',
                        'Жавобингизни кутяпман, {address}',
                        'Илтимос, бир караб куйинг, {address}',
                        'Жарималар тупланиб колди, {address}, илтимос',
                        'Илтимос, + булса хам ёзиб куйинг',
                        'Илтимос, {address}, статусларни янгилаб беринг',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Жду ответа, {address}',
                        'Ответьте, пожалуйста, {address}',
                        '{address}, ответ будет?',
                        'Штрафы копятся, {address}',
                        'Хотя бы + напишите',
                        '{address}, видите сообщения?',
                        'Я не просто так пишу, {address}',
                        '{address}, отпишитесь, пожалуйста',
                    ],
                    'respectful' => [
                        'Пожалуйста, {address}',
                        'Ответьте, пожалуйста, {address}',
                        '{address}, посмотрите, пожалуйста',
                        'Жду вашего ответа, {address}',
                        'Пожалуйста, хотя бы +, {address}',
                        'Штрафы накопились, {address}, посмотрите, пожалуйста',
                    ],
                ],
            ],
            'media' => ['gifs' => [], 'voices' => ['uz' => [], 'ru' => []]],
        ],

        /*
         * "Доброе утро. Готово" -> "Доброе утро, Анна!", then the thanks.
         * Names are only for the panel.
         */
        'greetings' => [
            'enabled' => true,

            'fillers' => [
                'aka', 'akajon', 'opa', 'opajon', 'apa', 'uka', 'ukam', 'brat', 'hammaga',
                'ака', 'акажон', 'опа', 'опажон', 'апа', 'ука', 'укам', 'брат', 'хаммага',
                'всем', 'коллеги', 'вам', 'тебе',
                '🙂', '😊', '☺', '🤝', '👋', '🌞', '☀',
            ],

            'list' => [
                [
                    'name' => 'Утро',
                    'keywords' => ['доброе утро', 'доброго утра', 'утро доброе', 'xayrli tong', 'hayrli tong', 'хайрли тонг', 'good morning'],
                    'answers' => [
                        'uz' => [
                            'plain' => [
                                'Ассалому алайкум, {address}!',
                                'Ассалому алайкум!',
                                'Ассалому алайкум',
                                'Ва алайкум ассалом',
                            ],
                            'respectful' => [
                                'Ассалому алайкум, {address}!',
                                'Ассалому алайкум!',
                                'Ассалому алайкум',
                                'Ва алайкум ассалом',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Доброе утро, {address}!',
                                'Доброе утро!',
                                'Доброе утро',
                                'И вам доброе утро',
                            ],
                            'respectful' => [
                                'Доброе утро, {address}!',
                                'Доброе утро!',
                                'Доброе утро',
                                'И вам доброе утро',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'День',
                    'keywords' => ['добрый день', 'xayrli kun', 'hayrli kun', 'хайрли кун'],
                    'answers' => [
                        'uz' => [
                            'plain' => [
                                'Хайрли кун, {address}!',
                                'Хайрли кун!',
                                'Хайрли кун',
                                'Сизга хам хайрли кун',
                            ],
                            'respectful' => [
                                'Хайрли кун, {address}!',
                                'Хайрли кун!',
                                'Хайрли кун',
                                'Сизга хам хайрли кун',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Добрый день, {address}!',
                                'Добрый день!',
                                'Добрый день',
                                'И вам добрый день',
                            ],
                            'respectful' => [
                                'Добрый день, {address}!',
                                'Добрый день!',
                                'Добрый день',
                                'И вам добрый день',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Вечер',
                    'keywords' => ['добрый вечер', 'xayrli kech', 'hayrli kech', 'хайрли кеч'],
                    'answers' => [
                        'uz' => [
                            'plain' => [
                                'Хайрли кеч, {address}!',
                                'Хайрли кеч!',
                                'Хайрли кеч',
                                'Сизга хам хайрли кеч',
                            ],
                            'respectful' => [
                                'Хайрли кеч, {address}!',
                                'Хайрли кеч!',
                                'Хайрли кеч',
                                'Сизга хам хайрли кеч',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Добрый вечер, {address}!',
                                'Добрый вечер!',
                                'Добрый вечер',
                                'И вам добрый вечер',
                            ],
                            'respectful' => [
                                'Добрый вечер, {address}!',
                                'Добрый вечер!',
                                'Добрый вечер',
                                'И вам добрый вечер',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Ассалому алайкум',
                    'keywords' => [
                        'assalomu alaykum', 'assalomu aleykum', 'assalamu alaykum', 'assalamu aleykum',
                        'ассалому алайкум', 'ассалому алейкум', 'ассаламу алайкум', 'ассаламу алейкум',
                        'salom alaykum', 'salam aleykum', 'салом алайкум', 'салам алейкум',
                        /*
                         * Typed every way there is - "Ассалому алекейкум"
                         * (2026-10-13): the second word only has to start
                         * with "ал".
                         */
                        'assalomu al*', 'assalamu al*', 'ассалому ал*', 'ассаламу ал*',
                        'salom al*', 'salam al*', 'салом ал*', 'салам ал*',
                        'assalom*', 'ассалом*', 'ассалам*',
                    ],
                    'answers' => [
                        'uz' => [
                            'plain' => [
                                'Ва алайкум ассалом, {address}!',
                                'Ва алайкум ассалом!',
                                'Ваалайкум ассалом',
                                'Валейкум ассалом',
                            ],
                            'respectful' => [
                                'Ва алайкум ассалом, {address}!',
                                'Ва алайкум ассалом!',
                                'Ваалайкум ассалом',
                                'Валейкум ассалом',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Ва алейкум ассалам, {address}!',
                                'Ва алейкум ассалам!',
                                'Ваалейкум ассалам',
                                'Алейкум ассалам',
                            ],
                            'respectful' => [
                                'Ва алейкум ассалам, {address}!',
                                'Ва алейкум ассалам!',
                                'Ваалейкум ассалам',
                                'Алейкум ассалам',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Салом',
                    'keywords' => ['здравствуйте', 'здраствуйте', 'здравствуй', 'приветствую', 'привет', 'salom', 'salam', 'салом', 'салам', 'hello'],
                    'answers' => [
                        'uz' => [
                            'plain' => [
                                'Салом, {address}!',
                                'Салом!',
                                'Салом',
                                'Ассалому алайкум!',
                            ],
                            'respectful' => [
                                'Ассалому алайкум, {address}!',
                                'Ассалому алайкум!',
                                'Ассалому алайкум',
                                'Ва алайкум ассалом!',
                            ],
                        ],
                        'ru' => [
                            'plain' => [
                                'Привет, {address}!',
                                'Привет!',
                                'Привет',
                                'Здравствуйте!',
                            ],
                            'respectful' => [
                                'Здравствуйте, {address}!',
                                'Здравствуйте!',
                                'Здравствуйте',
                            ],
                        ],
                    ],
                ],
            ],
        ],

        /*
         * The penalty used to come first and the person greeted us - the
         * older one greeting first, which the account's owner had to put
         * right by hand (2026-10-13). Now the day starts with ours.
         */
        'opening' => [
            'enabled' => true,
            'answers' => [
                'uz' => [
                    'plain' => [
                        'Ассалому алайкум, {address}!',
                        'Ассалому алайкум!',
                        'Ассалому алайкум',
                        'Салом, {address}!',
                        'Салом!',
                    ],
                    'respectful' => [
                        'Ассалому алайкум, {address}!',
                        'Ассалому алайкум!',
                        'Ассалому алайкум',
                        'Ассалому алейкум, {address}',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Здравствуйте, {address}!',
                        'Здравствуйте!',
                        'Здравствуйте',
                        'Привет, {address}!',
                        'Привет!',
                    ],
                    'respectful' => [
                        'Здравствуйте, {address}!',
                        'Здравствуйте!',
                        'Здравствуйте',
                        'Ассаламу алейкум!',
                    ],
                ],
            ],
            'media' => ['gifs' => [], 'voices' => ['uz' => [], 'ru' => []]],
        ],

        /*
         * "Обновите статус" to the same person twice in a row reads like a
         * bot; a person writes "это тоже" the second time.
         */
        'follow_up' => [
            'enabled' => true,
            'window_minutes' => 60,
            'answers' => [
                'uz' => [
                    'plain' => [
                        'Бу хам',
                        'Буниси хам',
                        'Бу хам бор',
                        'Буни хам унутманг',
                        'Мана бу хам',
                        'Яна биттаси',
                        'Буни хам, {address}',
                    ],
                    'respectful' => [
                        'Бу хам, {address}',
                        'Буниси хам, илтимос',
                        'Буни хам унутманг, {address}',
                        'Мана бу хам',
                        'Бу хам, илтимос',
                        'Яна биттаси, {address}',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Это тоже',
                        'И это',
                        'Вот это тоже',
                        'Это тоже не забудьте',
                        'Ещё одна',
                        'Это тоже, {address}',
                        'И это тоже',
                        'Этот тоже',
                    ],
                    'respectful' => [
                        'Это тоже, {address}',
                        'И это, пожалуйста',
                        'Вот это тоже, {address}',
                        'Это тоже не забудьте, пожалуйста',
                        'И вот это, пожалуйста',
                        'Ещё одна, {address}',
                        'И это тоже, {address}',
                    ],
                ],
            ],
            'answers_many' => [
                'uz' => [
                    'plain' => [
                        'Булар хам',
                        'Булари хам',
                        'Мана булар хам',
                        'Буларни хам унутманг',
                        'Яна бир нечтаси',
                        'Буларни хам, {address}',
                        'Булар хам бор',
                        'Мана яна',
                    ],
                    'respectful' => [
                        'Булар хам, {address}',
                        'Буларни хам унутманг, илтимос',
                        'Мана булар хам, {address}',
                        'Булар хам, илтимос',
                        'Яна бир нечтаси, {address}',
                        'Мана яна, {address}',
                    ],
                ],
                'ru' => [
                    'plain' => [
                        'Вот эти тоже',
                        'И эти',
                        'Эти тоже не забудьте',
                        'Вот эти тоже не забудьте',
                        'Ещё несколько',
                        'Эти тоже, {address}',
                        'И эти тоже',
                        'Вот ещё',
                    ],
                    'respectful' => [
                        'Вот эти тоже, {address}',
                        'И эти, пожалуйста',
                        'Эти тоже не забудьте, пожалуйста',
                        'Вот эти тоже, пожалуйста',
                        'Ещё несколько, {address}',
                        'И эти тоже, {address}',
                        'Вот ещё, {address}',
                    ],
                ],
            ],
        ],
    ],

    /*
     * Texts that were shipped once and are no more (2026-10-23: too many
     * greetings and thanks, and words nobody here writes). The panel keeps
     * its own copy: telegram:merge-defaults takes these out of it, from
     * every answer list, leaving whatever was added in the panel itself.
     */
    'retired' => [
        'Хайрли тонг, {address}!',
        'Хайрли тонг!',
        'Хайрли тонг',
        'Сизга хам хайрли тонг',
        'Зур, рахмат!',
        'Катта рахмат, {address}',
        'Ок, рахмат, {address}',
        'Келишдик, рахмат',
        'Баракалла, {address}!',
        'Мана бу бошка гап, рахмат',
        'Тушунарли, рахмат',
        'Хуп, рахмат!',
        'Зур иш, {address}',
        'Ажойиб, рахмат!',
        'Рахмат, {address}, зур',
        'Яхши, келишдик',
        'Койил, рахмат!',
        'Рахмат, {address} 🤝',
        'Зур, рахмат, {address}!',
        'Катта рахмат, {address}!',
        'Рахмат сизга, {address}',
        'Жуда яхши, рахмат!',
        'Рахмат, {address} 🙏',
        'Келишдик, рахмат сизга',
        'Тушунарли, рахмат, {address}',
        'Хуп, катта рахмат!',
        'Рахмат, {address}, сог булинг',
        'Миннатдорман, {address}',
        'Баракалла, рахмат сизга',
        'Ажойиб, рахмат, {address}',
        'Жуда соз, рахмат!',
        'Рахмат, {address} 👍',
        'Келишдик, {address}, рахмат',
        'Яхши, катта рахмат',
        'Рахмат сизга!',
        'Зур, рахмат сизга, {address}',
        'Отлично, спасибо!',
        'Супер, спасибо',
        'Отлично 👍',
        'Спасибо, {address}, отлично',
        'Хорошо, спасибо!',
        'Договорились, спасибо',
        'Класс, спасибо, {address}',
        'Ясно, спасибо',
        'Благодарю!',
        'Ок, спасибо, {address}',
        'Отлично, договорились',
        'Спасибо 🙌',
        'Супер, {address}!',
        'Отлично, благодарю',
        'Сэнк ю!',
        'Отлично, спасибо, {address}!',
        'Спасибо большое, {address}!',
        'Благодарю, {address}!',
        'Большое спасибо!',
        'Отлично, благодарю вас',
        'Спасибо вам, {address}',
        'Договорились, спасибо вам!',
        'Принято, спасибо, {address}',
        'Очень хорошо, спасибо!',
        'Спасибо вам большое 🙏',
        'Благодарю вас, {address}',
        'Замечательно, спасибо!',
        'Спасибо, {address}, договорились',
        'Хорошо, большое спасибо',
        'Спасибо вам!',
        'Отлично, {address}, спасибо вам',
        'Принято, благодарю',
        'Спасибо, {address} 👍',
        'Прекрасно, спасибо вам!',
        'Ассалому алайкум, {address}, хайрли тонг!',
        'Тонг хайрли булсин, {address}',
        'Хайрли тонг, {address} ☀️',
        'Сизга хам хайрли тонг, {address}',
        'Хайрли тонг, кунингиз яхши утсин',
        'Хайрли тонг, {address}! 🙂',
        'Хайрли тонг, {address}',
        'И вам доброе утро, {address}!',
        'Доброе утро, {address} ☀️',
        'Доброе утро, хорошего дня!',
        'Утро доброе, {address}',
        'Доброе утро, {address}! Хорошего дня',
        'Доброе, {address}!',
        'Доброе утро, {address} 🙂',
        'Доброго утра, {address}!',
        'Ассалому алайкум, {address}, хайрли кун!',
        'Сизга хам хайрли кун, {address}',
        'Хайрли кун, {address} 🙂',
        'Кунингиз хайрли утсин, {address}',
        'Хайрли кун, {address}',
        'И вам добрый день, {address}!',
        'Добрый день, {address} 🙂',
        'Добрый день, хорошего дня!',
        'День добрый, {address}',
        'Добрый, {address}!',
        'Приветствую, {address}, добрый день!',
        'Сизга хам хайрли кеч, {address}',
        'Хайрли кеч, {address} 🙂',
        'Кечингиз хайрли булсин, {address}',
        'Хайрли кеч, {address}',
        'Ассалому алайкум, {address}, хайрли кеч!',
        'И вам добрый вечер, {address}!',
        'Добрый вечер, {address} 🙂',
        'Вечер добрый, {address}',
        'Добрый вечер, {address}! Хорошего вечера',
        'Приветствую, {address}, добрый вечер!',
        'Ваалайкум ассалом, {address}',
        'Ва алайкум ассалом, {address} 🙂',
        'Ва алайкум ассалом, хайрли кун!',
        'Валейкум ассалом, {address}',
        'Ваалайкум ассалом!',
        'Ва алайкум ассалом, {address} 🤝',
        'Ваалейкум ассалам, {address}',
        'Ва алейкум ассалам, {address} 🙂',
        'Ва алейкум ассалам, хорошего дня!',
        'Ва алейкум ассалам, {address}! Добрый день',
        'Ваалейкум ассалам!',
        'Ва алейкум ассалам, {address} 🤝',
        'Алейкум ассалам, {address}',
        'Салом, {address} 🙂',
        'Салом, {address}, хайрли кун',
        'Салом-салом, {address}!',
        'Салом, {address} 👋',
        'Ассалому алайкум, {address} 🙂',
        'Ассалому алайкум, {address} 🤝',
        'Ассалому алайкум, {address}, кунингиз хайрли утсин',
        'Привет, {address} 🙂',
        'Приветствую, {address}!',
        'Привет-привет, {address}',
        'Привет, {address}, хорошего дня',
        'Привет, {address} 👋',
        'Привет! Хорошего дня',
        'Салют, {address}!',
        'Здравствуйте, {address} 🙂',
        'Приветствую вас, {address}!',
        'Здравствуйте, {address}, хорошего дня!',
        'Здравствуйте, {address} 🤝',
        'Здравствуйте, {address}, всего доброго',
        'Доброго дня, {address}!',
        'Буларга жавоб керак, {address}',
        '{address}, жавоб йук-ку',
        'Жавобсиз колмасин, {address}',
        'Бир огиз жавоб ёзинг, {address}',
        'Кутяпман, {address}, жавоб ёзинг',
        'Шунчаки ёзмаяпман, жавоб керак',
        'Илтимос, жавоб кайтаринг, {address}',
        '{address}, жим турманг',
        '{address}, хабарларни укинг, илтимос',
        '{address}, куриб чиксангиз',
        '{address}, илтимос, жавоб ёзиб куйсангиз',
        'Кечирасиз, {address}, жавоб кутяпман',
        '{address}, вактингиз булса, караб беринг',
        'Нужен ответ, {address}',
        'Жду обратную связь, {address}',
        'Ответа так и нет, {address}',
        'Извините, {address}, жду ответа',
        '{address}, будьте добры, отпишитесь',
        '{address}, когда будет минутка, ответьте',
        'Очень жду ответа, {address}',
        'Ассалому алайкум, кунингиз хайрли утсин!',
        'Ассаламу алейкум, {address}!',
        'Бунга хам каранг',
        'Бу хам шунака',
        'Бу хам уша',
        'Бунга хам караб куйинг, илтимос',
        'Бунисини хам куриб куйинг',
        'Бу хам шу каторда',
        'Буни хам, илтимос, {address}',
        'И вот это',
        'Сюда тоже гляньте',
        'Посмотрите и это, пожалуйста',
        'Сюда тоже взгляните, пожалуйста',
        'Этот тоже, пожалуйста',
        'Буларга хам каранг',
        'Булар хам шунака',
        'Буларга хам караб куйинг, илтимос',
        'Буларни хам куриб куйинг',
        'Булар хам шу каторда',
        'Буларни хам, илтимос, {address}',
        'И вот эти',
        'И вот эти, {address}',
        'Посмотрите и эти, пожалуйста',
    ],

];
