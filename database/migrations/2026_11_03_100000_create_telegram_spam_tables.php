<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Our join request bot deletes what the people of a list write in our
 * groups, a few seconds after they wrote it, and tells the owner without a
 * sound. They stay in the group: nobody is removed or muted.
 *
 * telegram_spam_senders - the list, kept in the panel. A person (or a
 *   channel writing in the group) is typed by id or by @username. One typed
 *   by @username gets their id the first time they write: from then on the
 *   id is what counts, a new @username does not save them. username is kept
 *   lowercase, without the @.
 *
 * telegram_spam_messages - every message of theirs the bot saw: waiting for
 *   its time (pending), deleted, or could not be deleted (failed, with
 *   Telegram's words). chat_id is the Bot API id (-100...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_spam_senders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('username', 64)->nullable();
            /* The name the last message came with. */
            $table->string('name')->nullable();
            $table->string('note')->nullable();
            $table->unsignedInteger('deleted_count')->default(0);
            $table->timestamp('last_deleted_at')->nullable();
            $table->timestamps();

            $table->index('telegram_user_id');
            $table->index('username');
        });

        Schema::create('telegram_spam_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spam_sender_id')
                ->nullable()
                ->constrained('telegram_spam_senders')
                ->nullOnDelete();
            $table->bigInteger('chat_id');
            $table->string('chat_title')->nullable();
            $table->bigInteger('message_id');
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('username', 64)->nullable();
            $table->string('name')->nullable();
            /* text | photo | video | sticker | voice | ... */
            $table->string('kind', 24);
            /* The text or the caption, cut short. */
            $table->text('text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delete_at');
            /* pending | deleted | failed */
            $table->string('status', 16);
            $table->string('error', 120)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
            $table->index(['status', 'delete_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_spam_messages');
        Schema::dropIfExists('telegram_spam_senders');
    }
};
