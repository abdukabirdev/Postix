<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\SpamBotCheck;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountSpamCheck;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What @SpamBot says about an account is read and kept: free, limited
 * until a moment, not understood, or the session did not answer. The
 * account's own process that asks the bot is stood in for.
 */
final class SpamBotCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('session_path')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->timestamps();
        });

        (include database_path('migrations/2026_11_10_120000_create_telegram_account_spam_checks_table.php'))->up();

        DB::table('telegram_accounts')->insert(['id' => 1, 'phone' => '+998900000001', 'session_path' => '/sessions/1']);
    }

    public function test_the_bots_words_are_read_in_three_languages(): void
    {
        $free = SpamBotCheck::verdict("Good news, no limits are currently applied to your account. You're free as a bird!");
        $this->assertSame(TelegramAccountSpamCheck::FREE, $free['status']);
        $this->assertNull($free['until']);

        $this->assertSame(TelegramAccountSpamCheck::FREE, SpamBotCheck::verdict('Хорошие новости! На ваш аккаунт не наложено никаких ограничений.')['status']);
        $this->assertSame(TelegramAccountSpamCheck::FREE, SpamBotCheck::verdict("Yaxshi xabar! Akkauntingizga hech qanday cheklovlar qo'yilmagan.")['status']);

        $limited = SpamBotCheck::verdict("I'm afraid some Telegram users found your messages annoying and reported them as spam.\n\nYour account is now limited until 11 Nov 2026, 06:10 UTC.");
        $this->assertSame(TelegramAccountSpamCheck::LIMITED, $limited['status']);
        $this->assertSame('2026-11-11 06:10:00', $limited['until']?->toDateTimeString());

        $limited = SpamBotCheck::verdict('К сожалению, некоторые пользователи пожаловались на ваши сообщения. Ваш аккаунт ограничен до 11 ноя. 2026 г., 06:10 UTC.');
        $this->assertSame(TelegramAccountSpamCheck::LIMITED, $limited['status']);
        $this->assertSame('2026-11-11 06:10:00', $limited['until']?->toDateTimeString());

        $limited = SpamBotCheck::verdict('Your account is now limited until Nov 15, 2026, 12:00 UTC.');
        $this->assertSame('2026-11-15 12:00:00', $limited['until']?->toDateTimeString());

        /* Limited for good: no moment to read. */
        $limited = SpamBotCheck::verdict('Unfortunately, our moderators have confirmed the reports. Your account is now limited.');
        $this->assertSame(TelegramAccountSpamCheck::LIMITED, $limited['status']);
        $this->assertNull($limited['until']);

        $this->assertSame(TelegramAccountSpamCheck::UNKNOWN, SpamBotCheck::verdict('Hi! Please choose a language.')['status']);
    }

    public function test_the_answer_of_the_accounts_process_is_kept_as_it_went(): void
    {
        $account = TelegramAccount::query()->find(1);

        $check = new SpamBotCheck(fn () => "some noise\n" . SpamBotCheck::line(true, 'Your account is now limited until 11 Nov 2026, 06:10 UTC.') . "\n");
        $row = $check->ask($account);

        $this->assertSame(TelegramAccountSpamCheck::LIMITED, $row->status);
        $this->assertSame('2026-11-11 06:10:00', $row->limited_until?->toDateTimeString());
        $this->assertStringContainsString('limited until', (string) $row->text);

        $check = new SpamBotCheck(fn () => SpamBotCheck::line(false, error: 'AUTH_KEY_UNREGISTERED'));
        $row = $check->ask($account);

        $this->assertSame(TelegramAccountSpamCheck::FAILED, $row->status);
        $this->assertSame('AUTH_KEY_UNREGISTERED', $row->error);

        $check = new SpamBotCheck(fn () => 'the process died without a word');
        $row = $check->ask($account);

        $this->assertSame(TelegramAccountSpamCheck::FAILED, $row->status);

        $check = new SpamBotCheck(fn () => SpamBotCheck::line(true, "Good news, no limits are currently applied to your account."));
        $check->ask($account);

        /* The latest answer per account is the one the page shows. */
        $latest = SpamBotCheck::latest();
        $this->assertCount(1, $latest);
        $this->assertSame(TelegramAccountSpamCheck::FREE, $latest[1]->status);
        $this->assertSame(4, TelegramAccountSpamCheck::query()->count());
    }
}
