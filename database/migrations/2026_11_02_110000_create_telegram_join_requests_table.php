<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Our own bot takes the requests to join our groups: it approves every one
 * and writes down who asked, where, and what came of it.
 *
 * telegram_join_requests - one line per request the bot got.
 *   chat_id is the Bot API id (-100...), the same form as
 *   telegram_invite_groups.telegram_chat_id. invite_group_id is set when
 *   the chat is one of our groups. A person goes into the base
 *   (scraped_user_id); one of our own accounts does not - telegram_account_id
 *   tells it is ours (it asked to join so it could add people).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_join_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->string('chat_title')->nullable();
            $table->foreignId('invite_group_id')
                ->nullable()
                ->constrained('telegram_invite_groups')
                ->nullOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->foreignId('scraped_user_id')
                ->nullable()
                ->constrained('telegram_scraped_users')
                ->nullOnDelete();
            $table->foreignId('telegram_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();
            /* The person was not in the base before this request. */
            $table->boolean('is_new')->default(false);
            /* approved | failed */
            $table->string('status', 16);
            $table->string('error', 120)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['telegram_account_id', 'created_at']);
            $table->index(['telegram_user_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_join_requests');
    }
};
