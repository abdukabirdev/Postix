<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * People are added into one group at a time. Where more than one group is
 * switched on, the newest stays on and the others are switched off - not
 * archived: their history stays and any of them can be made current again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $newest = DB::table('telegram_invite_groups')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->max('id');

        if ($newest !== null) {
            DB::table('telegram_invite_groups')
                ->where('is_active', true)
                ->where('id', '!=', $newest)
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        // Which groups were on before is not kept.
    }
};
