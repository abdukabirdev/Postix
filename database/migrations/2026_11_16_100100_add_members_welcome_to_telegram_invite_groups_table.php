<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Our groups whose members count as people who do not mind being invited
 * (the owner, 2026-11-16: Cargo Connect and Логистика и Грузы - "это те
 * люди которые более или менее не против"). Whoever is on such a group's
 * members list is green for the invites into our other groups. A switch
 * on the groups page; those two are switched on here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->boolean('members_welcome')->default(false)->after('is_active');
        });

        DB::table('telegram_invite_groups')
            ->where(fn ($q) => $q
                ->where('title', 'like', '%Cargo Connect%')
                ->orWhere('title', 'like', '%Логистика и Грузы%')
                ->orWhere('link', 'cargo_connect_uz'))
            ->update(['members_welcome' => true]);
    }

    public function down(): void
    {
        Schema::table('telegram_invite_groups', function (Blueprint $table) {
            $table->dropColumn('members_welcome');
        });
    }
};
