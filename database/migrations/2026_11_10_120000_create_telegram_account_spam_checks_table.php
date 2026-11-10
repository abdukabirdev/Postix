<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What @SpamBot says about an account: Telegram's own bot answers /start
 * with whether the account is limited (cannot write to strangers, cannot
 * add people) and until when. Asked from the accounts page, one account
 * or all of them, each answer is kept: when the limit came, when it went.
 *
 * status: free | limited | unknown (an answer we could not read: the text
 * is kept) | failed (the session did not answer: the error is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_account_spam_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_account_id')
                ->constrained('telegram_accounts')
                ->cascadeOnDelete();
            $table->string('status', 16);
            $table->timestamp('limited_until')->nullable();
            /* The bot's words, cut short. */
            $table->text('text')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['telegram_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_account_spam_checks');
    }
};
