<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The group invite stops trusting a call that came back without an error.
 * Telegram leaves people out of a batch without a word - someone who left
 * the group before, everybody while it holds the account quietly - so a
 * person counts as added only once Telegram names them in its "added"
 * message or the check finds them in the group.
 *
 * telegram_group_invites.status gains sent (the call went through, not
 *   checked yet), not_added (could be added, is not in the group) and
 *   already (was in the group before). No earlier "invited" was ever
 *   checked: they become sent, for the next run to check one by one.
 *   USER_NOT_MUTUAL_CONTACT (left the group, only a mutual contact may bring
 *   them back) is not_added for that group, no longer refused everywhere.
 * telegram_group_invites.checked_at - when Telegram's answer or the check
 *   settled where the person stands.
 * telegram_invite_groups.members_before / members_after - the group's member
 *   count when the last run came to it and when it was done with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('attempted_at');
        });

        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->unsignedInteger('members_before')->nullable()->after('telegram_chat_id');
            $table->unsignedInteger('members_after')->nullable()->after('members_before');
            $table->timestamp('members_checked_at')->nullable()->after('members_after');
        });

        DB::table('telegram_group_invites')->where('status', 'invited')->update(['status' => 'sent']);

        DB::table('telegram_group_invites')
            ->where('status', 'failed')
            ->where('error', 'USER_NOT_MUTUAL_CONTACT')
            ->update(['status' => 'not_added']);

        DB::table('telegram_scraped_users')
            ->where('can_add_to_group', false)
            ->where('add_to_group_error', 'USER_NOT_MUTUAL_CONTACT')
            ->update(['can_add_to_group' => null, 'add_to_group_error' => null]);
    }

    public function down(): void
    {
        DB::table('telegram_group_invites')->whereIn('status', ['sent', 'already'])->update(['status' => 'invited']);
        DB::table('telegram_group_invites')->where('status', 'not_added')->update(['status' => 'failed']);

        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->dropColumn(['members_before', 'members_after', 'members_checked_at']);
        });

        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->dropColumn('checked_at');
        });
    }
};
