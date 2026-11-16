<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether a person is worth inviting into our groups at all (the owner,
 * 2026-11-16: "мы берём всех ... они всё равно выходят"). Only people who
 * live in Telegram are invited: those who wrote in a group lately, those
 * who were online lately, and those who already sit in our own groups.
 *
 * invite_ok           - null: not checked yet; true: green, invited;
 *                       false: red, left out (PeopleActivity::rule()).
 * last_seen_at        - when the person was online, as near as Telegram
 *                       tells it (a hidden "last seen" gives a range).
 * seen_status         - what Telegram said last: online, offline, recently,
 *                       last_week, last_month, long_ago.
 * last_posted_at      - the latest message of theirs seen in a group.
 * our_group_at        - the latest time they were seen on the members list
 *                       of one of our groups marked "members welcome".
 * activity_checked_at - when any of the above was last looked at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_scraped_users', function (Blueprint $table) {
            $table->boolean('invite_ok')->nullable()->after('add_to_group_checked_at');
            $table->timestamp('last_seen_at')->nullable()->after('invite_ok');
            $table->string('seen_status', 16)->nullable()->after('last_seen_at');
            $table->timestamp('last_posted_at')->nullable()->after('seen_status');
            $table->timestamp('our_group_at')->nullable()->after('last_posted_at');
            $table->timestamp('activity_checked_at')->nullable()->after('our_group_at');

            $table->index('invite_ok');
            $table->index('activity_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_scraped_users', function (Blueprint $table) {
            $table->dropIndex(['invite_ok']);
            $table->dropIndex(['activity_checked_at']);
            $table->dropColumn(['invite_ok', 'last_seen_at', 'seen_status', 'last_posted_at', 'our_group_at', 'activity_checked_at']);
        });
    }
};
