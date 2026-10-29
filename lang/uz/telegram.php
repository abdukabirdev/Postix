<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Umumiy panel elementlari
    |--------------------------------------------------------------------------
    |
    | Driver-check komponentlari ishlatadigan matnlar: filtrlar, saralash,
    | sahifalash, bo'sh va xato holatlari. Bitta nusxa - hamma sahifada bir xil.
    |
    */

    'ui' => [

        'filters' => 'Filtrlar',
        'filters_show' => 'Ko\'proq filtr',
        'filters_hide' => 'Filtrlarni yashirish',
        'filters_active' => 'Faol filtrlar',
        'reset' => 'Tozalash',
        'apply' => 'Qo\'llash',
        'search' => 'Qidirish',
        'search_clear' => 'Qidiruvni tozalash',
        'sort' => 'Saralash',
        'sort_asc' => 'O\'sish bo\'yicha',
        'sort_desc' => 'Kamayish bo\'yicha',
        'order' => 'Tartib',
        'per_page' => 'Sahifada',
        'showing' => 'Ko\'rsatilmoqda',
        'of' => 'dan',
        'page' => 'Sahifa',
        'previous' => 'Oldingi',
        'next' => 'Keyingi',
        'loading' => 'Yuklanmoqda...',
        'refresh' => 'Yangilash',
        'retry' => 'Qayta urinish',
        'copy' => 'Nusxalash',
        'copied' => 'Nusxalandi',
        'open' => 'Ochish',
        'close' => 'Yopish',
        'cancel' => 'Bekor qilish',
        'save' => 'Saqlash',
        'saving' => 'Saqlanmoqda...',
        'created' => 'Yaratilgan sana',
        'period' => 'Davr',
        'period_custom' => 'Boshqa oraliq',
        'details' => 'Batafsil',
        'error' => 'Xatolik',
        'never' => 'Hech qachon',
        'show_more' => 'Ko\'proq',
        'show_less' => 'Yig\'ish',
        'results' => 'ta natija',

        'duration' => [
            'd' => 'kun',
            'h' => 'soat',
            'm' => 'daq',
            's' => 's',
        ],
    ],

    'menu' => [
        'groups' => [
            'check' => 'Haydovchi tekshiruvi',
            'settings' => 'Sozlamalar',
            'monitoring' => 'Monitoring',
        ],
        'items' => [
            'operation_users' => 'Operatorlar statistikasi',
            'drivers' => 'Haydovchilar',
            'resolved_phones' => 'Telegram raqamlari',
            'penalties' => 'CRM jarimalari',
            'people' => 'Hammasi (odamlar)',
            'operators' => 'Operatorlar',
            'sales' => 'Sales',
            'penalty_settings' => 'Jarima sozlamalari',
            'auto_replies' => 'Avto-javoblar',
            'personal_answers' => 'Shaxsiy javoblar',
            'chats' => 'Chatlar',
            'file_routes' => 'Fayllar',
            'scheduled_messages' => 'Jadval bo\'yicha xabarlar',
            'system' => 'Qanday ishlaydi',
            'listeners' => 'Listenerlar',
            'sessions' => 'Telegram sessiyalari',
            'watchdog' => 'Jarayonlar',
            'contact_sync' => 'Guruh kontaktlari',
            'queue' => 'Navbat',
        ],
    ],

    'operators' => [

        'title' => 'Operatorlarni boshqarish',

        'description' => 'Operatorlarni Telegram bilan qo\'lda bog\'lash: hisobotlar ularning shaxsiy chatiga ham yuboriladi',

        'refresh' => 'Yangilash',

        'loading' => 'Yuklanmoqda...',

        'create' => 'Operator qo\'shish',

        'search_placeholder' => 'Ism, @username yoki Telegram ID',

        'per_page' => 'Sahifada',

        'stats' => [
            'total' => 'Jami operatorlar',
            'linked' => 'Telegram bog\'langan',
            'dm_enabled' => 'Lichkaga oladi',
            'failing' => 'Yuborishda xato',
        ],

        'filters' => [
            'title' => 'Filtrlar',
            'reset' => 'Tozalash',
            'dm' => 'Shaxsiy xabarlar',
            'dm_all' => 'Hammasi',
            'dm_on' => 'Yoqilgan',
            'dm_off' => 'O\'chirilgan',
            'linked' => 'Telegram',
            'linked_all' => 'Hammasi',
            'linked_yes' => 'Bog\'langan',
            'linked_no' => 'Bog\'lanmagan',
            'role' => 'Rol',
            'role_all' => 'Hammasi',
        ],

        'roles' => [
            'operation' => 'Operation',
            'sales' => 'Sales',
        ],

        'table' => [
            'operator' => 'Operator',
            'telegram' => 'Telegram',
            'dm' => 'Lichkaga',
            'drivers' => 'Haydovchilar',
            'checks' => 'Tekshiruvlar',
            'last_sent' => 'Oxirgi yuborilgan',
            'actions' => 'Amallar',
            'no_username' => 'Username yo\'q',
            'no_id' => 'ID yo\'q',
            'never' => 'Yuborilmagan',
            'dm_on' => 'Yoqilgan',
            'dm_off' => 'O\'chirilgan',
            'dm_unreachable' => 'Kontakt yo\'q',
            'edit' => 'Tahrirlash',
            'penalties' => 'Jarimalar',
            'last_penalty' => 'Oxirgi jarima',
            'no_penalties' => 'Jarima bo\'lmagan',
            'open_penalties' => 'Jarimalarni ochish',
            'style' => 'Qanday yozamiz',
            'respectful' => 'Hurmat bilan',
        ],

        'form' => [
            'create_title' => 'Yangi operator',
            'edit_title' => 'Operatorni tahrirlash',
            'name' => 'Operator ismi',
            'name_hint' => 'Guruh xabaridagi «Пользователь:» qatori bilan bir xil bo\'lishi kerak',
            'name_normalized' => 'Moslashtirish kaliti',
            'telegram_username' => 'Telegram username',
            'telegram_username_hint' => '«@» belgisisiz. Birinchi navbatda ishlatiladi — akkaunt operatorni hali tanimasa ham ishlaydi',
            'telegram_id' => 'Telegram ID',
            'telegram_id_hint' => 'Zaxira variant: akkaunt bu foydalanuvchini allaqachon ko\'rgan bo\'lsagina ishlaydi',
            'dm_enabled' => 'Hisobotlarni lichkaga yuborish',
            'dm_enabled_hint' => 'Guruhga ketgan hisobot nusxasi operatorga shaxsiy xabar bilan yuboriladi',
            'switches' => 'Lichkaga nima yuboriladi',
            'switches_hint' => 'Yuqoridagi lichkaga yuborish yoqilgan bo\'lsa ishlaydi.',
            'switches_off' => 'O\'chirilgan: :n',
            'switch' => [
                'penalties_enabled' => [
                    'label' => 'CRM jarimalari',
                    'hint' => 'Jarimani forward qilish. O\'chirilsa - jarimalar umuman yuborilmaydi.',
                ],
                'penalty_comments_enabled' => [
                    'label' => 'Jarimaga izoh',
                    'hint' => 'Forwarddan keyingi matn: «Moshina chiqmadimi?».',
                ],
                'greet_first_enabled' => [
                    'label' => 'Birinchi bo\'lib salom',
                    'hint' => 'Kunning birinchi jarimasidan oldin «Assalomu alaykum».',
                ],
                'nudge_enabled' => [
                    'label' => 'Eslatma',
                    'hint' => 'Jarimalarga ketma-ket javob bermasa.',
                ],
                'auto_replies_enabled' => [
                    'label' => 'Avto-javoblar',
                    'hint' => 'Uning xabarlariga javob: +, «ok», salom.',
                ],
            ],
            'role' => 'Rol',
            'role_hint' => 'Rol odam qaysi sahifada ko\'rinishini belgilaydi. Ism bo\'yicha moslashtirish umumiy: bitta odam ikkalasida bo\'la olmaydi.',
            'save' => 'Saqlash',
            'cancel' => 'Bekor qilish',
            'saving' => 'Saqlanmoqda...',
            'delete' => 'O\'chirish',
            'delete_hint' => 'Faqat haydovchisi, tekshiruvi va jarimasi yo\'q odamni o\'chirish mumkin.',
            'language' => 'Xabarlar tili',
            'language_auto' => 'Rol bo\'yicha: :language',
            'language_hint' => 'Jarima izohlari qaysi tilda yuboriladi.',
            'respectful' => 'Hurmat bilan',
            'respectful_hint' => 'Yoshi kattalar uchun: jarima izohlari hurmatli variantda yuboriladi.',
            'address' => 'Murojaat (:language)',
            'address_short' => 'Murojaat',
            'address_hint' => '«Ali aka» - kattaga, «jigar» - yaqin do\'stga, «uka» - kichikka. Matnlarga {address} bo\'lib qo\'yiladi; bo\'sh bo\'lsa, tushirib qoldiriladi.',
            'address_placeholder' => [
                'uz' => 'masalan, Ali aka',
                'ru' => 'masalan, Али ака',
            ],
        ],

        'validation' => [
            'duplicate_name' => 'Bunday ismli operator allaqachon mavjud',
            'username_format' => 'Username lotin harflari, raqamlar va «_» dan iborat, 5–32 belgi bo\'lishi kerak',
        ],

        'messages' => [
            'created' => 'Operator qo\'shildi',
            'updated' => 'O\'zgarishlar saqlandi',
            'moved' => 'Saqlandi va «:role» ga o\'tkazildi',
            'deleted' => 'O\'chirildi',
        ],

        'deleted' => 'Operator o\'chirildi',

        'errors' => [
            'title' => 'Xato',
            'load' => 'Operatorlarni yuklab bo\'lmadi',
            'save' => 'Operatorni saqlab bo\'lmadi',
            'has_history' => 'Bu odamda haydovchilar, tekshiruvlar yoki jarimalar bor - o\'chirib bo\'lmaydi, aks holda tarix egasiz qoladi. Lichkaga yuborishni o\'chiring.',
            'dm_last_error' => 'Oxirgi yuborishdagi xato',
            'delete' => 'O\'chirib bo\'lmadi',
        ],

        'empty' => [
            'title' => 'Operatorlar yo\'q',
            'description' => 'Operatorlar guruh xabarlaridan avtomatik yaratiladi. Qo\'lda ham qo\'shish mumkin.',
        ],

        'pagination' => [
            'showing' => 'Ko\'rsatilmoqda',
            'to' => '—',
            'of' => 'dan',
            'previous' => 'Oldingi',
            'next' => 'Keyingi',
        ],

        'tabs' => [
            'all' => 'Hammasi',
            'operation' => 'Operatorlar',
            'sales' => 'Sales',
        ],

        'pages' => [
            'operation' => [
                'title' => 'Operatorlar',
                'description' => 'Haydovchi tekshiruvi guruhlaridagi operatorlar: hisobotlar ularning lichkasiga ham yuboriladi. «Ответственный (Operation)» bo\'lgan CRM jarimalari ham shu yerga keladi.',
                'create' => 'Operator qo\'shish',
                'edit_title' => 'Operatorni tahrirlash',
                'total' => 'Jami operatorlar',
                'name_hint' => 'Guruh xabaridagi «Пользователь:» qatori bilan bir xil bo\'lishi kerak',
                'dm_enabled_hint' => 'Guruhdagi hisobot nusxasi va CRM jarimalari operatorga shaxsiy xabar bilan yuboriladi',
                'empty_title' => 'Operatorlar yo\'q',
                'empty_description' => 'Operatorlar guruh xabarlaridan avtomatik yaratiladi. Qo\'lda ham qo\'shish mumkin.',
            ],
            'sales' => [
                'title' => 'Sales',
                'description' => 'CRM jarimalaridagi sales-menejerlar («Ответственный (Sales)»): jarima ularning lichkasiga izoh bilan birga yuboriladi.',
                'create' => 'Sales qo\'shish',
                'edit_title' => 'Salesni tahrirlash',
                'total' => 'Jami sales',
                'name_hint' => 'Jarimadagi «Ответственный (Sales):» qatoridagi ism bilan bir xil bo\'lishi kerak',
                'dm_enabled_hint' => 'CRM jarimalari lichkaga izoh bilan birga yuboriladi',
                'empty_title' => 'Hozircha sales yo\'q',
                'empty_description' => 'Sales CRM jarimalaridan avtomatik yaratiladi. Telegramini darrov kiritish uchun qo\'lda ham qo\'shsa bo\'ladi.',
            ],
            'all' => [
                'title' => 'Hammasi',
                'description' => 'Operatorlar va sales-menejerlar birga: hisobotlar va jarimalar shaxsiy xabarda yuboriladigan hamma odamlar.',
                'create' => 'Odam qo\'shish',
                'edit_title' => 'Odamni tahrirlash',
                'total' => 'Jami odamlar',
                'name_hint' => 'Bot xabaridagi ism bilan bir xil bo\'lishi kerak («Пользователь:» yoki «Ответственный:»)',
                'dm_enabled_hint' => 'Hisobotlar va jarimalar odamga shaxsiy xabar bilan yuboriladi',
                'empty_title' => 'Hozircha odamlar yo\'q',
                'empty_description' => 'Odamlar bot xabarlaridan avtomatik yaratiladi. Qo\'lda ham qo\'shish mumkin.',
            ],
        ],
        'confirm' => [
            'delete_title' => 'O\'chirilsinmi?',
            'delete_text' => 'Bu amalni qaytarib bo\'lmaydi.',
            'delete' => 'O\'chirish',
            'cancel' => 'Bekor qilish',
        ],
    ],

    'penalties' => [
        'title' => 'CRM jarimalari',
        'description' => 'CRM boti statusda qotib qolgan so\'rovlar uchun yozgan jarimalar: kimga yuborildi, qanday izoh ketdi va yetib bordimi.',
        'search_placeholder' => 'So\'rov raqami, ism yoki @username',
        'settings' => [
            'title' => 'Jarimalarni yuborish',
            'enabled' => 'Jarimalarni yuborish',
            'enabled_hint' => 'Jarima mas\'ulning lichkasiga forward qilinadi. O\'chirilgan bo\'lsa hech narsa ketmaydi: na forward, na izoh. Jarimalar baribir yoziladi va hisoblanadi.',
            'comments' => 'Jarimadan keyingi izoh',
            'comments_hint' => 'Har bir jarimalar to\'plamidan keyin bitta izoh xabari ketadi. O\'chirilgan bo\'lsa faqat forward, qo\'shimcha xabarsiz.',
            'on' => 'Yoq',
            'off' => 'O\'ch',
            'saved' => 'Sozlama saqlandi',
            'failed' => 'Sozlamani saqlab bo\'lmadi',
            'off_banner' => 'Jarimalarni yuborish o\'chirilgan: yangi jarimalar yoziladi, lekin hech kimga ketmaydi. Qayta yoqilganda eskilari yuborilmaydi.',
            'comments_off_banner' => 'Izohlar o\'chirilgan: jarimalar qo\'shimcha xabarsiz forward qilinadi.',
            'operation_enabled' => 'Operatorlarga',
            'operation_enabled_hint' => '«Ответственный (Operation)» bo\'lgan jarimalar',
            'sales_enabled' => 'Sales\'ga',
            'sales_enabled_hint' => '«Ответственный (Sales)» bo\'lgan jarimalar',
            'by_role' => 'Kimga yuboriladi',
        ],
        'off_link' => 'Sozlamalarda yoqish',
        'delivery_on' => 'Yuborish yoqiq',
        'delivery_off' => 'Yuborish o\'chiq',
        'comments_off' => 'izohsiz',
        'level_n' => ':n-daraja',
        'open_settings' => 'Sozlamalar',
        'stats' => [
            'total' => 'Jami jarimalar',
            'sent' => 'Yetkazildi',
            'in_progress' => 'Jarayonda',
            'failed' => 'Xato',
            'skipped' => 'O\'tkazib yuborildi',
            'critical' => 'Eng yuqori daraja',
            'people' => 'Odamlar',
        ],
        'tabs' => [
            'all' => 'Hammasi',
            'operation' => 'Operation',
            'sales' => 'Sales',
        ],
        'filters' => [
            'period' => 'Davr',
            'period_all' => 'Butun davr',
            'period_today' => 'Bugun',
            'period_week' => '7 kun',
            'period_month' => '30 kun',
            'period_custom' => 'O\'z davri',
            'period_from' => 'Dan',
            'period_to' => 'Gacha',
            'role' => 'Rol',
            'status' => 'Status',
            'status_all' => 'Hammasi',
            'level' => 'Daraja',
            'level_all' => 'Hammasi',
            'person' => 'Odam',
            'person_clear' => 'Hammasini ko\'rsatish',
        ],
        'statuses' => [
            'pending' => 'Kutilmoqda',
            'forwarded' => 'Yuborildi, izoh kutilmoqda',
            'sent' => 'Yetkazildi',
            'failed' => 'Xato',
            'skipped' => 'O\'tkazib yuborildi',
        ],
        'reasons' => [
            'outside_hours' => 'Ish vaqtidan tashqari keldi - yuborilmadi',
            'responsible_missing' => 'Jarimada mas\'ul ko\'rsatilmagan',
            'responsible_unreachable' => 'Mas\'ulning Telegrami yo\'q yoki lichkaga yuborish o\'chirilgan',
            'person_penalties_off' => 'Odam kartasida jarimalar o\'chirilgan',
            'person_comments_off' => 'Izohsiz forward qilindi: odam kartasida o\'chirilgan',
            'forward_failed' => 'Telegram forwardni qabul qilmadi',
            'comment_failed' => 'Izoh yuborilmadi',
            'disabled' => 'Jarimalarni yuborish o\'chirilgan edi',
            'comment_disabled' => 'Izohsiz yuborildi: izohlar o\'chirilgan',
            'role_disabled' => 'Bu rol uchun yuborish o\'chirilgan',
            'level_off' => 'Bu darajada bu rolga hech narsa yuborilmaydi',
            'forward_only' => 'Faqat forward: bu darajada izoh kerak emas',
            'quiet' => 'Faqat forward: bu safar «bu ham»siz',
        ],
        'levels' => [
            '0' => 'Xotirjam',
            '1' => 'Eslatma',
            '2' => 'Qattiq',
            '3' => 'Juda qattiq',
        ],
        'table' => [
            'request' => 'So\'rov',
            'responsible' => 'Mas\'ul',
            'repeat' => 'Takror',
            'level' => 'Daraja',
            'status' => 'Status',
            'created' => 'Kelgan',
            'actions' => 'Amallar',
            'details' => 'Batafsil',
            'no_responsible' => 'Ko\'rsatilmagan',
            'crm' => 'CRMda ochish',
        ],
        'detail' => [
            'title' => 'So\'rov bo\'yicha jarima',
            'message' => 'Bot xabari',
            'comment' => 'Izoh',
            'comment_on_last' => 'Izoh har bir to\'plamga bitta yuboriladi - u to\'plamning eng kuchli jarimasida saqlanadi.',
            'batch' => 'To\'plamdagi jarimalar',
            'metrics' => 'Odam tarixi',
            'hour_count' => 'Oxirgi soat',
            'today_count' => 'Bugun',
            'week_count' => 'Shu hafta',
            'minutes_since_last' => 'Oldingi to\'plamdan beri, daqiqa',
            'crm_status' => 'CRMdagi status',
            'time_in_status' => 'Statusda turibdi',
            'attempts' => 'Urinishlar',
            'error' => 'Xato',
            'peer' => 'Qayerga yuborildi',
            'forwarded_at' => 'Yuborildi',
            'sent_at' => 'Izoh',
            'retry_hint' => 'Xatolar jarima yangi ekan avtomatik qayta yuboriladi. Odamning Telegrami bo\'lmasa, uni odamlar sahifasida kiriting.',
            'variant' => 'Qaysi matn',
            'variant_value' => ':language, :tone',
            'reply' => 'Odamning javobi',
            'reply_unread' => 'aniqlanmadi',
            'sales_turn' => 'Salesga ketdi: CRMda tashuvchi narxi bor',
            'sales_turn_price' => 'Tashuvchi narxi',
            'sales_turn_named' => 'Bot mas\'ul deb yozgan',
            'reply_unanswered' => 'Javob berilmadi: avto-javoblarning hech bir turi mos kelmadi.',
            'nudge' => 'Eslatildi: javob bo\'lmadi',
        ],
        'empty' => [
            'title' => 'Jarimalar yo\'q',
            'description' => 'CRM boti kuzatiladigan chatga yozganda jarimalar shu yerda paydo bo\'ladi.',
        ],
        'errors' => [
            'title' => 'Xato',
            'load' => 'Jarimalarni yuklab bo\'lmadi',
        ],
    ],

    'penalty_settings' => [
        'title' => 'Jarima sozlamalari',
        'description' => 'Bitta so\'rov bo\'yicha 1-, 2-, 3- va keyingi jarimalarda odamga nima yozish. Raqam bot xabaridan olinadi. Matnlar ikki tilda, oddiy va hurmatli - yoshi kattalar uchun.',
        'back' => 'Jarimalarga qaytish',
        'sections' => [
            'hours' => 'Ish vaqti',
            'delivery' => 'Yuborish',
            'levels' => 'Jarima raqami bo\'yicha matnlar',
            'levels_hint' => '«⚠️ Штраф по запросу» - birinchi marta, «🆘 Повторное отправление штрафа №N» - N-marta. Oxirgi daraja undan keyingilar uchun ham ishlaydi.',
            'timing' => 'Vaqt va qayta urinishlar',
            'placeholders' => 'O\'zgaruvchilar',
            'placeholders_hint' => 'Oxirgi tahrirlangan iboraga qo\'yish uchun bosing.',
            'language' => 'Matnlar tili',
            'how' => 'Matn qanday tanlanadi',
            'how_text' => 'Til va ohang odamning kartasidan olinadi: sales uchun standart rus tili, operatorlar uchun o\'zbek tili. «Hurmat bilan» ham o\'sha yerda yoqiladi. Bo\'sh variant eng yaqini bilan almashtiriladi: boshqa ohang, pastki daraja, oxirgi chora sifatida boshqa til.',
        ],
        'hours' => [
            'hint' => 'Bu vaqtdan tashqari kelgan jarimalar yoziladi, lekin hech kimga yuborilmaydi. Toshkent vaqti.',
            'from' => 'Dan',
            'to' => 'Gacha',
            'always' => 'Kun bo\'yi',
            'clear' => 'Cheklovsiz',
        ],
        'languages' => [
            'uz' => 'O\'zbekcha',
            'ru' => 'Русский',
        ],
        'tones' => [
            'plain' => 'Oddiy',
            'plain_hint' => 'Tengdosh va yoshlarga',
            'respectful' => 'Hurmat bilan',
            'respectful_hint' => 'Yoshi kattalarga',
        ],
        'level' => [
            'title' => ':n-daraja',
            'name' => 'Nomi',
            'name_placeholder' => 'Masalan: Ikkinchi marta',
            'from' => 'Qaysi jarimadan',
            'from_hint' => '«Повторное отправление штрафа №N» dagi raqam',
            'first' => 'Birinchi jarima',
            'from_summary' => '№:n-jarima',
            'from_summary_last' => '№:n-jarima va keyingilar',
            'add_phrase' => 'Ibora',
            'remove_phrase' => 'Iborani o\'chirish',
            'add' => 'Daraja qo\'shish',
            'remove' => 'Darajani o\'chirish',
            'empty' => 'Bo\'sh - :fallback olinadi',
            'fallback_tone' => 'oddiy variant',
            'fallback_lower' => 'pastki daraja matni',
            'phrases_count' => ':n ta ibora',
            'random_hint' => 'Ibora bir nechta bo\'lsa, tasodifiysi tanlanadi, lekin odam oldin olganidan boshqasi.',
        ],
        'timing' => [
            'batch_quiet_seconds' => 'Takrorni kutish, son',
            'batch_quiet_seconds_hint' => 'Shu vaqt ichida bitta odamga kelgan jarimalarga, turli so\'rovlar bo\'lsa ham, bitta izoh ketadi - eng kuchlisiniki (kattaroq raqam).',
            'max_attempts' => 'Yuborish urinishlari',
            'max_attempts_hint' => 'Telegram rad etsa, necha marta urinish.',
            'retry_minutes' => 'Qayta urinish muddati, daq',
            'retry_minutes_hint' => 'Shundan keyin jarima eskirgan hisoblanadi va yuborilmaydi.',
        ],
        'placeholders' => [
            'name' => 'Odamning ismi',
            'request' => 'So\'rov raqami',
            'repeat_number' => 'Jarima raqami',
            'status_limit' => 'Statusga berilgan vaqt (2 ч / 2 soat)',
            'time_in_status' => 'Statusda turgan vaqt',
            'crm_status' => 'CRMdagi status',
            'address' => 'Murojaat (kartochkadan)',
        ],
        'actions' => [
            'save' => 'Saqlash',
            'saving' => 'Saqlanmoqda...',
            'discard' => 'O\'zgarishlarni bekor qilish',
            'reset' => 'Standartga qaytarish',
            'reset_confirm' => 'Standart sozlamalarga qaytarish uchun yana bir marta bosing',
            'dirty' => 'Saqlanmagan o\'zgarishlar bor',
            'customised' => 'O\'z sozlamalari',
            'default' => 'Standart sozlamalar',
        ],
        'messages' => [
            'saved' => 'Jarima sozlamalari saqlandi',
            'reset' => 'Standart sozlamalar qaytarildi',
            'failed' => 'Saqlab bo\'lmadi',
            'leave' => 'O\'zgarishlar saqlanmagan. Sahifadan chiqasizmi?',
        ],
        'validation' => [
            'first_level_phrase' => 'Birinchi darajada kamida bitta ibora bo\'lishi kerak',
            'from_order' => ':level-daraja kamida №:min-jarimadan boshlanishi kerak',
            'fix' => 'Belgilangan maydonlarni to\'g\'rilang',
        ],
        'roles' => [
            'operation' => 'Operatorlar',
            'sales' => 'Sales',
        ],
        'roles_hint' => 'Operatorlar va sales uchun darajalar, matnlar va yuborish qoidalari alohida.',
        'modes' => [
            'all' => 'Forward + izoh',
            'forward' => 'Faqat forward',
            'off' => 'Yuborilmaydi',
        ],
        'mode_hint' => 'Shu darajada bu rolga nima ketadi',
        'copy_from' => '«:role» dan nusxa olish',
        'copy_confirm' => 'Yana bosing: «:role» darajalari bularning o\'rniga qo\'yiladi',
        'copied' => '«:role» dan nusxa olindi. Saqlashni unutmang.',
    ],

    'personal_answers' => [
        'title' => 'Shaxsiy javoblar',
        'description' => 'Bitta odam uchun o\'z ovozli xabarlari, GIF va matnlari: jarimaga, «xayrli tong»ga, avto-javobga',
        'progress' => 'Ovozlisi borlar: :done / :total',
        'how' => 'Vaziyatni tanlang va shu odamning javoblarini qo\'shing. Ular umumiylar bilan teng tushadi (yana bitta umumiy ibora kabi) yoki «Faqat shaxsiy» bilan ularning o\'rniga. Ovozli xabar - .ogg, Telegram yozganidek; GIF - Telegramdan yoki fayl qilib.',
        'add' => 'Qo\'shish',
        'add_placeholder' => 'Vaziyat: jarima, salomlashish, avto-javob...',
        'no_slots' => 'Hozircha shaxsiy javoblar yo\'q: odam umumiylarini oladi.',
        'no_items' => 'Bo\'sh: matn, ovozli xabar yoki GIF qo\'shing. Bo\'sh vaziyat saqlanmaydi.',
        'remove_slot' => 'Vaziyatni olib tashlash',
        'remove' => 'O\'chirish',
        'only' => 'Faqat shaxsiy',
        'only_on' => 'Bu vaziyatning umumiy javoblari unga yuborilmaydi - faqat shular',
        'only_off' => 'Umumiylarga qo\'shilgan: ular bilan teng tushadi',
        'add_text' => 'Matn',
        'add_voice' => 'Ovozli xabar',
        'add_gif' => 'GIF fayl',
        'text_placeholder' => 'Javob matni, {address} bilan ham bo\'ladi',
        'placeholders_title' => 'Qo\'yiladiganlar',
        'placeholders_hint' => 'Oxirgi tahrirlangan matnga qo\'yiladi. So\'rov, vaqt va status faqat jarimada bor.',
        'types' => [
            'text' => 'Matn',
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
            'penalty' => 'Jarima',
            'greeting' => 'Salomlashish',
            'reply' => 'Avto-javob',
            'silence' => 'Eslatma',
            'opening' => 'Jarimadan oldin salom',
        ],
        'situations' => [
            'penalty' => ':name (:from-chidan)',
            'level' => ':n-chidan daraja',
            'greeting' => 'Salomlashish :n',
            'reply' => 'Tur :n',
            'silence' => 'Jarimalarga javob bermaganda',
            'opening' => 'Kunning birinchi jarimasidan oldin',
            'gone' => 'Bu vaziyat endi yo\'q',
            'gone_hint' => 'Tur, salomlashish yoki daraja sozlamalarda o\'chirilgan: bu javoblar yuborilmaydi. Olib tashlang yoki qaytguncha qoldiring.',
        ],
        'people' => [
            'search' => 'Ism yoki @username',
            'role' => 'Rol',
            'all' => 'Hammasi',
            'without_voice' => 'Ovozlisi yo\'q',
            'none' => 'Hech kim topilmadi',
        ],
        'person' => [
            'respectful' => '«siz»',
            'plain' => '«sen»',
            'address' => 'murojaat «:address»',
            'muted' => 'Kartochkada unga shaxsiy xabarlar o\'chirilgan: hech narsa yuborilmaydi.',
        ],
        'empty' => [
            'title' => 'Chapdan odamni tanlang',
            'description' => 'Masalan, birinchi jarima uchun ismi bilan ovozli xabar yozilgan operatorni.',
        ],
        'placeholders' => [
            'address' => 'Murojaat (kartochkadan)',
            'name' => 'Odamning ismi',
            'request' => 'So\'rov raqami',
            'repeat_number' => 'Jarima nechanchi marta',
            'status_limit' => 'Statusga berilgan vaqt',
            'time_in_status' => 'Statusda qancha turibdi',
            'crm_status' => 'CRM dagi status',
        ],
        'actions' => [
            'dirty' => 'Saqlanmagan o\'zgarishlar bor: :name',
            'save' => 'Saqlash',
            'saving' => 'Saqlanmoqda...',
            'discard' => 'Bekor qilish',
        ],
        'messages' => [
            'saved' => 'Shaxsiy javoblar saqlandi',
            'failed' => 'Yuklab bo\'lmadi',
            'leave' => 'Saqlanmagan o\'zgarishlar bor. Saqlamasdan chiqasizmi?',
        ],
        'validation' => [
            'slot' => 'Noma\'lum vaziyat: :slot',
        ],
    ],

    'auto_replies' => [
        'title' => 'Avto-javoblar',
        'description' => 'Operator yoki sales bot akkauntiga shaxsiy chatda yozsa, unga nima deb javob beriladi: +, «ok», «hop» -> «Rahmat, Ali aka». Hammasi bitta JSON faylda saqlanadi va keyingi xabardan boshlab ishlaydi.',
        'sections' => [
            'kinds' => 'Nima yozishadi, nima javob beramiz',
            'kinds_hint' => 'Turlar yuqoridan pastga tekshiriladi: kalit so\'zi xabarda bo\'lgan birinchi tur ishlaydi. Javob odamning tilida va ohangida, kartochkasiga qarab yuboriladi.',
            'language' => 'Javob tili',
            'behaviour' => 'Qachon javob berish',
            'placeholders' => 'O\'rniga qo\'yiladiganlar',
            'placeholders_hint' => 'Bosing - oxirgi tahrirlangan javobga qo\'yiladi.',
            'test' => 'Xabarni sinash',
            'file' => 'Fayl',
            'silence' => 'Javob bermasa',
            'opening' => 'Jarimadan oldin salom',
            'follow_up' => '«Bu ham»',
            'greetings' => 'Salomlashish',
        ],
        'tones' => [
            'plain' => 'Oddiy',
            'plain_hint' => 'Tengdosh va kichiklarga',
            'respectful' => 'Hurmat bilan',
            'respectful_hint' => 'Kattalarga',
        ],
        'kind' => [
            'title' => 'Tur :n',
            'name' => 'Nomi',
            'keywords' => 'Nima yozishadi',
            'keywords_hint' => 'Vergul yoki yangi qator bilan. Oxiridagi «*» - har qanday qo\'shimcha: клиент* - клиента, клиентом. Katta-kichik harf, apostrof (bo\'ldi = boldi), ё/е, ў/у, қ/к va cho\'zilgan harflar (okkk, ++) farq qilmaydi.',
            'answers' => 'Nima javob beramiz',
            'add_answer' => 'Javob',
            'remove_answer' => 'Javobni o\'chirish',
            'empty' => 'Bo\'sh - boshqa ohang, keyin boshqa til olinadi',
            'add' => 'Tur qo\'shish',
            'remove' => 'Turni o\'chirish',
            'move_up' => 'Yuqoriga',
            'move_down' => 'Pastga',
            'none' => 'Turlar yo\'q: hech kimga javob berilmaydi.',
            'random_hint' => 'Javob turning matnlari, GIF va ovozli xabarlari orasidan tasodifiy tanlanadi.',
            'max_words' => 'So\'zgacha',
            'max_words_hint' => 'Bo\'sh - :n, sozlamalardagidek. Izoh kutiladigan turlar uchun ko\'proq.',
        ],
        'fields' => [
            'enabled' => 'Shaxsiy xabarlarga javob berish',
            'enabled_hint' => 'O\'chiq: xabarlar jarimalarda saqlanaveradi, lekin hech kimga javob berilmaydi.',
            'scope_any' => 'Ularning har qanday xabariga',
            'scope_any_hint' => 'Operator yoki sales «ok» yozsa - jarima bo\'lsa ham, bo\'lmasa ham javob oladi.',
            'scope_penalty' => 'Faqat jarimaga javobga',
            'scope_penalty_hint' => 'Faqat jarima forward qilingandan keyin tez orada yozsa javob beriladi.',
            'penalty_window_minutes' => 'Jarimaga javob, daq',
            'penalty_window_minutes_hint' => 'Jarimadan keyin shu vaqt ichidagi xabar - unga javob: jarimada saqlanadi va bir marta javob oladi.',
            'cooldown_minutes' => 'Javoblar orasida pauza, daq',
            'cooldown_minutes_hint' => 'Shu vaqt ichida qayta javob berilmaydi: «ok», «ok», «ok» ga bitta «rahmat». 0 - pauzasiz.',
            'max_words' => 'Ko\'pi bilan so\'z',
            'max_words_hint' => 'Uzunroq xabar - bu suhbat, «ok» emas: javob berilmaydi.',
        ],
        'silence' => [
            'enabled' => 'Javob bermasa eslatish',
            'enabled_hint' => 'Odam jarimalarga e\'tibor bermasa: shuncha jarima ketma-ket javobsiz qolsa - eslatma. Ish vaqtida; undan keyin hisob qaytadan boshlanadi.',
            'after_penalties' => 'Javobsiz jarimalardan keyin',
            'after_penalties_hint' => 'Uning shaxsiyga yozgan har qanday xabari hisobni nolga tushiradi.',
            'answers' => 'Eslatma',
        ],
        'opening' => [
            'enabled' => 'Birinchi bo\'lib salomlashish',
            'enabled_hint' => 'Kunning birinchi jarimasidan oldin - avval alohida xabar bilan «Assalomu alaykum, Ali aka», bir-ikki soniyadan keyin jarima. Bugun o\'zi birinchi salom bergan bo\'lsa - kerak emas; uning «Va alaykum assalom»iga javob berilmaydi.',
            'answers' => 'Salom',
        ],
        'follow_up' => [
            'enabled' => 'Bir xil gapni takrorlamaslik',
            'enabled_hint' => 'Odamga yaqinda shu darajadagi izoh («statusni yangilang») ketgan bo\'lsa, keyingi jarima qisqa «Bu ham» / «Bunisi ham» bilan, bir nechtasi - «Bularni ham unutmang» bilan boradi. Tirik odamga o\'xshaydi.',
            'window_minutes' => 'Yaqinda - bu necha daqiqa',
            'window_minutes_hint' => 'Shu odamga ketgan oxirgi izohdan hisoblanadi.',
            'one' => 'Bitta jarima',
            'many' => 'Bir nechta jarima birdan',
        ],
        'greetings' => [
            'enabled' => 'Salomga alik olish',
            'enabled_hint' => '«Доброе утро. Готово» - avval alohida javob bilan «Доброе утро, Анна!», bir-ikki soniyadan keyin turning javobi. Faqat salom bo\'lsa ham alik olinadi; savoldan oldingi salomga - yo\'q. Har bir odamga kuniga bir marta.',
            'title' => 'Salom :n',
            'add' => 'Salom qo\'shish',
            'remove' => 'Salomni o\'chirish',
            'none' => 'Salomlar yo\'q: hech kimga alik olinmaydi.',
            'order_hint' => 'Yuqoridan pastga tekshiriladi: xabarda birinchi topilgan salom javobni tanlaydi. Matn, GIF yoki ovozli xabar - tasodifiy bittasi.',
            'fillers' => 'Salomdan keyin hisobga olinmaydigan so\'zlar',
            'fillers_hint' => '«Assalomu alaykum aka 🙂» - shunchaki salom: unga alik olinadi. Vergul yoki yangi qator bilan.',
        ],
        'media' => [
            'title' => 'GIF va ovozli xabarlar',
            'gifs' => 'GIF',
            'gif_hint' => 'Barcha tillar uchun · Telegramdan yoki .gif, .mp4',
            'voices' => 'Ovozli xabarlar: :language',
            'voice_hint' => 'Faqat shu til uchun · .ogg',
            'add_gif' => 'GIF',
            'add_voice' => 'Ovozli xabar',
            'remove' => 'O\'chirish',
            'uploading' => 'Yuklanmoqda...',
            'failed' => 'Fayl yuklanmadi',
            'add_telegram' => 'Telegramdan',
            'file_badge' => 'Fayl',
            'file_badge_hint' => 'Fayl qilib yuklangan: video bo\'lib borishi mumkin',
            'telegram_badge' => 'Telegram',
            'telegram_badge_hint' => 'Telegramdagi GIF: haqiqiy GIF bo\'lib boradi',
            'telegram_title' => 'Telegramdan GIF',
            'telegram_search' => 'GIF qidirish: ok, rahmat, thumbs up',
            'telegram_search_hint' => 'Telegramdagi GIF bo\'limidagidek qidiruv. Bo\'sh qidiruv - akkauntning saqlangan GIF\'lari.',
            'telegram_saved' => 'Saqlangan GIF',
            'telegram_searching' => 'Telegramdan qidirilmoqda...',
            'telegram_empty' => 'Hech narsa topilmadi',
            'telegram_more' => 'Yana',
            'telegram_timeout' => 'Tinglovchi javob bermadi - qidiruv u orqali ishlaydi. U ishga tushganmi?',
            'telegram_failed' => 'Telegram javob bermadi: :error',
            'telegram_gone' => 'Bu GIF endi topilmaydi - qaytadan qidiring',
            'telegram_pick' => 'Tanlash',
        ],
        'placeholders' => [
            'address' => 'Murojaat (kartochkadan)',
            'name' => 'Odamning ismi',
            'request' => 'Jarima so\'rovi (jarima bo\'lmasa «—»)',
        ],
        'test' => [
            'placeholder' => 'Nima yozishi mumkin',
            'match' => 'Tur: :kind',
            'none' => 'Hech narsa mos kelmadi - javob yo\'q',
            'too_long' => ':n so\'zdan uzun - javob yo\'q',
            'answer' => 'Oladi',
            'as' => 'Kimdek',
            'media' => 'Yana GIF: :gifs, ovozli xabar: :voices - ular ham tushishi mumkin',
            'greeting' => 'Salom: :greeting - avval alik olinadi',
            'greeting_only' => 'Salom: :greeting - faqat alik olinadi',
            'greeted_once' => 'Kuniga bir marta: bugun alik olingan odam faqat turning javobini oladi',
        ],
        'file' => [
            'customised' => 'Faylga saqlangan',
            'default' => 'Standart - fayl hali yo\'q',
            'download' => 'JSON yuklab olish',
            'reset' => 'Standartga qaytarish',
            'reset_confirm' => 'Yana bosing: fayl o\'chiriladi',
            'broken' => 'Fayl o\'qilmayapti (:error). Qo\'lda tuzatilmaguncha yoki shu yerdan qayta saqlanmaguncha hech kimga javob berilmaydi.',
        ],
        'actions' => [
            'save' => 'Saqlash',
            'saving' => 'Saqlanmoqda...',
            'discard' => 'O\'zgarishlarni bekor qilish',
            'dirty' => 'Saqlanmagan o\'zgarishlar bor',
        ],
        'messages' => [
            'saved' => 'Faylga saqlandi',
            'reset' => 'Fayl o\'chirildi, standart qiymatlar qaytdi',
            'failed' => 'Nimadir xato ketdi',
            'leave' => 'Saqlanmagan o\'zgarishlar bor',
        ],
        'validation' => [
            'keywords' => 'Turda kamida bitta kalit so\'z bo\'lishi kerak',
            'answers' => 'Turda kamida bitta javob bo\'lishi kerak: matn, GIF yoki ovozli xabar',
            'fix' => 'Belgilangan maydonlarni tuzating',
            'silence' => 'Yoqilgan eslatmada kamida bitta matn, GIF yoki ovozli xabar bo\'lishi kerak',
            'opening' => 'Yoqilgan salomda kamida bitta matn, GIF yoki ovozli xabar bo\'lishi kerak',
            'follow_up' => 'Yoqilgan «Bu ham»da bitta jarima uchun kamida bitta matn bo\'lishi kerak',
            'media_gif' => 'GIF - .gif yoki .mp4 fayl',
            'media_gif_sound' => 'Bu .mp4 da ovoz bor: Telegram uni GIF emas, video qilib ko\'rsatadi. Ovozsizini yuklang',
            'media_voice' => 'Ovozli xabar - .ogg (Opus) fayl, Telegram yozgandek',
            'greeting_keywords' => 'Salomda kamida bitta kalit so\'z bo\'lishi kerak',
            'greeting_answers' => 'Salomda kamida bitta javob bo\'lishi kerak: matn, GIF yoki ovozli xabar',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Kuzatiladigan chatlar
    |--------------------------------------------------------------------------
    |
    | Listener tinglaydigan guruhlar. Ro'yxatda bitta chat, bir nechtasi yoki
    | umuman bo'lmasligi mumkin; ro'yxatni panel emas, listener o'qiydi.
    |
    */

    'chats' => [

        'title' => 'Kuzatiladigan chatlar',

        'description' => 'Haydovchi tekshiruvi tinglaydigan guruhlar. Bitta, bir nechta yoki umuman yo\'q.',

        'notice' => 'Havolani listener o\'zi aniqlaydi, shuning uchun yangi chat bir daqiqagacha vaqt oladi.',

        'create' => 'Chat qo\'shish',

        'search_placeholder' => 'Havola, nom yoki chat ID',

        'stats' => [
            'total' => 'Chatlar',
            'active' => 'Yoqilgan',
            'watching' => 'Kuzatilmoqda',
            'failing' => 'Xatolar',
        ],

        'filters' => [
            'status' => 'Holat',
            'status_all' => 'Barchasi',
            'status_active' => 'Yoqilgan',
            'status_inactive' => 'To\'xtatilgan',
            'resolved' => 'Aniqlash',
            'resolved_all' => 'Barchasi',
            'resolved_yes' => 'Aniqlangan',
            'resolved_no' => 'Kutilmoqda',
        ],

        'table' => [
            'chat' => 'Chat',
            'peer' => 'Chat ID',
            'status' => 'Holat',
            'checks' => 'Tekshiruvlar',
            'counts' => 'Statistika',
            'penalties' => 'Jarimalar',
            'files' => 'Fayllar',
            'last_message' => 'Oxirgi xabar',
            'actions' => 'Amallar',
            'no_link' => 'ID orqali qo\'shilgan',
            'no_id' => 'Hali aniqlanmagan',
            'never' => 'Hali yo\'q',
            'edit' => 'Tahrirlash',
            'source_env' => '.env dan',
        ],

        'status' => [
            'watching' => 'Kuzatilmoqda',
            'pending' => 'Aniqlanmoqda',
            'failed' => 'Xato',
            'paused' => 'To\'xtatilgan',
        ],

        'form' => [
            'create_title' => 'Yangi chat',
            'edit_title' => 'Chatni tahrirlash',
            'chat' => 'Havola yoki chat ID',
            'chat_hint' => 'Taklif havolasi (t.me/+...), @username yoki raqamli ID (-100...)',
            'title' => 'Nomi',
            'title_hint' => 'Faqat panel uchun. Bo\'sh qoldirilsa, listener Telegramdan oladi',
            'is_active' => 'Bu chatni kuzatish',
            'is_active_hint' => 'O\'chirilsa, listener guruhni e\'tiborsiz qoldiradi, lekin unutmaydi',
            'save' => 'Saqlash',
            'cancel' => 'Bekor qilish',
            'saving' => 'Saqlanmoqda...',
            'delete' => 'O\'chirish',
        ],

        'validation' => [
            'format' => 't.me havolasi, @username yoki raqamli chat ID kiriting',
            'duplicate' => 'Bu chat ro\'yxatda allaqachon bor',
        ],

        'confirm' => [
            'delete_title' => 'Bu chat o\'chirilsinmi?',
            'delete_text' => 'Listener uni tinglashni to\'xtatadi. Yaratilgan tekshiruvlar saqlanib qoladi.',
            'delete' => 'O\'chirish',
            'cancel' => 'Bekor qilish',
        ],

        'messages' => [
            'created' => 'Chat qo\'shildi',
            'updated' => 'O\'zgarishlar saqlandi',
            'deleted' => 'Chat o\'chirildi',
        ],

        'deleted' => 'Chat o\'chirildi',

        'errors' => [
            'title' => 'Xato',
            'load' => 'Chatlarni yuklab bo\'lmadi',
            'load_failed' => 'Chatlarni yuklab bo\'lmadi',
            'save' => 'Chatni saqlab bo\'lmadi',
            'delete' => 'Chatni o\'chirib bo\'lmadi',
            'resolve' => 'Aniqlash xatosi',
        ],

        'empty' => [
            'title' => 'Hozircha chat yo\'q',
            'description' => 'Xabarlari tekshiriladigan guruhni qo\'shing. Usiz listener ishlaydi, lekin jim turadi.',
        ],
    ],

    'listeners' => [
        'title' => 'Listenerlar',
        'description' => 'Driver Check yonida o\'z jarayoni bor akkauntlar: CEO, Head Operation va boshqalar. Har birining o\'z vazifalari bor.',
        'notice' => 'Bitta akkaunt — bitta jarayon: Telegram sessiyasini faqat u ushlab turadi, shuning uchun bu akkauntlar raqam tekshirish, tarqatma va kontaktlarni sinxronlash uchun ishlatilmaydi. Nom va kalitni o\'zingiz yozasiz. Barcha akkauntlar «Jarayonlar» sahifasidagi bitta tugma bilan ishga tushiriladi; bu yerda yoqilgan listener o\'zi qo\'shiladi.',
        'owners' => [
            'title' => 'Kim nima qiladi',
            'description' => 'Har bir vazifani bitta akkaunt bajaradi. Odatda hammasini Driver Check qiladi; boshqa akkauntni tanlang, vazifa bir necha soniyada unga o\'tadi.',
            'fixed' => [
                'name' => 'Guruhlarni o\'qish va tekshiruv hisobotlari',
                'hint' => 'Guruhlarni o\'qiydi, haydovchi tekshiruvlari va guruhdagi jarimalarni yozadi. Driver Checkda qoladi',
            ],
            'duties' => [
                'penalty_intake' => [
                    'name' => 'Jarimalarni qabul qilish',
                    'hint' => 'Bot jarimalarni yozadigan guruhlarni o\'qiydi va yozib oladi. Ularni pastda (rollar bo\'yicha) tanlangan akkaunt yuboradi. Akkaunt shu guruhlarda a\'zo bo\'lishi kerak',
                ],
                'penalties' => [
                    'name' => 'Jarimalar',
                    'hint' => 'Jarimani javobgarga yuborish, seriyadan keyingi izoh, eslatma va uning shaxsiy javoblariga avtojavoblar. Akkaunt jarimalar yuboriladigan guruhlarda a\'zo bo\'lishi kerak',
                ],
                'file_routes' => [
                    'name' => 'Botlardan fayllar',
                    'hint' => 'Bildirishnoma botining ertalabki fayllarini turlari bo\'yicha guruhlarga yuborish. Akkaunt bot bilan chatda bo\'lishi kerak',
                ],
            ],
            'roles' => [
                'title' => 'Rollar bo\'yicha jarimalar',
                'hint' => 'Sotuvchilar jarimalarini sotuv boshlig\'ining akkaunti, operatorlarniki — operatorlar boshlig\'ining akkaunti yuborishi mumkin. Shaxsiy javoblarga ham shu akkaunt javob beradi. «Odatiy» — yuqoridagi «Jarimalar» vazifasi kabi.',
                'default' => 'Odatiy («Jarimalar» kabi)',
                'now' => 'Hozir yuboradi:',
                'names' => [
                    'sales' => 'Sotuvchilar (Sales)',
                    'operation' => 'Operatorlar (Operation)',
                ],
            ],
            'files' => [
                'name' => 'Turlar bo\'yicha fayllar',
                'hint' => 'Har bir fayl turi uchun alohida yuboruvchi akkaunt va alohida guruhlar tanlanadi',
                'link' => 'Ochish',
            ],
            'scheduled' => [
                'name' => 'Jadval bo\'yicha xabarlar',
                'hint' => 'Yuboruvchi har bir xabar uchun alohida tanlanadi',
                'link' => 'Ochish',
            ],
            'fallback' => 'Tanlangan listener o\'chiq yoki akkauntsiz: hozircha vazifani Driver Check bajaradi.',
            'idle' => 'Bu listenerning jarayoni ishlamayapti: u ishga tushmaguncha vazifa bajarilmaydi.',
            'saved' => 'Saqlandi',
        ],

        'processes' => [
            'title' => 'Jarayonlar',
            'running' => 'Umumiy watchdog ishlayapti',
            'stopped' => 'Umumiy watchdog ishga tushmagan',
            'start' => 'Hammasini ishga tushirish',
            'restart_all' => 'Hammasini qayta yoqish',
            'stop' => 'Hammasini to\'xtatish',
            'restart_one' => 'Qayta yoqish',
            'hint' => 'Bitta watchdog barcha yoqilgan listenerlarni va Driver Checkni ishga tushiradi va kuzatadi. Pastda yoqilgan yangi listener bir necha soniyada o\'zi qo\'shiladi. «Hammasini to\'xtatish» shu watchdog ishga tushirgan hammasini to\'xtatadi.',
            'no_worker' => 'Navbat (queue worker) ishlamayapti, shuning uchun «Hammasini ishga tushirish» ishlamaydi. Watchdogni serverda bir marta ishga tushiring:',
            'moved' => 'Barcha sessiyalarni ishga tushirish, qayta yoqish va to\'xtatish «Jarayonlar» sahifasida.',
            'moved_link' => 'Ochish',
            'manage' => 'Listenerlar va akkauntlarni sozlash',
            'none' => 'Hozircha listenerlar yo\'q.',
            'accounts_running' => ':total tadan :running tasi ishlayapti',
            'no_account' => 'Akkaunt tanlanmagan',
            'driver_check_details' => 'Driver Check: tafsilotlar',
            'open_sessions' => 'Sessiyalarni ochish',
            'watchdog_up' => 'Watchdog ishlayapti',
            'watchdog_down' => 'Watchdog ishga tushmagan',
            'last_error' => 'Oxirgi ishga tushirish xatosi',
            'summary' => [
                'loading' => ['title' => 'Yuklanmoqda…', 'hint' => ''],
                'empty' => ['title' => 'Yoqilgan akkauntlar yo\'q', 'hint' => '«Listenerlar» sahifasida listenerni yoqing yoki Driver Checkni ishga tushiring.'],
                'all' => ['title' => 'Barcha akkauntlar ishlayapti', 'hint' => ':total tadan :running tasi ishlayapti.'],
                'partial' => ['title' => 'Hamma akkaunt ishlamayapti', 'hint' => ':total tadan :running tasi ishlayapti. Sababi pastda akkaunt yonida ko\'rsatilgan.'],
                'none' => ['title' => 'Hech narsa ishlamayapti', 'hint' => ':total tadan 0 tasi ishlayapti. «Hammasini ishga tushirish»ni bosing.'],
            ],
            'blockers' => [
                'no_account' => 'Listenerga akkaunt tanlanmagan.',
                'not_authorized' => 'Akkaunt avtorizatsiya qilinmagan: sessiyalar sahifasida unga kiring.',
                'no_session' => 'Serverda bu akkauntning sessiya fayli yo\'q: sessiyalar sahifasida qayta avtorizatsiya qiling.',
            ],
            'confirm' => [
                'stop_title' => 'Hammasi to\'xtatilsinmi?',
                'stop_text' => 'Telegramning barcha jarayonlari, shu jumladan Driver Check ham to\'xtatiladi: «Hammasini ishga tushirish»ni bosmaguningizcha guruh xabarlari qayta ishlanmaydi.',
                'restart_title' => 'Hammasi qayta yoqilsinmi?',
                'restart_text' => 'Barcha akkauntlar qayta yoqiladi: bir necha soniya xabarlar qayta ishlanmaydi.',
                'confirm' => 'Tasdiqlash',
            ],
            'exit_code' => 'jarayon :code kodi bilan xabarsiz tugadi',
            'tried' => 'Watchdog bu akkauntni :when ishga tushirgan, lekin jarayon ishlamayapti va xato qoldirmagan. Serverda qo\'lda ishga tushiring: php artisan telegram:listener <kalit> - sababi shu yerda ko\'rinadi.',
            'never_tried' => 'Watchdog bu akkauntni hali ishga tushirishga urinmagan. Ehtimol u kodning eski versiyasida ishlayapti: «Hammasini to\'xtatish», keyin «Hammasini ishga tushirish»ni bosing.',
            'start_timeout' => 'Ishga tushirish 20 soniyada bajarilmadi. U navbat orqali ketadi: serverda telegram navbatini qayta ishlaydigan queue (queue:work yoki queue:listen) ishlayotganini tekshiring va storage/logs/listeners-watchdog.log ni ko\'ring.',
            'messages' => [
                'done' => 'Tayyor',
                'started' => 'Ishga tushirildi, bir necha soniyada hammasi ko\'tariladi',
                'start_queued' => 'Ishga tushirish navbatga qo\'yildi, bir necha soniyada hammasi ko\'tariladi',
                'already_running' => 'Umumiy watchdog allaqachon ishlayapti',
                'restart_requested' => 'Qayta yoqish so\'raldi, jarayonlar bir necha soniyada qaytadi',
                'stop_requested' => 'To\'xtatish so\'raldi',
                'not_running' => 'Umumiy watchdog ishga tushmagan: «Hammasini ishga tushirish»ni bosing',
                'unknown' => 'Bunday listener yo\'q',
            ],
        ],

        'create' => 'Listener qo\'shish',
        'table' => [
            'account' => 'Akkaunt',
            'duties' => 'Vazifalar',
            'seen' => 'Oxirgi faollik',
            'edit' => 'O\'zgartirish',
            'no_account' => 'Akkaunt tanlanmagan',
            'no_duties' => 'Hech narsa qilmaydi',
            'never' => 'Hali bo\'lmagan',
            'run_hint' => 'Ishga tushmagan. «Jarayonlar» sahifasida «Hammasini ishga tushirish» tugmasi bilan ishga tushiring.',
        ],
        'state' => [
            'running' => 'Ishlayapti',
            'starting' => 'Ishga tushmoqda',
            'stopped' => 'Ishlamayapti',
            'off' => 'O\'chirilgan',
        ],
        'duties' => [
            'scheduled_messages' => 'Jadval bo\'yicha xabarlar',
        ],
        'duty_hints' => [
            'scheduled_messages' => 'Shu akkaunt tanlangan xabarlarni guruhlarga yozadi',
        ],
        'form' => [
            'create_title' => 'Yangi listener',
            'edit_title' => 'Listenerni o\'zgartirish',
            'key' => 'Kalit',
            'key_hint' => 'Lotin harflari, raqamlar va «_». Jarayonni nomlaydi, keyin o\'zgarmaydi',
            'name' => 'Nomi',
            'account' => 'Akkaunt',
            'account_hint' => '«Telegram sessiyalari»dagi hali hech kimga berilmagan akkauntlar. Driver Check akkaunti bu yerda ko\'rsatilmaydi',
            'no_account' => 'Tanlanmagan',
            'duties' => 'Vazifalar',
            'is_enabled' => 'Yoqilgan',
            'is_enabled_hint' => 'O\'chirilgan listener to\'xtaydi, akkaunti boshqa jarayonlarga qayta ochiladi',
            'save' => 'Saqlash',
            'cancel' => 'Bekor qilish',
            'saving' => 'Saqlanmoqda...',
            'delete' => 'O\'chirish',
        ],
        'confirm' => [
            'delete_title' => 'Listener o\'chirilsinmi?',
            'delete_text' => 'Jarayon to\'xtaydi, uning jadval bo\'yicha xabarlari Driver Check akkauntiga o\'tadi.',
            'delete' => 'O\'chirish',
            'cancel' => 'Bekor qilish',
        ],
        'messages' => [
            'created' => 'Listener qo\'shildi',
            'updated' => 'O\'zgarishlar saqlandi',
            'deleted' => 'Listener o\'chirildi',
            'copied' => 'Buyruq nusxalandi',
        ],
        'errors' => [
            'title' => 'Xato',
            'load' => 'Listenerlarni yuklab bo\'lmadi',
            'save' => 'Saqlab bo\'lmadi',
            'delete' => 'O\'chirib bo\'lmadi',
            'driver_check_account' => 'Bu Driver Check akkaunti, uning o\'z jarayoni bor',
        ],
        'empty' => [
            'title' => 'Hozircha listenerlar yo\'q',
            'description' => 'CEO yoki Head Operation akkauntini qo\'shing: avval uni «Telegram sessiyalari» sahifasida kiriting.',
        ],
    ],

    'scheduled' => [
        'title' => 'Jadval bo\'yicha xabarlar',
        'description' => 'Listener akkaunti tanlangan guruhlarga belgilangan vaqtda o\'zi yozadi, masalan har kuni 17:00 da «Отчет эсдан чикмасин».',
        'notice' => 'Xabarlar listener akkaunti nomidan yuboriladi, u guruhlarda a\'zo bo\'lishi kerak. Vaqt server vaqti bo\'yicha. Listener o\'chiq bo\'lgan bo\'lsa, xabar yoqilganda yuboriladi, lekin 2 soatdan kech emas.',
        'create' => 'Xabar qo\'shish',
        'table' => [
            'groups' => 'Guruhlar',
            'next' => 'Keyingi yuborish',
            'last' => 'Oxirgi yuborish',
            'edit' => 'O\'zgartirish',
            'send_now' => 'Hozir yuborish',
            'sender' => 'Kimdan',
            'no_groups' => 'Guruhlar tanlanmagan',
            'never' => 'Hali yuborilmagan',
            'queued' => 'Yuborilmoqda…',
        ],
        'status' => [
            'active' => 'Yoqilgan',
            'paused' => 'O\'chirilgan',
        ],
        'last_status' => [
            'sent' => 'Yuborildi',
            'partial' => 'Qisman',
            'failed' => 'Xato',
        ],
        'next' => [
            'off' => 'O\'chirilgan',
            'no_groups' => 'Guruh yo\'q',
        ],
        'days' => [
            'every_day' => 'Har kuni',
            'short' => ['Du', 'Se', 'Ch', 'Pa', 'Ju', 'Sh', 'Ya'],
        ],
        'form' => [
            'create_title' => 'Yangi xabar',
            'edit_title' => 'Xabarni o\'zgartirish',
            'name' => 'Nomi',
            'name_hint' => 'Faqat panel uchun',
            'text' => 'Xabar matni',
            'time' => 'Vaqt',
            'days' => 'Hafta kunlari',
            'groups' => 'Guruhlar',
            'groups_hint' => 'Xabar yuboriladigan guruhlarni belgilang. «Fayllar»dagi guruhlar ko\'rsatilgan, boshqasini ID orqali qo\'shish mumkin.',
            'add_group' => 'Qo\'shish',
            'sender' => 'Yuboruvchi',
            'sender_hint' => 'Qaysi akkauntdan yoziladi. Boshqa akkauntlar listenerda bu vazifa yoqilganda paydo bo\'ladi.',
            'group_invalid' => 'Guruh ID raqam bo\'lishi kerak, masalan -1004431008937',
            'is_active' => 'Yuborish',
            'is_active_hint' => 'O\'chirilsa, xabar yuborilmaydi',
            'save' => 'Saqlash',
            'cancel' => 'Bekor qilish',
            'saving' => 'Saqlanmoqda...',
            'delete' => 'O\'chirish',
        ],
        'confirm' => [
            'send_title' => 'Hozir yuborilsinmi?',
            'send_text' => 'Xabar bir necha soniyada tanlangan guruhlarga yuboriladi. Jadval o\'zgarmaydi.',
            'send' => 'Yuborish',
            'delete_title' => 'Xabar o\'chirilsinmi?',
            'delete_text' => 'U endi yuborilmaydi.',
            'delete' => 'O\'chirish',
            'cancel' => 'Bekor qilish',
        ],
        'messages' => [
            'created' => 'Xabar qo\'shildi',
            'updated' => 'O\'zgarishlar saqlandi',
            'deleted' => 'Xabar o\'chirildi',
            'queued' => 'Yuborish boshlandi',
        ],
        'errors' => [
            'title' => 'Xato',
            'load' => 'Xabarlarni yuklab bo\'lmadi',
            'save' => 'Saqlab bo\'lmadi',
            'delete' => 'O\'chirib bo\'lmadi',
            'send' => 'Yuborib bo\'lmadi',
        ],
        'empty' => [
            'title' => 'Hozircha xabarlar yo\'q',
            'description' => 'Birinchisini qo\'shing, masalan kerakli guruhlarga 17:00 da «Отчет эсдан чикмасин».',
        ],
    ],

    'file_routes' => [

        'title' => 'Fayllar',

        'description' => 'Bildirishnoma boti har kuni ertalab yuboradigan fayllar: qaysi turi qaysi guruhlarga forward qilinadi.',

        'notice' => 'Fayl turi izoh yoki fayl nomidagi kalit so\'z orqali aniqlanadi, ro\'yxatda yuqoridagi tur birinchi tekshiriladi. Listener akkaunti tanlangan guruhlarda a\'zo bo\'lishi kerak, aks holda Telegram forwardni rad etadi.',

        'source' => 'Manba chat',

        'create' => 'Tur qo\'shish',

        'table' => [
            'name' => 'Tur',
            'keywords' => 'Kalit so\'zlar',
            'targets' => 'Guruhlar',
            'sent' => 'Yuborildi',
            'last_sent' => 'Oxirgi',
            'sender' => 'Yuboruvchi',
            'status' => 'Holat',
            'actions' => 'Amallar',
            'edit' => 'Tahrirlash',
            'no_targets' => 'Guruh tanlanmagan',
            'never' => 'Hali yo\'q',
        ],

        'status' => [
            'active' => 'Yoqilgan',
            'paused' => 'To\'xtatilgan',
        ],

        'recent' => [
            'title' => 'Oxirgi fayllar',
            'description' => 'Oxirgi 50 ta yuborish. Rad etilgani daqiqada bir qayta uriniladi (3 martagacha).',
            'time' => 'Vaqt',
            'file' => 'Fayl',
            'route' => 'Tur',
            'target' => 'Guruh',
            'status' => 'Holat',
            'empty' => 'Hali fayl kelmagan',
            'no_route' => 'Aniqlanmadi',
        ],

        'forward_status' => [
            'sent' => 'Yuborildi',
            'failed' => 'Xato',
            'unmatched' => 'Tur topilmadi',
        ],

        'form' => [
            'create_title' => 'Yangi fayl turi',
            'edit_title' => 'Fayl turini tahrirlash',
            'name' => 'Nomi',
            'name_hint' => 'Faqat panel uchun',
            'keywords' => 'Kalit so\'zlar',
            'keywords_hint' => 'Vergul bilan. Izoh yoki fayl nomida shulardan biri bo\'lsa, fayl shu turga tegishli. Katta-kichik harf, chiziqcha va qavslar ahamiyatsiz',
            'targets' => 'Guruhlar ID si',
            'targets_hint' => 'Har qatorda bitta, masalan -5524265426 yoki -1004431008937',
            'sender' => 'Yuboruvchi akkaunt',
            'sender_hint' => 'Bu turdagi fayl qaysi akkauntdan yuboriladi. U bot chatida va maqsad guruhlarda bo\'lishi kerak',
            'sender_default' => 'Odatiy («Botlardan fayllar» kabi)',
            'is_active' => 'Forward qilish',
            'is_active_hint' => 'O\'chirilsa, bu turdagi fayllar hech qayerga yuborilmaydi',
            'save' => 'Saqlash',
            'cancel' => 'Bekor qilish',
            'saving' => 'Saqlanmoqda...',
            'delete' => 'O\'chirish',
        ],

        'confirm' => [
            'delete_title' => 'Bu fayl turi o\'chirilsinmi?',
            'delete_text' => 'Bunday fayllar endi forward qilinmaydi. Yuborilganlar jurnalda qoladi.',
            'delete' => 'O\'chirish',
            'cancel' => 'Bekor qilish',
        ],

        'messages' => [
            'created' => 'Fayl turi qo\'shildi',
            'updated' => 'O\'zgarishlar saqlandi',
            'deleted' => 'Fayl turi o\'chirildi',
        ],

        'validation' => [
            'chat_id' => 'Guruh ID si raqam bo\'lishi kerak, masalan -1004431008937',
        ],

        'errors' => [
            'title' => 'Xato',
            'load' => 'Fayl turlarini yuklab bo\'lmadi',
            'save' => 'Saqlab bo\'lmadi',
            'delete' => 'O\'chirib bo\'lmadi',
        ],

        'empty' => [
            'title' => 'Fayl turlari yo\'q',
            'description' => 'php artisan db:seed --class=TelegramFileRouteSeeder yoki "Tur qo\'shish" tugmasi bilan qo\'shing.',
        ],
    ],

    'sessions' => [

        'title' => 'Telegram sessiyalari',

        'description' => 'Listener va raqam bo\'yicha qidiruv ishlaydigan barcha MadelineProto akkauntlari: kirish, tekshirish, chiqish.',

        'notice' => 'MadelineProto faqat konsoldan ishlaydi, shuning uchun har bir amal fon buyrug\'ini ishga tushiradi. Holat bir necha soniyada o\'zi yangilanadi.',

        'create' => 'Akkaunt qo\'shish',

        'search_placeholder' => 'Telefon, ism, @username yoki Telegram ID',

        'primary' => 'Listener',

        'primary_hint' => 'Bu akkaunt chatlarni tinglaydi (TELEGRAM_DRIVER_CHECK_ACCOUNT_ID)',

        'stats' => [
            'authorized' => 'Faol',
            'pending' => 'Kirish tugallanmagan',
            'problem' => 'Muammolar',
            'logged_out' => 'Chiqqan',
        ],

        'filters' => [
            'state' => 'Holat',
            'state_all' => 'Hammasi',
        ],

        'table' => [
            'account' => 'Akkaunt',
            'phone' => 'Telefon',
            'status' => 'Holat',
            'processes' => 'Jarayonlar',
            'last_checked' => 'Tekshirilgan',
            'authorized_at' => 'Kirgan sana',
            'actions' => 'Amallar',
        ],

        'fields' => [
            'telegram_id' => 'Telegram ID',
            'name' => 'Ism',
            'username' => 'Username',
            'session_file' => 'Sessiya fayli',
            'session_file_yes' => 'Diskda bor',
            'session_file_no' => 'Diskda yo\'q',
        ],

        'state' => [
            'listening' => 'Chatlarni tinglayapti',
            'stopped' => 'Listener to\'xtagan',
            'active' => 'Faol',
            'warning' => 'Faol, xato bor',
            'no_file' => 'Sessiya fayli yo\'q',
            'sending_code' => 'Kod yuborilmoqda',
            'awaiting_code' => 'Kod kutilmoqda',
            'verifying' => 'Tekshirilmoqda',
            'awaiting_password' => '2FA parol kutilmoqda',
            'checking' => 'Tekshirilmoqda',
            'logging_out' => 'Chiqilmoqda...',
            'stale' => 'Qotib qoldi',
            'code_invalid' => 'Kod noto\'g\'ri',
            'failed' => 'Kirish xatosi',
            'revoked' => 'Sessiya bekor qilingan',
            'logged_out' => 'Chiqqan',
            'new' => 'Avtorizatsiya qilinmagan',
        ],

        'state_hint' => [
            'listening' => 'Listener ishlayapti va shu sessiyani ushlab turibdi. Uni paneldan tekshirib bo\'lmaydi: profil listener har safar ishga tushganda yangilanadi.',
            'stopped' => 'Sessiya avtorizatsiyadan o\'tgan, lekin listener ishlamayapti. Chatlardagi xabarlar qayta ishlanmaydi.',
            'active' => 'Sessiya ishlayapti va jarayonlar uchun ochiq.',
            'warning' => 'Sessiya avtorizatsiyadan o\'tgan, lekin oxirgi so\'rov xato qaytardi. Tirikligini bilish uchun "Tekshirish"ni bosing.',
            'no_file' => 'Bazada akkaunt avtorizatsiyadan o\'tgan, lekin diskda sessiya fayli yo\'q: har qanday jarayon unda yiqiladi. Chiqib, qayta kiring.',
            'sending_code' => 'Fon buyrug\'i Telegramdan kod so\'ramoqda.',
            'awaiting_code' => 'Telegram kodni yubordi. Davom etish uchun uni kiriting.',
            'verifying' => 'Fon buyrug\'i kiritilgan ma\'lumotni tekshirmoqda.',
            'awaiting_password' => 'Akkauntda ikki bosqichli tekshiruv yoqilgan. Bulutli parol kerak.',
            'checking' => 'Fon buyrug\'i sessiya tirikligini Telegramdan so\'ramoqda.',
            'logging_out' => 'Fon buyrug\'i Telegramdagi sessiyani yakunlab, faylni o\'chirmoqda.',
            'stale' => 'Fon buyrug\'i javob bermadi. Ehtimol u yiqilgan - amalni qaytarish mumkin.',
            'code_invalid' => 'Kod mos kelmadi. Telegram shu kod bilan qayta urinishga ruxsat bermaydi: yangi kod so\'rang.',
            'failed' => 'Kirish muvaffaqiyatsiz. Qaytadan boshlash mumkin.',
            'revoked' => 'Telegram bu sessiyani endi qabul qilmaydi: u telefonda yakunlangan yoki akkaunt bloklangan. Qayta kiring.',
            'logged_out' => 'Sessiya yakunlangan. Akkauntga qayta kirish yoki uni o\'chirish mumkin.',
            'new' => 'Kirish hali boshlanmagan.',
        ],

        'processes' => [
            'names' => [
                'resolver_phone' => 'Raqam bo\'yicha qidiruv',
                'send_message' => 'Xabar yuborish',
                'driver_check' => 'Haydovchilarni tekshirish',
                'group_join' => 'Guruhlarga qo\'shilish',
                'group_invite' => 'Guruhlarga qo\'shish',
            ],
            'states' => [
                'ready' => 'Tayyor',
                'busy' => 'Band',
                'stuck' => 'Qotib qoldi',
                'failing' => 'Xatolar bor',
                'disabled' => 'O\'chirilgan',
            ],
            'none' => 'Ishlatilmagan',
            'none_hint' => 'Hali hech bir jarayon bu akkauntni olmagan. Jarayon uni birinchi marta tanlaganda qator paydo bo\'ladi.',
            'successes' => 'Muvaffaqiyatli',
            'failures' => 'Xatolar',
            'streak' => 'Ketma-ket',
            'disabled_reason' => 'Sabab',
            'stuck_hint' => ':time band deb belgilangan va bo\'shamagan. Belgini olib tashlash uchun jarayonni o\'chirib, qayta yoqing.',
            'enable' => 'Yoqish',
            'disable' => 'O\'chirish',
            'disabled_manually' => 'Panelda qo\'lda o\'chirilgan',
        ],

        'actions' => [
            'continue' => 'Kirishni davom ettirish',
            'login_again' => 'Qayta kirish',
            'check' => 'Tekshirish',
            'logout' => 'Chiqish',
            'delete' => 'O\'chirish',
        ],

        'login' => [
            'title' => 'Yangi akkaunt',
            'steps' => [
                'phone' => 'Telefon',
                'code' => 'Kod',
                'password' => '2FA parol',
            ],
            'phone' => 'Telefon raqami',
            'phone_hint' => 'Xalqaro formatda, mamlakat kodi bilan',
            'send_code' => 'Kod olish',
            'sending' => 'Kod so\'ralmoqda...',
            'verifying' => 'Tekshirilmoqda...',
            'waiting_hint' => 'Buyruq fonda ishlayapti. Oynani yopish mumkin - kirish ro\'yxatdan davom etadi.',
            'code' => 'Telegramdagi kod',
            'code_hint' => 'Shu raqamdagi Telegram ilovasiga (yoki SMS orqali) keladi',
            'code_warning' => 'Kodni hech kimga, hatto "Saqlanganlar"ga ham yubormang: Telegram uni darhol bekor qiladi.',
            'password' => 'Bulutli parol',
            'password_hint' => 'Shu akkauntning ikki bosqichli tekshiruv paroli',
            'hint' => 'Eslatma',
            'verify' => 'Tasdiqlash',
            'resend' => 'Kodni qayta yuborish',
            'done' => 'Akkaunt avtorizatsiyadan o\'tdi',
        ],

        'confirm' => [
            'logout_title' => 'Akkauntdan chiqilsinmi?',
            'logout_text' => 'Telegramdagi sessiya yakunlanadi, sessiya fayli o\'chiriladi. Jarayonlar bu akkauntdan foydalanmay qo\'yadi.',
            'logout_primary' => 'Bu listener akkaunti: chiqqandan keyin haydovchilarni tekshirish chatlardan xabar olmay qo\'yadi.',
            'delete_title' => 'Akkaunt o\'chirilsinmi?',
            'delete_text' => 'Yozuv va jarayonlar statistikasi o\'chiriladi. Akkauntda tirik sessiya yo\'q, Telegramda hech narsa o\'zgarmaydi.',
        ],

        'messages' => [
            'authorized' => 'Akkaunt avtorizatsiyadan o\'tdi',
            'check_started' => 'Tekshiruv boshlandi',
            'logout_started' => 'Chiqish boshlandi',
            'deleted' => 'Akkaunt o\'chirildi',
            'process_enabled' => 'Jarayon yoqildi',
            'process_disabled' => 'Jarayon o\'chirildi',
        ],

        'validation' => [
            'phone' => 'Raqamni xalqaro formatda kiriting, masalan +998901234567',
            'code' => 'Kod faqat raqamlardan iborat',
        ],

        'state_errors' => [
            'listener_running' => 'Hozir bu sessiyada listener ishlayapti. Uning profili listener ishga tushganda yangilanadi.',
            'process_busy' => 'Akkaunt hozir jarayon bilan band. U bo\'shaganda qayta urinib ko\'ring.',
            'already_authorized' => 'Bu akkaunt allaqachon avtorizatsiyadan o\'tgan.',
            'busy' => 'Akkaunt bilan fon buyrug\'i ishlayapti. Biroz kuting.',
            'not_waiting_code' => 'Akkaunt hozir kod kutmayapti. Sahifani yangilang.',
            'not_waiting_password' => 'Akkaunt hozir parol kutmayapti. Sahifani yangilang.',
            'not_authorized' => 'Akkaunt avtorizatsiyadan o\'tmagan.',
            'still_authorized' => 'Avval akkauntdan chiqing, keyin o\'chiring.',
        ],

        'telegram_errors' => [
            'PHONE_CODE_INVALID' => 'Kod noto\'g\'ri. Yangisini so\'rang.',
            'PHONE_CODE_EXPIRED' => 'Kod eskirgan. Yangisini so\'rang.',
            'PASSWORD_HASH_INVALID' => 'Parol noto\'g\'ri. Yana urinib ko\'ring.',
            'PASSWORD_EXPIRED' => 'Parol fon buyrug\'iga yetib bormadi. Uni qayta kiriting.',
            'PHONE_NUMBER_INVALID' => 'Telegram bu raqamni qabul qilmaydi.',
            'PHONE_NUMBER_BANNED' => 'Raqam Telegramda bloklangan.',
            'PHONE_NUMBER_FLOOD' => 'Bu raqam bilan juda ko\'p urinish bo\'ldi. Keyinroq urinib ko\'ring.',
            'FLOOD_WAIT' => 'Telegram keyingi urinishdan oldin kutishni so\'ramoqda.',
            'FLOOD_WAIT_SECONDS' => 'Telegram :seconds soniya kutishni so\'ramoqda.',
            'AUTH_KEY_UNREGISTERED' => 'Sessiya Telegramda yakunlangan.',
            'SESSION_REVOKED' => 'Sessiya Telegramda yakunlangan.',
            'USER_DEACTIVATED' => 'Akkaunt o\'chirilgan yoki bloklangan.',
            'SESSION_NOT_FOUND' => 'Sessiya fayli diskda topilmadi.',
            'NOT_LOGGED_IN' => 'Diskdagi sessiya avtorizatsiyadan o\'tmagan.',
            'ACCOUNT_NOT_REGISTERED' => 'Bu raqamda Telegram ro\'yxatdan o\'tmagan.',
        ],

        'errors' => [
            'title' => 'Xato',
            'load' => 'Sessiyalarni yuklab bo\'lmadi',
            'load_failed' => 'Sessiyalarni yuklab bo\'lmadi',
            'action' => 'Amalni bajarib bo\'lmadi',
        ],

        'empty' => [
            'title' => 'Hozircha akkauntlar yo\'q',
            'description' => 'Telegram akkaunt qo\'shing: listener va raqam bo\'yicha qidiruv shunda ishlaydi.',
        ],
    ],

    'contact_sync' => [
        'title' => 'Guruh kontaktlari',
        'description' => 'Kuniga bir marta akkauntlar berilgan guruhlarni ochib, a\'zolarni o\'z kontaktlariga saqlaydi. Bitta odam faqat bitta akkauntga tushadi.',
        'actions' => [
            'run' => 'Hozir ishga tushirish',
            'running' => 'Bajarilmoqda…',
        ],
        'errors' => [
            'title' => 'Yuklab bo\'lmadi',
            'load_failed' => 'Ma\'lumotni yuklab bo\'lmadi.',
            'action' => 'Amal bajarilmadi.',
        ],
        'totals' => [
            'users' => 'Topilgan odamlar',
            'added' => 'Kontaktga saqlangan',
            'failed' => 'Xato bilan',
            'unassigned' => 'Hali akkauntsiz',
            'accounts_needed' => 'bir kunda: :n ta akkaunt, :limit tadan',
        ],
        'groups' => [
            'title' => 'Guruhlar',
            'add' => 'Qo\'shish',
            'empty' => 'Guruhlar hali yo\'q. Yuqoriga havolani qo\'ying.',
            'run' => 'Ishga tushirish',
            'pause' => 'Pauza',
            'resume' => 'Yoqish',
            'delete' => 'O\'chirish',
            'people' => 'kishi',
            'waiting' => 'kutmoqda: :n',
            'waiting_hint' => 'Hali hech kimning kontaktida emas: sinxronizatsiya ularni saqlaydi',
            'not_waiting' => 'kutmayapti: :n',
            'not_waiting_hint' => '@username borlar (havola orqali topiladi, bazada qoladi), akkaunt kontaktida borlar, botlar',
            'in_telegram' => '(Telegramda: :n)',
            'in_telegram_hint' => 'Guruhni o\'qiganda Telegram shuncha a\'zo borligini aytdi; ro\'yxatni to\'liq bermadi',
            'fetch' => 'Odamlarni yig\'ish',
            'fetch_hint' => 'Guruh a\'zolarini bazaga yozish, hech kimni kontaktga qo\'shmasdan',
            'fetching' => 'Yig\'ilmoqda…',
            'confirm_delete' => 'Guruh o\'chirilsinmi? Topilgan odamlar qoladi.',
        ],
        'accounts' => [
            'title' => 'Akkauntlar',
            'hint' => 'Akkaunt uchun kunlik limit:',
            'account' => 'Akkaunt',
            'contacts' => 'Kontaktlar',
            'today' => 'Bugun',
            'failed' => 'Xatolar',
            'skipped' => 'Avvaldan bor',
            'last_added' => 'Oxirgi qo\'shilgan',
            'primary' => 'asosiy',
            'held' => 'Pauzada',
            'enable' => 'Yoqish',
        ],
        'log' => [
            'title' => 'Jurnal',
            'all_events' => 'Barcha hodisalar',
            'all_accounts' => 'Barcha akkauntlar',
            'empty' => 'Hozircha bo\'sh.',
        ],
        'events' => [
            'started' => 'Boshlandi',
            'members' => 'A\'zolar',
            'added' => 'Qo\'shildi',
            'skipped' => 'O\'tkazib yuborildi',
            'failed' => 'Xato',
            'flood' => 'Telegram limiti',
            'finished' => 'Yakunlandi',
            'joined' => 'Qo\'shildi',
            'disabled' => 'O\'chirilgan',
        ],
        'messages' => [
            'already_running' => 'Sinxronizatsiya allaqachon ketmoqda.',
            'run_queued' => 'Ishga tushirish navbatga qo\'yildi.',
            'bad_link' => 'Guruh havolasi noto\'g\'ri.',
            'group_deleted' => 'Guruh o\'chirildi.',
            'account_enabled' => 'Akkaunt yana ishda.',
            'fetch_queued' => 'Guruh a\'zolari bazaga yig\'ilmoqda.',
            'fetch_running' => 'Bu guruh a\'zolari allaqachon yig\'ilmoqda.',
        ],
    ],

    'watchdog' => [

        'title' => 'Jarayonlar',

        'description' => 'Telegramning barcha jarayonlari: bitta watchdog ostidagi barcha listener sessiyalari va Driver Check listeneri - ishlayaptimi, qaysi akkauntda va oxirgi nima qilgani.',

        'actions' => [
            'start' => 'Ishga tushirish',
            'restart' => 'Listenerni qayta ishga tushirish',
            'restart_short' => 'Qayta yoqish',
            'stop' => 'To\'xtatish',
        ],

        'state' => [
            'ok' => 'Ishlayapti',
            'unsupervised' => 'Listener nazoratsiz',
            'restarting' => 'Listener qayta ishga tushmoqda',
            'down' => 'To\'xtagan',
            'misconfigured' => 'Asosiy akkaunt tayyor emas',
            'unknown' => 'Holat noma\'lum',
        ],

        'state_hint' => [
            'ok' => 'Watchdog listenerni nazorat qilyapti, asosiy akkaunt avtorizatsiyadan o\'tgan.',
            'unsupervised' => 'Listener ishlayapti, lekin watchdog ishga tushmagan: listener yiqilsa, uni hech kim ko\'tarmaydi. "Ishga tushirish"ni bosing.',
            'restarting' => 'Watchdog ishlayapti, lekin listener hozir ishlamayapti: u hozirgina yiqilgan yoki keyingi urinishdan oldin pauzani kutyapti.',
            'down' => 'Na watchdog, na listener ishlayapti. Chatlardagi xabarlar qayta ishlanmaydi.',
            'misconfigured' => 'TELEGRAM_DRIVER_CHECK_ACCOUNT_ID berilmagan, akkaunt topilmadi yoki avtorizatsiyadan o\'tmagan. Uni "Telegram sessiyalari" sahifasida tekshiring.',
            'unknown' => 'Veb-server storage/app/telegram dagi lock-fayllarni o\'qiy olmayapti.',
        ],

        'processes' => [
            'title' => 'Jarayonlar',
            'watchdog' => 'Watchdog',
            'watchdog_hint' => 'Listenerni ishga tushiradi va yiqilgandan keyin ko\'taradi',
            'spare' => 'Zaxira watchdog',
            'spare_hint' => 'Kutib turadi va asosiysi yiqilsa, nazoratni o\'z qo\'liga oladi',
            'listener' => 'Listener',
            'listener_hint' => 'telegram:start-loop: asosiy akkaunt nomidan chatlarni tinglaydi',
            'running' => 'Ishlayapti',
            'stopped' => 'Ishlamayapti',
            'unknown' => 'Noma\'lum',
            'pid' => 'PID',
        ],

        'account' => [
            'title' => 'Asosiy akkaunt',
            'missing' => 'Akkaunt berilmagan',
            'missing_hint' => '.env da TELEGRAM_DRIVER_CHECK_ACCOUNT_ID ni ko\'rsating.',
            'not_found' => 'TELEGRAM_DRIVER_CHECK_ACCOUNT_ID dagi akkaunt topilmadi',
            'authorized' => 'Avtorizatsiyadan o\'tgan',
            'not_authorized' => 'Avtorizatsiyadan o\'tmagan',
            'session_missing' => 'Sessiya fayli diskda yo\'q',
            'status' => 'Holat',
            'open_sessions' => 'Sessiyalar',
        ],

        'activity' => [
            'title' => 'Faollik',
            'last_check' => 'Oxirgi xabar',
            'last_report' => 'Oxirgi hisobot',
            'checks_today' => 'Bugungi tekshiruvlar',
            'pending' => 'Jarayonda',
        ],

        'worker' => [
            'title' => 'Navbat workeri',
            'alive' => 'Javob beryapti',
            'dead' => 'Javob bermayapti',
            'dead_hint' => '"Ishga tushirish" navbatga vazifa qo\'yadi. Worker javob bermaguncha, uni hech kim bajarmaydi.',
            'start_queued' => 'Navbatda ishga tushirish kutyapti: :count',
            'open_queue' => 'Navbat',
        ],

        'restart' => [
            'title' => 'Oxirgi yiqilish',
            'reason' => 'Sabab',
            'exit_code' => 'Chiqish kodi',
            'uptime' => 'Ishladi',
            'restarts' => 'Qayta ishga tushirish №',
            'at' => 'Qachon',
            'detail' => 'Tafsilotlar',
            'marker' => 'Listener muammo haqida xabar berdi',
        ],

        'signals_unavailable' => 'Qayta ishga tushirish va to\'xtatish faqat watchdog ishlaydigan Linux serverda ishlaydi.',

        'auto_refresh' => 'Har 5 soniyada yangilanadi',

        'confirm' => [
            'restart_title' => 'Listener qayta ishga tushirilsinmi?',
            'restart_text' => 'Listener SIGTERM oladi va to\'g\'ri yakunlanadi, watchdog bir necha soniyada yangisini ishga tushiradi. Bu vaqtda xabarlar qayta ishlanmaydi.',
            'stop_title' => 'Watchdog va listener to\'xtatilsinmi?',
            'stop_text' => 'Watchdog listenerni to\'xtatadi va o\'zi ham yakunlanadi. Qayta ishga tushirilmaguncha haydovchilarni tekshirish ishlamaydi.',
            'confirm' => 'Ha, davom etish',
        ],

        'messages' => [
            'restart_queued' => 'Qayta ishga tushirish navbat workeriga topshirildi, listener bir necha soniyada qayta ishga tushadi',
            'stop_queued' => 'To\'xtatish navbat workeriga topshirildi, watchdog bir necha soniyada to\'xtaydi',
            'start_queued' => 'Watchdogni ishga tushirish navbatga qo\'yildi',
            'restart_sent' => 'Listener qayta ishga tushmoqda',
            'stop_sent' => 'Watchdog to\'xtamoqda',
        ],

        'errors' => [
            'title' => 'Xato',
            'load_failed' => 'Holatni olib bo\'lmadi',
            'action' => 'Amalni bajarib bo\'lmadi',
            'no_watchdog' => 'Watchdog ishlamayapti: to\'xtatilgan listenerni hech kim ko\'tarmaydi. Avval watchdogni ishga tushiring.',
            'not_running' => 'Jarayon ishlamayapti.',
            'pid_unknown' => 'Jarayon PID sini tasdiqlab bo\'lmadi. Signal yuborilmadi.',
            'signal_failed' => 'Signal yuborilmadi: uni yuborayotgan jarayonda watchdogga huquq yo\'q. Ehtimol, watchdog boshqa foydalanuvchi nomidan qo\'lda ishga tushirilgan.',
        ],
    ],

    'queue' => [

        'title' => 'Navbat',

        'description' => 'Panelning fon vazifalari: bot, resolver, akkauntlarga kirish, xabar yuborish. Hammasi telegram navbati orqali o\'tishi kerak.',

        'worker' => [
            'alive' => 'Worker ishlayapti',
            'dead' => 'Worker javob bermayapti',
            'never' => 'Worker hali bir marta ham javob bermagan',
            'dead_hint' => 'Vazifalar yig\'ilib qolyapti va bajarilmayapti. Serverda tekshiring: php artisan queue:work --queue=telegram',
            'never_hint' => 'Worker yangi kod bilan qayta ishga tushganda puls paydo bo\'ladi (php artisan queue:restart).',
            'pulse' => 'Oxirgi puls',
            'listens' => 'Tinglaydi',
            'last_processed' => 'Oxirgi bajarilgan',
            'last_failed' => 'Oxirgi yiqilgan',
        ],

        'stats' => [
            'ready' => 'Tayyor',
            'delayed' => 'Kechiktirilgan',
            'reserved' => 'Bajarilmoqda',
            'failed' => 'Yiqilgan',
            'failed_day' => 'bir kunda: :count',
        ],

        'queues' => [
            'title' => 'Navbatlar',
            'queue' => 'Navbat',
            'stuck' => 'Qotib qolgan',
            'oldest' => 'Eng uzoq kutgan',
            'main' => 'Asosiy',
            'not_listened' => 'Hech kim tinglamaydi',
            'not_listened_hint' => 'Bu navbatdagi vazifalarni hech kim bajarmaydi. Ularni telegram ga o\'tkazing.',
            'move' => 'telegram ga o\'tkazish',
        ],

        'classes' => [
            'title' => 'Bajarilishini kutayotganlar',
            'job' => 'Vazifa',
            'total' => 'Jami',
            'empty' => 'Navbat bo\'sh',
        ],

        'tabs' => [
            'pending' => 'Kutayotganlar',
            'failed' => 'Yiqilganlar',
        ],

        'filters' => [
            'search' => 'Vazifa nomi',
            'search_failed' => 'Vazifa yoki xato matni',
            'queue' => 'Navbat',
            'queue_all' => 'Barcha navbatlar',
            'state' => 'Holat',
            'state_all' => 'Hammasi',
        ],

        'state' => [
            'ready' => 'Tayyor',
            'delayed' => 'Kechiktirilgan',
            'reserved' => 'Bajarilmoqda',
            'stuck' => 'Qotib qolgan',
        ],

        'table' => [
            'job' => 'Vazifa',
            'queue' => 'Navbat',
            'state' => 'Holat',
            'attempts' => 'Urinishlar',
            'created' => 'Qo\'yilgan',
            'available' => 'Ishga tushadi',
            'error' => 'Xato',
            'failed_at' => 'Yiqilgan',
            'actions' => 'Amallar',
        ],

        'actions' => [
            'retry' => 'Qayta urinish',
            'retry_all' => 'Hammasini qayta',
            'delete' => 'O\'chirish',
            'flush' => 'Hammasini o\'chirish',
            'details' => 'Batafsil',
        ],

        'detail' => [
            'title' => 'Yiqilgan vazifa',
            'exception' => 'Xato',
            'uuid' => 'UUID',
        ],

        'confirm' => [
            'move_title' => 'Vazifalar telegram ga o\'tkazilsinmi?',
            'move_text' => '":queue" navbatidagi barcha kutayotgan vazifalar telegram ga o\'tadi va worker tomonidan bajariladi.',
            'retry_all_title' => 'Barcha yiqilgan vazifalar qayta ishga tushirilsinmi?',
            'retry_all_text' => 'Barcha yiqilgan vazifalar telegram navbatiga qaytadi. Ma\'lumotdagi xato sabab yiqilgan vazifa, ehtimol, yana yiqiladi.',
            'delete_title' => 'Yiqilgan vazifa o\'chirilsinmi?',
            'delete_text' => 'Vazifa qayta ishga tushirilmasdan o\'chiriladi.',
            'flush_title' => 'Barcha yiqilgan vazifalar o\'chirilsinmi?',
            'flush_text' => 'Barcha yiqilgan vazifalar qayta ishga tushirilmasdan o\'chiriladi. Buni bekor qilib bo\'lmaydi.',
            'confirm' => 'Ha, davom etish',
        ],

        'messages' => [
            'retried' => 'Navbatga qaytarildi: :count',
            'deleted' => 'O\'chirildi: :count',
            'moved' => 'telegram ga o\'tkazildi: :count',
        ],

        'errors' => [
            'title' => 'Xato',
            'load_failed' => 'Navbatni yuklab bo\'lmadi',
            'action' => 'Amalni bajarib bo\'lmadi',
            'retry' => 'Vazifani tiklab bo\'lmadi: :error',
        ],

        'empty' => [
            'pending_title' => 'Kutayotgan vazifalar yo\'q',
            'pending_description' => 'Navbatga qo\'yilgan hamma narsa bajarib bo\'lingan.',
            'failed_title' => 'Yiqilgan vazifalar yo\'q',
            'failed_description' => 'Barcha vazifalar xatosiz bajarildi.',
        ],

        'auto_refresh' => 'Umumiy ma\'lumot har 10 soniyada yangilanadi',
    ],

    'operation_users' => [

        'title' => 'Operatorlar',

        'description' => 'Telegram foydalanuvchilari va haydovchilar tekshiruvi statistikasi',

        'telegram' => 'Telegram',

        'refresh' => 'Yangilash',

        'loading' => 'Yuklanmoqda...',

        'open_user' => 'Foydalanuvchini ochish',

        'per_page' => 'Sahifada',

        'filters' => [

            'title' => 'Filtrlar',

            'description' => 'Operatorlarni qidirish va saralash',

            'reset' => 'Tozalash',

            'search' => 'Qidiruv',

            'search_placeholder' => 'Ism, username, Telegram ID...',

            'telegram_id' => 'Telegram ID',

            'telegram_id_placeholder' => 'Telegram ID kiriting',

            'username' => 'Username',

            'username_placeholder' => '@username',

            'sort' => 'Saralash',

            'created' => 'Yaratilgan sana',

            'updated' => 'Yangilangan sana',

            'name' => 'Ism',

            'drivers' => 'Haydovchilar',

            'checks' => 'Tekshiruvlar',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'match_rate' => 'Moslik darajasi',

            'average_score' => 'O‘rtacha ball',

            'last_check' => 'Oxirgi tekshiruv',

            'ascending' => 'O‘sish tartibida',

            'descending' => 'Kamayish tartibida',

            'apply' => 'Qidirish',

            'more_filters' => 'Ko‘proq filtr',

            'less_filters' => 'Filtrlarni yashirish',

            'status' => 'Tekshiruv holati',

            'status_all' => 'Har qanday holat',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'processing' => 'Jarayonda',

            'period' => 'Davr',

            'period_today' => 'Bugun',

            'period_week' => '7 kun',

            'period_month' => '30 kun',

            'period_custom' => 'Boshqa davr',

            'period_all' => 'Barcha vaqt',

            'period_from' => 'Dan',

            'period_to' => 'Gacha',

            'score_range' => 'Moslik bali',

            'score_from' => 'Dan',

            'score_to' => 'Gacha',

            'has_telegram' => 'Telegram mavjud',

            'has_driver' => 'Haydovchi mavjud',

            'any' => 'Muhim emas',

            'yes' => 'Ha',

            'no' => 'Yo‘q',
        ],

        'export' => [

            'title' => 'Excelga eksport',

            'operators' => 'Operatorlar bo‘yicha jamlanma',

            'details' => 'Tekshiruvlar bo‘yicha batafsil',

            'hint' => 'Eksport joriy filtr va davrni hisobga oladi.',
        ],

        'stats' => [

            'users' => 'Operatorlar',

            'drivers' => 'Haydovchilar',

            'checks' => 'Tekshiruvlar',

            'avg_match' => 'O‘rtacha moslik',
        ],

        'table' => [

            'user' => 'Operator',

            'telegram' => 'Telegram',

            'drivers' => 'Haydovchilar',

            'checks' => 'Tekshiruvlar',

            'result' => 'Natija',

            'match' => 'Moslik',

            'score' => 'Ball',

            'id' => 'ID',

            'best' => 'Eng yaxshi',

            'last_check' => 'Oxirgi tekshiruv',

            'no_username' => 'Username mavjud emas',

            'no_telegram_id' => 'Telegram ID mavjud emas',
        ],

        'result' => [

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'processing' => 'Jarayonda',
        ],

        'empty' => [

            'title' => 'Operatorlar topilmadi',

            'description' => 'Filtr yoki qidiruv so‘rovini o‘zgartirib ko‘ring.',
        ],

        'pagination' => [

            'showing' => 'Ko‘rsatilmoqda',

            'page' => 'Sahifa',

            'of' => 'dan',

            'previous' => 'Oldingi',

            'next' => 'Keyingi',
        ],

        'errors' => [

            'load_failed' => 'Operatorlarni yuklashda xatolik yuz berdi',

            'unknown' => 'Noma’lum xatolik yuz berdi',
        ],

        'dates' => [

            'never' => 'Hali yo‘q',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation User
    |--------------------------------------------------------------------------
    */

    'operation_user' => [

        'title' => 'Operator',

        'back' => 'Operatorlar',

        'refresh' => 'Yangilash',

        'loading' => 'Yuklanmoqda...',

        'id' => 'ID',

        'no_username' => 'Username mavjud emas',

        'unknown' => 'Noma’lum',

        /*
        |--------------------------------------------------------------------------
        | Operator stats
        |--------------------------------------------------------------------------
        */

        'stats' => [

            'drivers' => 'Haydovchilar',

            'checks' => 'Tekshiruvlar',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'processing' => 'Jarayonda',

            'match_rate' => 'Moslik darajasi',
        ],

        /*
        |--------------------------------------------------------------------------
        | Driver filters
        |--------------------------------------------------------------------------
        */

        'filters' => [

            'title' => 'Filtrlar',

            'all' => 'Barcha vaqt',

            'last_week' => '7 kun',

            'last_month' => '30 kun',

            'from' => 'Dan',

            'to' => 'Gacha',

            'clear' => 'Tozalash',

            'status' => 'Holat',

            'status_all' => 'Har qanday holat',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'processing' => 'Jarayonda',
        ],

        'export' => [

            'button' => 'Excelga eksport',

            'hint' => 'Eksport joriy filtr va davrni hisobga oladi.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Drivers
        |--------------------------------------------------------------------------
        */

        'drivers' => [
            'phone' => 'Telefon',

            'title' => 'Haydovchilar',

            'per_page' => 'Sahifada',

            'phones' => 'telefon',

            'checks' => 'tekshiruv',

            'show_phones' => 'Telefonlarni ko‘rsatish',

            'hide_phones' => 'Telefonlarni yashirish',

            'no_drivers' => 'Haydovchilar mavjud emas',

            'no_drivers_description' => 'Ushbu operatorga hali haydovchilar biriktirilmagan.',

            'no_resolved_phones' => 'Bog‘langan telefon raqamlari mavjud emas',

            'no_username' => 'Username mavjud emas',

            'unknown_date' => 'Sana noma’lum',

            /*
            |--------------------------------------------------------------------------
            | Driver statuses
            |--------------------------------------------------------------------------
            */

            'confirmed' => 'Tasdiqlangan',

            'confirmed_description' => 'Haydovchi ma’lumotlari tasdiqlangan.',

            'not_confirmed' => 'Tasdiqlanmagan',

            'not_confirmed_description' => 'Haydovchi ma’lumotlari tasdiqlanmagan.',

            'pending' => 'Kutilmoqda',

            'pending_description' => 'Haydovchi tasdiqlanishini kutmoqda.',

            'unknown_description' => 'Haydovchi holati noma’lum.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        'pagination' => [

            'page' => 'Sahifa',

            'of' => 'dan',

            'previous' => 'Oldingi',

            'next' => 'Keyingi',
        ],

        /*
        |--------------------------------------------------------------------------
        | Errors
        |--------------------------------------------------------------------------
        */

        'errors' => [

            'load_operator' => 'Operatorni yuklashda xatolik yuz berdi',

            'load_drivers' => 'Haydovchilarni yuklashda xatolik yuz berdi',

            'unknown' => 'Noma’lum xatolik yuz berdi',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers (list page)
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'title' => 'Haydovchilar',

        'description' => 'Barcha operatorlar bo‘yicha Telegram orqali tekshirilgan haydovchilar',

        'refresh' => 'Yangilash',

        'loading' => 'Yuklanmoqda...',

        'filters' => [

            'title' => 'Filtrlar',

            'description' => 'Haydovchilarni qidirish va saralash',

            'reset' => 'Tozalash',

            'search' => 'Qidiruv',

            'search_placeholder' => 'Haydovchi ismi...',

            'sort' => 'Saralash',

            'created' => 'Yaratilgan sana',

            'name' => 'Ism',

            'checks' => 'Tekshiruvlar',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'best_match_score' => 'Eng yaxshi ball',

            'last_check' => 'Oxirgi tekshiruv',

            'ascending' => 'O‘sish tartibida',

            'descending' => 'Kamayish tartibida',

            'apply' => 'Qidirish',

            'status' => 'Haydovchi holati',

            'status_all' => 'Har qanday holat',

            'confirmed_status' => 'Tasdiqlangan',

            'not_confirmed_status' => 'Tasdiqlanmagan',

            'pending_status' => 'Kutilmoqda',

            'check_status' => 'Tekshiruv holati',

            'period' => 'Davr',

            'period_today' => 'Bugun',

            'period_week' => '7 kun',

            'period_month' => '30 kun',

            'period_custom' => 'Boshqa davr',

            'period_all' => 'Barcha vaqt',

            'period_from' => 'Dan',

            'period_to' => 'Gacha',

            'score_range' => 'Moslik bali',

            'score_from' => 'Dan',

            'score_to' => 'Gacha',
        ],

        'stats' => [

            'total' => 'Haydovchilar',

            'checks' => 'Tekshiruvlar',

            'confirmed' => 'Tasdiqlangan',

            'avg_match' => 'O‘rtacha moslik',
        ],

        'table' => [

            'driver' => 'Haydovchi',

            'operator' => 'Operator',

            'status' => 'Holat',

            'checks' => 'Tekshiruvlar',

            'result' => 'Natija',

            'score' => 'Ball',

            'last_check' => 'Oxirgi tekshiruv',

            'phones' => 'Telefonlar',

            'id' => 'ID',

            'no_operator' => 'Operator yo‘q',
        ],

        'status' => [

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'pending' => 'Kutilmoqda',

            'unknown' => 'Noma’lum',
        ],

        'export' => [

            'button' => 'Excelga eksport',

            'hint' => 'Eksport joriy filtr va davrni hisobga oladi.',
        ],

        'empty' => [

            'title' => 'Haydovchilar topilmadi',

            'description' => 'Filtr yoki qidiruv so‘rovini o‘zgartirib ko‘ring.',
        ],

        'pagination' => [

            'showing' => 'Ko‘rsatilmoqda',

            'page' => 'Sahifa',

            'of' => 'dan',

            'previous' => 'Oldingi',

            'next' => 'Keyingi',
        ],

        'errors' => [

            'load_failed' => 'Haydovchilarni yuklashda xatolik yuz berdi',

            'unknown' => 'Noma’lum xatolik yuz berdi',
        ],

        'dates' => [

            'never' => 'Hali yo‘q',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolved phones (list page)
    |--------------------------------------------------------------------------
    */

    'resolved_phones' => [

        'title' => 'Telegram raqamlari',

        'description' => 'Telegram orqali aniqlangan barcha telefon raqamlari va tekshiruv natijalari',

        'refresh' => 'Yangilash',

        'loading' => 'Yuklanmoqda...',

        'filters' => [

            'title' => 'Filtrlar',

            'description' => 'Aniqlangan raqamlarni qidirish va saralash',

            'reset' => 'Tozalash',

            'search' => 'Qidiruv',

            'search_placeholder' => 'Telefon, username, ism...',

            'sort' => 'Saralash',

            'created' => 'Yaratilgan sana',

            'resolved' => 'Aniqlangan sana',

            'phone' => 'Telefon',

            'checks' => 'Tekshiruvlar',

            'confirmed' => 'Tasdiqlangan',

            'not_confirmed' => 'Tasdiqlanmagan',

            'ascending' => 'O‘sish tartibida',

            'descending' => 'Kamayish tartibida',

            'apply' => 'Qidirish',

            'has_username' => 'Username mavjud',

            'has_driver' => 'Haydovchiga bog‘langan',

            'any' => 'Muhim emas',

            'yes' => 'Ha',

            'no' => 'Yo‘q',

            'stale' => 'Eskirgan (> 7 kun)',

            'period' => 'Davr',

            'period_today' => 'Bugun',

            'period_week' => '7 kun',

            'period_month' => '30 kun',

            'period_custom' => 'Boshqa davr',

            'period_all' => 'Barcha vaqt',

            'period_from' => 'Dan',

            'period_to' => 'Gacha',
        ],

        'stats' => [

            'total' => 'Raqamlar',

            'with_username' => 'Username bilan',

            'with_driver' => 'Haydovchiga bog‘langan',

            'checks' => 'Tekshiruvlar',
        ],

        'table' => [

            'phone' => 'Telefon',

            'telegram' => 'Telegram',

            'driver' => 'Haydovchi',

            'operator' => 'Operator',

            'account' => 'Aniqlovchi akkaunt',

            'resolved_at' => 'Aniqlangan',

            'checks' => 'Tekshiruvlar',

            'result' => 'Natija',

            'id' => 'ID',

            'no_username' => 'Username mavjud emas',

            'no_driver' => 'Haydovchi yo‘q',
        ],

        'export' => [

            'button' => 'Excelga eksport',

            'hint' => 'Eksport joriy filtr va davrni hisobga oladi.',
        ],

        'empty' => [

            'title' => 'Raqamlar topilmadi',

            'description' => 'Filtr yoki qidiruv so‘rovini o‘zgartirib ko‘ring.',
        ],

        'pagination' => [

            'showing' => 'Ko‘rsatilmoqda',

            'page' => 'Sahifa',

            'of' => 'dan',

            'previous' => 'Oldingi',

            'next' => 'Keyingi',
        ],

        'errors' => [

            'load_failed' => 'Raqamlarni yuklashda xatolik yuz berdi',

            'unknown' => 'Noma’lum xatolik yuz berdi',
        ],

        'dates' => [

            'never' => 'Hali yo‘q',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | How it works (driver-check/system)
    |--------------------------------------------------------------------------
    */
    'system' => [
        'title' => 'Qanday ishlaydi',
        'description' => 'Telegram qismining barcha mustaqil mantiqlari, hozir har birini kim bajarishi va aniqlangan muammolar.',
        'count' => 'Mustaqil mantiqlar: :total, shundan boshqa akkauntga yo\'naltirish mumkin: :movable.',
        'count_hint' => 'Har biri o\'zicha ishlaydi: bittasining nosozligi yoki ko\'chirilishi boshqalarni to\'xtatmaydi.',
        'movable' => 'Yo\'naltirish mumkin',
        'fixed' => 'Qat\'iy',
        'done_by' => 'Bajaradi:',
        'own_accounts' => 'O\'z akkauntlari',
        'change' => 'O\'zgartirish',
        'open' => 'Ochish',
        'errors' => [
            'title' => 'Sahifani yuklab bo\'lmadi',
            'load' => 'Ma\'lumotlarni yuklab bo\'lmadi',
        ],
        'state' => [
            'running' => 'Ishlayapti',
            'stopped' => 'Ishga tushmagan',
            'off' => 'O\'chiq',
        ],
        'logics' => [
            'driver_checks' => [
                'name' => 'Haydovchilarni tekshirish',
                'what' => 'Guruhlarni o\'qiydi, haydovchi va transport xabarlarini yozadi, natijalarni solishtiradi va tekshiruv akkauntlari yetmasa ogohlantiradi.',
                'note' => 'Driver Checkda qoladi, ko\'chirilmaydi.',
            ],
            'penalty_intake' => [
                'name' => 'Jarimalarni qabul qilish',
                'what' => 'Bot CRM jarimalarini yozadigan guruhlarni o\'qiydi, jarimani yozadi, javobgar va uning rolini topadi.',
                'note' => 'Akkaunt shu guruhlarda a\'zo bo\'lishi kerak.',
            ],
            'penalties_sales' => [
                'name' => 'Sotuvchilar jarimalari (Sales)',
                'what' => 'Jarimani sotuvchiga yuboradi, seriyadan keyin izoh yozadi, jim turganlarga eslatadi va shaxsiy javoblarga javob beradi.',
                'note' => '',
            ],
            'penalties_operation' => [
                'name' => 'Operatorlar jarimalari (Operation)',
                'what' => 'Operatorlar uchun ham shunday: yuborish, seriyadan keyingi izoh, eslatma va shaxsiy xabarlarga javob.',
                'note' => '',
            ],
            'file_routes' => [
                'name' => 'Botdan fayllar',
                'what' => 'Bildirishnoma botining ertalabki fayllari turlar bo\'yicha guruhlarga ketadi. Har bir turning o\'z yuboruvchi akkaunti va guruhlari bor.',
                'note' => 'Akkaunt yonidagi raqam — u nechta tur faylni yuborishi.',
            ],
            'scheduled_messages' => [
                'name' => 'Jadval bo\'yicha xabarlar',
                'what' => 'Belgilangan vaqtda guruhlarga yozadi; yuboruvchi har bir xabar uchun tanlanadi.',
                'note' => 'Akkaunt yonidagi raqam — u nechta faol xabar yozishi.',
            ],
            'contact_sync' => [
                'name' => 'Kontaktlarni saqlash',
                'what' => 'Kuniga bir marta guruh a\'zolarini akkauntlar kontaktlariga saqlaydi. O\'z akkauntlarida alohida vazifa.',
                'note' => '',
            ],
            'phone_resolver' => [
                'name' => 'Raqam bo\'yicha qidirish',
                'what' => 'Haydovchining Telegramini raqam bo\'yicha akkauntlar puli orqali topadi. Listener akkauntlari ishlatilmaydi.',
                'note' => '',
            ],
            'group_join' => [
                'name' => 'Guruhlarga qo\'shilish',
                'what' => 'Belgilangan akkauntlar, har biri alohida watchdog ostidagi o\'z jarayonida, kalit so\'zlar bo\'yicha guruhlarni o\'zi topadi, tekshiradi, to\'xtamasdan qo\'shiladi va a\'zolarini yig\'adi. Flooddan keyin o\'zi kutib davom etadi.',
                'note' => '',
            ],
        ],
        'flows' => [
            'penalty' => [
                'title' => 'Jarima yo\'li',
                'steps' => [
                    'Bot jarimani guruhga yozadi. Uni «Jarimalarni qabul qilish» akkaunti (odatda Driver Check) o\'qib yozib oladi.',
                    'Javobgarning ismi bo\'yicha odam va uning roli — Sales yoki Operation — aniqlanadi.',
                    'Jarimani shu rolga tayinlangan akkaunt yuboradi. U boshqa akkaunt bo\'lsa, jarima bir necha soniyada unga o\'tadi.',
                    'Shu akkaunt seriyadan keyin izoh yozadi, jim turganlarga eslatadi va shaxsiy javoblarga javob beradi.',
                    'Username ham Telegram ID ham yo\'q yoki Telegram rad etdi: ogohlantirish keladi. Odamda ID ni saqlang (avval shu akkauntdan unga yozing).',
                ],
            ],
            'file' => [
                'title' => 'Fayl yo\'li',
                'steps' => [
                    'Bildirishnoma boti faylni qabul qiluvchi akkaunt ko\'radigan chatga yuboradi.',
                    'Fayl turi izoh yoki fayl nomidagi kalit so\'z bo\'yicha aniqlanadi.',
                    'Faylni tur uchun tanlangan akkaunt (yoki «Botdan fayllar» umumiy egasi) shu turning guruhlariga yuboradi.',
                    'Bo\'lmasa: har daqiqada 3 martagacha qayta uriniladi, keyin ogohlantirish.',
                ],
            ],
        ],
        'processes' => [
            'title' => 'Jarayonlar',
            'description' => 'Har bir akkaunt o\'z jarayonida ishlaydi; nuqta uning tirik ekanini ko\'rsatadi.',
            'none' => 'Hozircha listenerlar yo\'q.',
            'run_all' => 'Barcha listenerlarni Driver Check bilan birga ishga tushiradigan va qayta yoqadigan bitta jarayon:',
            'run_note' => 'Alohida buyruqlar (telegram:watchdog va telegram:listener-watchdog <key>) avvalgidek ishlaydi; dublikatlar ishga tushmaydi.',
        ],
        'alerts' => [
            'title' => 'Bilish kerak bo\'lgan muammolar',
            'description' => 'Jarima yetkazilmadi, fayl ketmadi, listener tushib qolyapti: har bir muammo shu yerga yoziladi. Yarim soat ichidagi bir xili bitta hisoblanadi.',
            'not_configured' => 'Ogohlantirish boti sozlanmagan (.env da TELEGRAM_ALERT_BOT_TOKEN va TELEGRAM_ALERT_CHAT_ID): muammolar faqat yoziladi.',
            'sent' => 'yuborilgan',
            'not_sent' => 'yuborilmagan',
            'empty' => 'Muammolar bo\'lmagan',
        ],
    ],

];
