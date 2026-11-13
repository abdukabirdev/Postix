<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two ways for a scheduled message not to read like a clock:
 *
 *  - jitter_minutes: it goes out at its time plus or minus up to this many
 *    minutes, a different shift each day ("Ҳар куни 16:59 да кўзим қонаяпти",
 *    a group member wrote on 2026-11-09);
 *  - skip_if_file_today: it is not written into a group where somebody has
 *    already posted a file today - the report the evening reminder asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_scheduled_messages')) {
            return;
        }

        Schema::table('telegram_scheduled_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('telegram_scheduled_messages', 'jitter_minutes')) {
                $table->unsignedTinyInteger('jitter_minutes')->default(0)->after('send_at');
            }

            if (! Schema::hasColumn('telegram_scheduled_messages', 'skip_if_file_today')) {
                $table->boolean('skip_if_file_today')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_scheduled_messages')) {
            return;
        }

        Schema::table('telegram_scheduled_messages', function (Blueprint $table) {
            foreach (['jitter_minutes', 'skip_if_file_today'] as $column) {
                if (Schema::hasColumn('telegram_scheduled_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
