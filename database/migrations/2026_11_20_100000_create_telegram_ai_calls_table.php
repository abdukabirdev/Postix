<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every call to Claude: what was asked (short), what it answered, whether
 * the answer was used, tokens and money. The panel's journal, the spend of
 * the month for the cap and the shadow mode's "what the AI would have
 * written" all read this table.
 *
 * area:   driver_check | penalties | replies | profile | test
 * mode:   live (the answer is used) | shadow (only written down)
 * status: ok | failed (error says why) | refused (Claude declined)
 * used:   the answer really went out / decided the verdict
 *
 * operation_users.ai_profile: how Shohruh talks with the person, a short
 * text the AI reads before writing to them; made from the chat, editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_ai_calls', function (Blueprint $table) {
            $table->id();

            $table->string('area', 32)->index();
            $table->string('kind', 32)->nullable();
            $table->string('mode', 16);
            $table->string('status', 16)->index();
            $table->boolean('used')->default(false);

            $table->foreignId('operation_user_id')
                ->nullable()
                ->constrained('operation_users')
                ->nullOnDelete();
            /* What it was about: a driver check, a penalty row... */
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->text('request')->nullable();
            $table->json('answer')->nullable();
            /* What the free rules sent instead, for the shadow comparison. */
            $table->text('free_text')->nullable();
            $table->text('error')->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::table('operation_users', function (Blueprint $table) {
            $table->text('ai_profile')->nullable()->after('personal_answers');
        });
    }

    public function down(): void
    {
        Schema::table('operation_users', function (Blueprint $table) {
            $table->dropColumn('ai_profile');
        });

        Schema::dropIfExists('telegram_ai_calls');
    }
};
