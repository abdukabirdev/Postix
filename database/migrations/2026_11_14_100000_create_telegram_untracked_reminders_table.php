<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "📍 Не назначенные слежения": the CRM bot lists, a few times a day, how
 * many of each operator's orders have no tracking yet. Every operator of
 * the list is hurried in their private chat, harder each time they are in
 * it again that day (level). One row per operator per report.
 *
 * report_key: chat:message - every message is a report of its own (the
 * owner tests by posting copies into a watched group).
 *
 * status: pending (waiting for the account's tick) | sending | sent |
 * skipped (reason says why: unknown person, switched off, a newer report,
 * waited too long) | failed (error kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_untracked_reminders', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->string('report_key', 64);
            $table->text('message_text')->nullable();

            $table->string('name');
            $table->string('name_normalized')->index();
            $table->unsignedInteger('orders_count');
            /* 1 - the first report of the day that names them, 2 - the second, ... */
            $table->unsignedTinyInteger('level')->default(1);

            $table->foreignId('operation_user_id')
                ->nullable()
                ->constrained('operation_users')
                ->nullOnDelete();

            $table->string('status', 16)->default('pending')->index();
            $table->string('reason', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();

            $table->string('peer')->nullable();
            $table->string('phrase', 500)->nullable();
            $table->text('text')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['report_key', 'name_normalized'], 'telegram_untracked_reminders_report_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_untracked_reminders');
    }
};
