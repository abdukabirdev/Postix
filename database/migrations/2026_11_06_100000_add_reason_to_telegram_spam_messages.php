<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Our join request bot deletes every message with a link in our groups as
 * well, whoever wrote it (but the group's admins and ours), and sends the
 * owner a copy of it first.
 *
 * reason - why the message goes: list (its author is on the spam list) |
 *   link (it carries a link). A message of someone on the list with a link
 *   is a link one: the owner gets its copy too.
 * copy_message_id - the copy in the owner's chat, made right before the
 *   message is deleted; 0 - Telegram did not copy it (the report quotes the
 *   text then); null - not tried yet. Kept so that a sweep tried again does
 *   not send the copy twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_spam_messages', function (Blueprint $table) {
            $table->string('reason', 8)->default('list')->after('spam_sender_id');
            $table->bigInteger('copy_message_id')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_spam_messages', function (Blueprint $table) {
            $table->dropColumn(['reason', 'copy_message_id']);
        });
    }
};
