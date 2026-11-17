<?php

/*
|--------------------------------------------------------------------------
| The sender bot: what it says in Telegram (Uzbek, Latin)
|--------------------------------------------------------------------------
*/

return [
    'choose_language' => "Здравствуйте! Выберите язык.\nAssalomu alaykum! Tilni tanlang.",
    'lang_ru' => '🇷🇺 Русский',
    'lang_uz' => "🇺🇿 O'zbekcha",
    'language_set' => "Til: o'zbekcha.",

    'requested' => "✅ Foydalanish uchun so'rov administratorga yuborildi.\nU javob berishi bilan sizga yozaman.",
    'still_pending' => "⏳ So'rovingiz hali administratorda. U javob berishi bilan sizga yozaman.",
    'rejected' => "❌ Administrator so'rovni rad etdi.",
    'blocked' => '⛔️ Administrator botdan foydalanishni yopib qo‘ydi.',
    'approved' => "🎉 Administrator sizga ruxsat berdi!\n\n«✉️ Xabar yuborish» tugmasini bosing, matnni yuboring — men uni guruhlarga jo‘nataman.",

    'menu' => 'Nima qilamiz?',
    'btn_send' => '✉️ Xabar yuborish',
    'btn_posts' => '📋 Mening xabarlarim',
    'btn_groups' => '👥 Guruhlar',
    'btn_language' => '🌐 Til',
    'btn_cancel' => '✖️ Bekor qilish',
    'btn_start' => '✅ Boshlash',

    'ask_text' => "Guruhlarga yuboriladigan xabarni jo‘nating.\nMatn, rasm yoki izohli video bo‘lishi mumkin.",
    'bad_message' => "Bunday xabarni yubora olmayman. Matn, rasm, video yoki fayl jo‘nating.",
    'ask_times' => 'Xabar necha marta yuborilsin? 1 dan :max gacha son yozing.',
    'bad_times' => '1 dan :max gacha son kerak.',
    'ask_interval' => 'Necha daqiqada bir takrorlansin? :min dan :max gacha son yozing.',
    'bad_interval' => ':min dan :max gacha daqiqa kerak.',
    'confirm' => "Tekshiring:\n\n👥 Guruhlar: :groups\n🔁 Necha marta: :times\n⏱ Oraliq: :interval\n\nXabar — yuqorida. Boshlaymizmi?",
    'confirm_buttons' => "Yuqoridagi xabar ostidagi «✅ Boshlash» yoki «✖️ Bekor qilish» tugmasini bosing.",
    'started' => "🚀 Boshlandi! Birinchi yuborish — hozir.\nTo‘xtatish uchun «📋 Mening xabarlarim».",
    'cancelled' => 'Bekor qilindi.',
    'no_groups' => "Administrator hali yuborish uchun guruh qo‘shmagan. Keyinroq urinib ko‘ring.",

    'groups_list' => "Xabarlar shu guruhlarga yuboriladi:\n\n:list",
    'posts_none' => "Hozir faol xabarlaringiz yo‘q.",
    'posts_title' => 'Faol xabarlaringiz:',
    'post_line' => "#:id · :text\n🔁 :times dan :sent · keyingisi: :next",
    'btn_stop' => '⏹ To‘xtatish #:id',
    'stopped' => '⏹ #:id xabar to‘xtatildi.',
    'stopped_by_admin' => '⏹ Administrator #:id xabarni to‘xtatdi.',
    'done' => '✅ #:id xabar tugadi: :times marta, :groups ta guruhga.',
    'failed' => "⚠️ #:id xabar hech bir guruhga yetib bormadi.\n:error",
    'failed_no_groups' => "⚠️ #:id xabar to‘xtatildi: administrator barcha guruhlarni o‘chirdi.",
    'next_now' => 'hozir',
    'media' => '[media]',

    'minutes' => ':m daqiqa',
    'hours' => ':h soat',
    'hours_minutes' => ':h soat :m daqiqa',
    'interval_none' => '—',

    /* To the admin (the admin reads Russian; these stay for completeness). */
    'admin_request' => "🔔 Xabar botiga yangi so'rov\n\n👤 :name\n:username🆔 :id\n🌐 :language",
    'admin_request_link' => "\n\nRuxsat berish yoki rad etish: :link",
    'admin_test' => "🔔 Tekshiruv: yangi so'rovlar haqida xabarlar shunday keladi.",
    'btn_approve' => '✅ Ruxsat berish',
    'btn_reject' => '❌ Rad etish',
    'admin_approved' => '✅ Ruxsat berildi',
    'admin_rejected' => '❌ Rad etildi',
    'admin_already' => 'Allaqachon hal qilingan',
    'admin_only' => 'Buni faqat administrator qila oladi.',
];
