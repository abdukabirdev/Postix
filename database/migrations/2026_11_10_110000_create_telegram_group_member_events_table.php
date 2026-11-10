<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who came into our groups, who left, who was removed and by whom. Our join
 * request bot, an admin there, is told of every change of a member by
 * Telegram (chat_member updates) and wrote none of it down until 10.11:
 * when 321 of the people we had added were gone from a group in a night,
 * nothing could tell whether they had left or been removed, and by whom.
 *
 * One row per change. event: joined (came by a link or a request) | added
 * (brought in by somebody) | left (went by themselves) | removed (an admin
 * took them out, or banned them) | banned (banned while not in) | unbanned |
 * restricted | unrestricted | promoted | demoted | changed. Who did it:
 * actor_kind self | ours (one of our accounts) | bot (our join bot) | other,
 * with the Telegram id and name. The member matched against the base
 * (scraped_user_id), against our accounts (member_account_id), and against
 * our invites of the group: invited_by_account_id is the account of ours
 * that added them, when one did. Kept KEEP_DAYS (MemberEvents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_member_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invite_group_id')
                ->nullable()
                ->constrained('telegram_invite_groups')
                ->nullOnDelete();
            /* The Bot API id (-100...). */
            $table->bigInteger('chat_id');
            $table->string('chat_title')->nullable();
            $table->bigInteger('telegram_user_id');
            $table->string('username', 64)->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->foreignId('scraped_user_id')
                ->nullable()
                ->constrained('telegram_scraped_users')
                ->nullOnDelete();
            $table->foreignId('member_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();
            $table->foreignId('invited_by_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();
            $table->string('event', 16);
            /* link | request | folder: how somebody who joined came in. */
            $table->string('via', 16)->nullable();
            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->string('actor_kind', 8);
            $table->bigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->foreignId('actor_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['invite_group_id', 'happened_at']);
            $table->index(['event', 'happened_at']);
            $table->index('telegram_user_id');
            $table->index('happened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_member_events');
    }
};
