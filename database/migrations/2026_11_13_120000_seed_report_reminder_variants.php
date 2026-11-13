<?php

use App\Models\Telegram\TelegramScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * The three daily report reminders ("Отчетлар эсдан чикмасин, соат 5 да
 * сорайман" 09:59, "Эслатиб койабман" 14:24, "Нима болди отчетлар?" 16:59)
 * went to three groups with the same words every day, and a member of one
 * group noticed. They get other wordings - the owner's own phrases from the
 * chats, written with ў қ ҳ - and a few minutes of jitter. The file skip is
 * left off: a work group gets other files all day (CMRs), which would hold
 * the evening reminder back; it is switched on per message in the panel.
 *
 * Found by their text; a message that already has variants is left as it is,
 * so what was written in the panel is never overwritten. Run once.
 */
return new class extends Migration
{
    private const SLOTS = [
        'morning' => [
            'variants' => [
                'Отчётлар эсдан чиқмасин, соат 5 да сўрайман',
                'Кечга отчёт сўрайман',
                'Соат 5 да отчётларни сўрайман, унутманглар',
                'Бугунги отчётлар соат 5 гача керак',
                'Отчётларни унутманглар, соат 5 да сўрайман',
                'Соат 5 да отчётларни сўрайман',
            ],
            'jitter' => 5,
            'skip_file' => false,
        ],
        'noon' => [
            'variants' => [
                'Эслатиб қўяман, отчётлар эсдан чиқмасин',
                'Отчётлар қалай, бошладингларми?',
                'Отчётларни тайёрлаяпсизларми? Соат 5 да сўрайман',
                'Отчётларни унутманглар, соат 5 гача улгуринглар',
                'Соат 5 гача отчётлар керак, эслатиб қўяман',
                'Отчётлар тайёр бўляптими?',
                'Отчётлар эсдан чиқмасин, эслатиб қўяй',
            ],
            'jitter' => 5,
            'skip_file' => false,
        ],
        'evening' => [
            'variants' => [
                'Нима бўлди отчётлар?',
                'Отчёт қани?',
                'Отчётлар қани?',
                'Отчётлар тайёрми?',
                'Отчётларни юбордингизларми?',
                'Отчётлар қалай бўлди?',
                'Отчётлар нима бўлди, юбордингларми?',
                'Отчётларни кутяпман',
            ],
            'jitter' => 5,
            'skip_file' => false,
        ],
    ];

    public function up(): void
    {
        if (
            ! Schema::hasTable('telegram_scheduled_messages')
            || ! Schema::hasColumn('telegram_scheduled_messages', 'variants')
            || ! Schema::hasColumn('telegram_scheduled_messages', 'jitter_minutes')
        ) {
            return;
        }

        TelegramScheduledMessage::query()->get()->each(function (TelegramScheduledMessage $message): void {
            $slot = self::slot((string) $message->text);

            if ($slot === null || $message->variants !== null && $message->variants !== []) {
                return;
            }

            $message->forceFill([
                'variants' => self::SLOTS[$slot]['variants'],
                'jitter_minutes' => self::SLOTS[$slot]['jitter'],
                'skip_if_file_today' => self::SLOTS[$slot]['skip_file'],
            ])->save();
        });
    }

    public function down(): void
    {
        //
    }

    /**
     * Which of the three it is, by its words: typed with or without the
     * Uzbek marks ("очетлар" too, as the first run of it was misspelt).
     */
    private static function slot(string $text): ?string
    {
        $text = mb_strtolower(strtr(mb_strtolower($text), ['ў' => 'у', 'қ' => 'к', 'ғ' => 'г', 'ҳ' => 'х', 'ё' => 'е']));

        return match (true) {
            str_contains($text, 'эсдан чикмасин') && str_contains($text, 'сорайман') => 'morning',
            str_starts_with($text, 'эслатиб') => 'noon',
            (str_contains($text, 'нима болди') || str_contains($text, 'нима булди')) && (str_contains($text, 'отчет') || str_contains($text, 'очет')) => 'evening',
            default => null,
        };
    }
};
