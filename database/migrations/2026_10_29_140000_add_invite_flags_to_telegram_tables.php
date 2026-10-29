<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The group invite learns who can be added at all, and reaches people with
 * a public @username once an account's own contacts run out.
 *
 * telegram_scraped_users.can_add_to_group - null: not tried yet; true: added
 *   to a group at least once; false: Telegram refused because of the person
 *   (privacy, a deleted account, too many groups), so no group tries again.
 * telegram_group_invites.via - contact (the account's own contact) or
 *   username (resolved by @username from the common base).
 * telegram_group_invite_logs - the day's invite calls are counted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_scraped_users', function (Blueprint $table) {
            $table->boolean('can_add_to_group')->nullable()->after('is_bot');
            $table->string('add_to_group_error', 64)->nullable()->after('can_add_to_group');
            $table->timestamp('add_to_group_checked_at')->nullable()->after('add_to_group_error');

            $table->index('can_add_to_group');
        });

        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->string('via', 16)->default('contact')->after('status');
        });

        Schema::table('telegram_group_invite_logs', function (Blueprint $table) {
            $table->index(['telegram_account_id', 'event', 'created_at'], 'tg_invite_log_account_event');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_group_invite_logs', function (Blueprint $table) {
            $table->dropIndex('tg_invite_log_account_event');
        });

        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->dropColumn('via');
        });

        Schema::table('telegram_scraped_users', function (Blueprint $table) {
            $table->dropIndex(['can_add_to_group']);
            $table->dropColumn(['can_add_to_group', 'add_to_group_error', 'add_to_group_checked_at']);
        });
    }
};
