<?php

use App\Models\Telegram\TelegramGroupInvite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * "Proven" (can_add_to_group = true) is somebody our call actually added
 * into one of our groups (the owner, 2026-11-04). Before the check came
 * (2026_10_29_160000) a call without an error marked everybody in it so:
 * those calls became "sent", the check found many of them not added or in
 * the group already, and the mark stayed. Whoever has no added row
 * (invited or left, archived groups included) is neither proven nor
 * refused again: tried, to be tried like anybody else.
 *
 * No way back: which of them the old mark was given to is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_scraped_users')
            ->where('can_add_to_group', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('telegram_group_invites')
                ->whereColumn('telegram_group_invites.scraped_user_id', 'telegram_scraped_users.id')
                ->whereIn('telegram_group_invites.status', TelegramGroupInvite::ADDED))
            ->update(['can_add_to_group' => null, 'add_to_group_error' => null]);
    }

    public function down(): void
    {
        //
    }
};
