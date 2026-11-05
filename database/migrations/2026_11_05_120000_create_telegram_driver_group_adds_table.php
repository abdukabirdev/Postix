<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Drivers into one of our groups (Group invites -> Drivers into a group).
 *
 * One row per driver found in Telegram (telegram_resolved_phones) and our
 * group: waiting (pending), or where the try ended - added (invited), in
 * the group already, the call went and they are not in (not_added), refused
 * (failed, with Telegram's code), sent but not looked up (sent).
 *
 * The account that found the driver by the number adds them: the access
 * hash belongs to its session. resolver_account_id is that account, kept
 * when the row is made; telegram_account_id is the one that tried - another
 * one when the first cannot work any more, after it found the driver by the
 * number itself (re_resolved).
 *
 * source: resolve - the driver was checked while "add on resolve" was on;
 * all - the "add every driver" button.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The first run on MySQL broke on an index name over 64 characters
         * after the table itself was made: MySQL does not take a CREATE
         * back, so the table stayed, empty and without its indexes. Made
         * again here; one that already holds rows is not touched.
         */
        if (Schema::hasTable('telegram_driver_group_adds')) {
            if (DB::table('telegram_driver_group_adds')->exists()) {
                throw new RuntimeException('telegram_driver_group_adds already holds rows: check it by hand before migrating.');
            }

            Schema::drop('telegram_driver_group_adds');
        }

        /* Index names given by hand: the generated ones run over MySQL's 64 characters. */
        Schema::create('telegram_driver_group_adds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invite_group_id');
            $table->foreignId('resolved_phone_id')
                ->constrained('telegram_resolved_phones')
                ->cascadeOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('phone', 32);
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->unsignedBigInteger('resolver_account_id')->nullable();
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->boolean('re_resolved')->default(false);
            $table->string('source', 16);
            $table->string('status', 16);
            $table->string('error', 120)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['invite_group_id', 'resolved_phone_id'], 'tdga_group_phone_unique');
            $table->index(['invite_group_id', 'status'], 'tdga_group_status_index');
            $table->index(['telegram_account_id', 'attempted_at'], 'tdga_account_attempted_index');
            $table->index('resolver_account_id', 'tdga_resolver_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_driver_group_adds');
    }
};
