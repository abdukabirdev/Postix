<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The sender bot, second step: what people and the admin asked for once it
 * was in use.
 *
 * groups.invite_link - the link a person opens the group by when it has no
 *   @name (the bot takes it itself when it is admin, or the admin types it).
 *
 * posts.group_ids - the groups the person chose (null: every group that is on).
 * posts.payload - the message itself (text + entities, or file_id + caption),
 *   sent by hand when the copy of the person's own message is refused (it
 *   was deleted, or Telegram says it "can't be copied"), and for a repeat.
 * posts.paused_at - the person or the admin paused it (status paused).
 * posts.repeat_of_id - made by "Repeat" from an earlier post.
 * posts.delete_asked_at / _by / copies_deleted_at - "delete from the groups":
 *   asked (by user | admin), then done by the posting command.
 *
 * sends.deleted_at - this copy was deleted from its group.
 *
 * telegram_sender_bot_leads - a driver (somebody in the groups) forwarded a
 *   post of the bot to it, or opened it by the post's button: whose post it
 *   is, and what the driver got - the operator's own chat (link_given), or
 *   the question for their contact (contact_asked), then the contact passed
 *   to the operator (contact_sent). not_found: no post of ours matched.
 *
 * telegram_sender_bot_events - the history: requests, decisions, posts and
 *   their rounds, pauses, stops, deletions, groups. actor: user | admin | system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_sender_bot_groups', function (Blueprint $table) {
            $table->string('invite_link', 255)->nullable()->after('username');
        });

        Schema::table('telegram_sender_bot_posts', function (Blueprint $table) {
            $table->json('group_ids')->nullable()->after('text');
            $table->json('payload')->nullable()->after('group_ids');
            $table->timestamp('paused_at')->nullable()->after('next_at');
            $table->unsignedBigInteger('repeat_of_id')->nullable()->after('stopped_by');
            $table->timestamp('delete_asked_at')->nullable()->after('finished_at');
            $table->string('delete_asked_by', 16)->nullable()->after('delete_asked_at');
            $table->timestamp('copies_deleted_at')->nullable()->after('delete_asked_by');
        });

        Schema::table('telegram_sender_bot_sends', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('error');
        });

        Schema::create('telegram_sender_bot_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_bot_post_id')->nullable()->index();
            /* The operator: the author of the post. */
            $table->unsignedBigInteger('sender_bot_user_id')->nullable()->index();
            $table->bigInteger('driver_telegram_id')->index();
            $table->string('driver_username')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 32)->nullable();
            $table->string('source', 16)->default('forward');
            $table->string('status', 16);
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_sender_bot_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->index();
            $table->string('actor', 16)->default('system');
            $table->unsignedBigInteger('sender_bot_user_id')->nullable()->index();
            $table->unsignedBigInteger('sender_bot_post_id')->nullable()->index();
            $table->unsignedBigInteger('sender_bot_group_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_sender_bot_events');
        Schema::dropIfExists('telegram_sender_bot_leads');

        Schema::table('telegram_sender_bot_sends', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });

        Schema::table('telegram_sender_bot_posts', function (Blueprint $table) {
            $table->dropColumn(['group_ids', 'payload', 'paused_at', 'repeat_of_id', 'delete_asked_at', 'delete_asked_by', 'copies_deleted_at']);
        });

        Schema::table('telegram_sender_bot_groups', function (Blueprint $table) {
            $table->dropColumn('invite_link');
        });
    }
};
