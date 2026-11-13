<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Other wordings of the same scheduled message ("Отчет эсдан чикмасин",
 * "Ҳисоботларни унутманг" ...). Each group gets one of the texts picked at
 * random, so the groups do not all read the same line.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_scheduled_messages') || Schema::hasColumn('telegram_scheduled_messages', 'variants')) {
            return;
        }

        Schema::table('telegram_scheduled_messages', function (Blueprint $table) {
            /* A list of texts besides the main one; empty is the main text only. */
            $table->json('variants')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('telegram_scheduled_messages', 'variants')) {
            return;
        }

        Schema::table('telegram_scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('variants');
        });
    }
};
