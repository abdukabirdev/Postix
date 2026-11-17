<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The sender bot: our people write a message to the bot, and the bot posts
 * it into the groups the admin set, as many times as asked, with a pause
 * in between.
 *
 * telegram_sender_bot_users - whoever pressed Start. status: new (chose no
 *   language yet) | pending (asked the admin) | approved | rejected |
 *   blocked (was approved, the admin took it back). step + draft: where the
 *   person is in the bot's questions. notified_at / notify_error: the admin
 *   was told of the request by the listener account (SenderBotNotices).
 *
 * telegram_sender_bot_groups - where the posts go. The bot sees itself added
 *   to a group (my_chat_member) and lists it switched off; the admin turns
 *   it on in the panel, or adds it there by @name / id.
 *
 * telegram_sender_bot_posts - one message to send: the person's own message
 *   in the bot's chat (source_*) is copied into every group that is on,
 *   `times` rounds, `interval_minutes` apart. status: active | done |
 *   stopped (by the person or the admin) | failed (no round got anywhere).
 *
 * telegram_sender_bot_sends - one copy into one group in one round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_sender_bot_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language', 8)->nullable();

            $table->string('status', 16)->default('new')->index();
            $table->string('step', 32)->nullable();
            $table->json('draft')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->string('notify_error', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
        });

        Schema::create('telegram_sender_bot_groups', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('username')->nullable();
            $table->string('type', 16)->nullable();
            $table->boolean('is_active')->default(false);
            /* What the bot is there: member | administrator | left | kicked | restricted. */
            $table->string('bot_status', 16)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_sender_bot_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_bot_user_id')
                ->constrained('telegram_sender_bot_users')
                ->cascadeOnDelete();

            $table->bigInteger('source_chat_id');
            $table->bigInteger('source_message_id');
            $table->string('kind', 16)->default('text');
            $table->text('text')->nullable();

            $table->unsignedSmallInteger('times')->default(1);
            $table->unsignedInteger('interval_minutes')->default(0);
            $table->unsignedSmallInteger('sent_times')->default(0);
            $table->timestamp('next_at')->nullable()->index();

            $table->string('status', 16)->default('active')->index();
            $table->string('stopped_by', 16)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_sender_bot_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_bot_post_id')
                ->constrained('telegram_sender_bot_posts')
                ->cascadeOnDelete();
            $table->foreignId('sender_bot_group_id')
                ->nullable()
                ->constrained('telegram_sender_bot_groups')
                ->nullOnDelete();
            $table->unsignedSmallInteger('round');
            $table->bigInteger('message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_sender_bot_sends');
        Schema::dropIfExists('telegram_sender_bot_posts');
        Schema::dropIfExists('telegram_sender_bot_groups');
        Schema::dropIfExists('telegram_sender_bot_users');
    }
};
