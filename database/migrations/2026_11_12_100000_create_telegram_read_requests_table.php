<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The developer's chat reader (GET /api/telegram/reader/...): only the process
 * that holds an account's session can talk to Telegram, so the API leaves a
 * request here and that process answers it on its tick (ChatReader::serve()).
 *
 * status: pending | running | done | failed. `result` is chat content: it is
 * cleared an hour after the answer (ChatReadQueue::prune()); the row itself
 * (who asked what of which account, when) stays two weeks as the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_read_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('telegram_account_id');
            $table->string('op', 16);
            $table->json('params')->nullable();

            $table->string('status', 16)->default('pending');
            $table->longText('result')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_account_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_read_requests');
    }
};
