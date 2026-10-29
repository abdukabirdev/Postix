<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Adding the accounts' contacts into chosen groups. Separate from the
 * contact sync and from Join group: those find people and save them into
 * the phone book. This only invites people who are already a contact.
 *
 * telegram_invite_groups - the groups people are invited into.
 * telegram_group_invites - one row per person per group: invited or failed.
 *   Unique on the pair, so a person is tried once.
 * telegram_group_invite_logs - one line per run event, for the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_invite_groups', function (Blueprint $table) {
            $table->id();

            $table->string('title')->nullable();

            /* t.me/name, @name or an invite link, as the accounts open it. */
            $table->string('link', 255)->unique();

            $table->bigInteger('telegram_chat_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->text('last_error')->nullable();

            $table->timestamps();
        });

        Schema::create('telegram_group_invites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invite_group_id')
                ->constrained('telegram_invite_groups')
                ->cascadeOnDelete();

            /* Unique with the group: one person is tried once for that group. */
            $table->foreignId('scraped_user_id')
                ->constrained('telegram_scraped_users')
                ->cascadeOnDelete();

            $table->foreignId('telegram_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();

            /* pending (claimed) | invited | failed */
            $table->string('status', 16)->default('pending');

            $table->string('error', 120)->nullable();

            /* One id per inviteToChannel call, so a day can be counted in batches. */
            $table->uuid('batch_key')->nullable();

            $table->timestamp('attempted_at')->nullable();

            $table->timestamps();

            $table->unique(['invite_group_id', 'scraped_user_id'], 'tg_group_invite_once');
            $table->index(['telegram_account_id', 'attempted_at']);
            $table->index('batch_key');
            $table->index(['invite_group_id', 'status']);
        });

        Schema::create('telegram_group_invite_logs', function (Blueprint $table) {
            $table->id();

            $table->string('run_id', 36)->index();

            $table->foreignId('telegram_account_id')
                ->nullable()
                ->constrained('telegram_accounts')
                ->nullOnDelete();

            $table->foreignId('invite_group_id')
                ->nullable()
                ->constrained('telegram_invite_groups')
                ->nullOnDelete();

            /* started | batch | skipped | failed | flood | disabled | finished */
            $table->string('event', 24);

            $table->string('message', 500)->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_invite_logs');
        Schema::dropIfExists('telegram_group_invites');
        Schema::dropIfExists('telegram_invite_groups');
    }
};
