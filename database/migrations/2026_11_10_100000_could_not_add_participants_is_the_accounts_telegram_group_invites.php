<?php

use App\Application\Telegram\Services\GroupInviteBase;
use App\Models\Telegram\TelegramGroupInvite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * CHAT_MEMBER_ADD_FAILED ("could not add participants") was taken for the
 * person's refusal: whoever was in such a call was written down as "cannot
 * be added" for the group. On 10.11 fourteen accounts got it on every call
 * from their first one while the other accounts kept adding into the same
 * group - it is Telegram holding the account (GroupInviteService::KIND_LIMITED
 * now). Those people were never tried: their rows go, and the group's queue
 * has them again. The log lines of the calls stay.
 *
 * No way back: the rows are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_group_invites')
            ->where('status', TelegramGroupInvite::STATUS_FAILED)
            ->where('error', 'CHAT_MEMBER_ADD_FAILED')
            ->delete();

        GroupInviteBase::forget();
    }

    public function down(): void
    {
        //
    }
};
