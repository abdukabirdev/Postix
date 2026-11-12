<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "❌ Запрос отменён (EGS21300)": the CRM bot says a request was cancelled.
 * The person who got the request's last penalty is asked why, in a reply to
 * that penalty in their private chat, from the account that delivers their
 * penalties.
 *
 * status: pending (waiting for the account's tick) | sending | sent |
 * skipped (reason says why: no penalty for the request, switched off, ...) |
 * failed (error kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_request_cancellations', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->text('message_text')->nullable();
            $table->json('parsed')->nullable();
            $table->string('request_number', 32)->nullable()->index();

            /* The request's last penalty that reached somebody. */
            $table->foreignId('telegram_client_check_id')
                ->nullable()
                ->constrained('telegram_client_checks')
                ->nullOnDelete();
            $table->foreignId('operation_user_id')
                ->nullable()
                ->constrained('operation_users')
                ->nullOnDelete();

            $table->string('status', 16)->default('pending')->index();
            $table->string('reason', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();

            $table->string('peer')->nullable();
            /* The penalty message the question replies to; null - sent without a reply. */
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->text('question')->nullable();
            $table->timestamp('asked_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['telegram_chat_id', 'telegram_message_id'],
                'telegram_request_cancellations_message_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_request_cancellations');
    }
};
