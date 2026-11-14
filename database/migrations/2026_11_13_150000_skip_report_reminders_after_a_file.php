<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The owner, 2026-11-13: once a report file came into a group after the
 * morning reminder, the 14:24 and 16:59 ones are not needed there. The
 * skip goes on for those two (found by their text, as in the seed of
 * 2026_11_13_120000); the morning one stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('telegram_scheduled_messages')
            || ! Schema::hasColumn('telegram_scheduled_messages', 'skip_if_file_today')
        ) {
            return;
        }

        foreach (DB::table('telegram_scheduled_messages')->get(['id', 'text']) as $message) {
            if (self::later((string) $message->text)) {
                DB::table('telegram_scheduled_messages')->where('id', $message->id)->update(['skip_if_file_today' => true]);
            }
        }
    }

    public function down(): void
    {
        //
    }

    /**
     * The noon ("Эслатиб койабман") or the evening ("Нима болди отчетлар?")
     * reminder, typed with or without the Uzbek marks.
     */
    private static function later(string $text): bool
    {
        $text = mb_strtolower(strtr(mb_strtolower(trim($text)), ['ў' => 'у', 'қ' => 'к', 'ғ' => 'г', 'ҳ' => 'х', 'ё' => 'е']));

        return str_starts_with($text, 'эслатиб')
            || ((str_contains($text, 'нима болди') || str_contains($text, 'нима булди')) && (str_contains($text, 'отчет') || str_contains($text, 'очет')));
    }
};
