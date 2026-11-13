<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The call by name (PingSilentByName): a penalty nobody answered gets the
 * person's name alone - "Зуба", "Рушана апа" - the way the owner wakes
 * people up by hand. When it went and what it said, on the penalty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_client_checks') || Schema::hasColumn('telegram_client_checks', 'pinged_at')) {
            return;
        }

        Schema::table('telegram_client_checks', function (Blueprint $table) {
            $table->timestamp('pinged_at')->nullable()->after('nudge_text');
            $table->string('ping_text', 255)->nullable()->after('pinged_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('telegram_client_checks', 'pinged_at')) {
            return;
        }

        Schema::table('telegram_client_checks', function (Blueprint $table) {
            $table->dropColumn(['pinged_at', 'ping_text']);
        });
    }
};
