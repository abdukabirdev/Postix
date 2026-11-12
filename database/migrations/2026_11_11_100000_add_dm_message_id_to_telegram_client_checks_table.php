<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The id of the penalty message in the person's private chat - the one our
 * account wrote there. A cancelled request is asked about in a reply to it
 * (RequestCancellationAsker). Rows written before it are found in the
 * chat's history instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_client_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('dm_message_id')->nullable()->after('peer');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_client_checks', function (Blueprint $table) {
            $table->dropColumn('dm_message_id');
        });
    }
};
