<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What happened to a person in a group is kept for good.
 *
 * telegram_invite_groups.deleted_at - "Delete" on the page archives the
 *   group. Its rows in telegram_group_invites stay: who was added, who was
 *   not and why. Adding the same link again brings the group back with all
 *   of it, so nobody is tried a second time for it.
 * telegram_group_invites (scraped_user_id, status) - a person's history in
 *   every group, and the base page's "tried" counts, without a scan.
 * telegram_group_invites.left_at - an added person is not watched after
 *   the add. "Who left" on the page looks everybody added up once more:
 *   whoever is not in the group any more becomes status left, with when
 *   the check found it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('checked_at');

            $table->index(['scraped_user_id', 'status'], 'tg_group_invite_person');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_group_invites', function (Blueprint $table) {
            $table->dropIndex('tg_group_invite_person');
            $table->dropColumn('left_at');
        });

        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
