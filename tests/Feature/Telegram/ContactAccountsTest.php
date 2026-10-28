<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\AccountContactsService;
use App\Http\Controllers\Api\Telegram\ContactAccountsController;
use App\Http\Controllers\Api\Telegram\ContactCatalogController;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContactState;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The accounts page of the contacts: what the database lists for an account
 * against what the account holds, and clearing the database on its own.
 * (Reading an account and deleting from it need Telegram: not here.)
 */
final class ContactAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->string('session_path')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_account_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_account_id');
            $table->string('process');
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_busy')->default(false);
            $table->timestamp('busy_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();
        /* The group types and Join group's columns: the contact sync reads only its own groups. */
        (include database_path('migrations/2026_10_20_100000_create_telegram_group_join_tables.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001', 'session_path' => '/sessions/1'],
            ['id' => 2, 'phone' => '+998900000002', 'session_path' => '/sessions/2'],
            ['id' => 3, 'phone' => '+998900000003', 'session_path' => '/sessions/3'],
        ]);

        config()->set('services.telegram.driver_check_account_id', 3);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function person(int $id, array $extra = []): TelegramScrapedUser
    {
        return TelegramScrapedUser::create(['telegram_user_id' => $id, 'first_name' => 'P' . $id, ...$extra]);
    }

    /** The database says $accountId saved the person; the account may or may not be known to hold them. */
    private function held(int $accountId, TelegramScrapedUser $person, bool $link): void
    {
        DB::table('telegram_account_contacts')->insert([
            'telegram_account_id' => $accountId,
            'scraped_user_id' => $person->id,
            'status' => 'added',
            'added_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($link) {
            DB::table('telegram_account_contact_links')->insert([
                'telegram_account_id' => $accountId,
                'scraped_user_id' => $person->id,
                'synced_at' => now(),
            ]);
        }
    }

    private function accounts(): array
    {
        return collect($this->app->make(ContactAccountsController::class)->index()->getData(true)['data']['accounts'])->keyBy('id')->all();
    }

    public function test_the_database_count_is_the_people_it_holds_and_the_people_it_saw_in_the_account(): void
    {
        $held = $this->person(10);
        $both = $this->person(11);
        $seen = $this->person(12);

        $this->held(1, $held, link: false);
        $this->held(1, $both, link: true);
        DB::table('telegram_account_contact_links')->insert(['telegram_account_id' => 1, 'scraped_user_id' => $seen->id, 'synced_at' => now()]);

        $this->assertSame(3, AccountContactsService::inDatabase(1), 'held, held and seen, seen: three people, once each');
        $this->assertSame(0, AccountContactsService::inDatabase(2));
    }

    public function test_the_page_says_where_database_and_account_agree_and_where_not(): void
    {
        foreach ([20, 21, 22] as $id) {
            $this->held(1, $this->person($id), link: true);
        }

        /* Account 1 was read: it had 3. Account 2 was read: it had 5, the database knows none. Account 3 is the listener. */
        TelegramAccountContactState::of(1)->update(['in_account' => 3, 'synced_at' => now()]);
        TelegramAccountContactState::of(2)->update(['in_account' => 5, 'synced_at' => now()]);

        $accounts = $this->accounts();

        $this->assertSame(['match', 3, 3], [$accounts[1]['agree'], $accounts[1]['in_database'], $accounts[1]['in_account']]);
        $this->assertSame(['database_less', 0, 5], [$accounts[2]['agree'], $accounts[2]['in_database'], $accounts[2]['in_account']]);
        $this->assertFalse($accounts[1]['is_primary']);
        $this->assertTrue($accounts[3]['is_primary']);
        $this->assertFalse($accounts[3]['usable'], 'the listener account is open in the listener');
    }

    public function test_an_account_never_read_has_no_number_of_its_own(): void
    {
        $this->held(2, $this->person(30), link: false);

        $account = $this->accounts()[2];

        $this->assertNull($account['in_account']);
        $this->assertSame('unknown', $account['agree']);

        /* Cleaned from the account: now it is a fact, and the database still lists the person. */
        TelegramAccountContactState::of(2)->update(['in_account' => 0, 'cleared_account_at' => now()]);

        $this->assertSame(['account_less', 1, 0], [$this->accounts()[2]['agree'], $this->accounts()[2]['in_database'], $this->accounts()[2]['in_account']]);
    }

    public function test_clearing_the_database_leaves_the_groups_people_and_the_other_accounts(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'one']);

        $fromGroup = $this->person(40);
        $fromGroup->groups()->attach($group->id);
        $onlyHere = $this->person(41);
        $elsewhere = $this->person(42);

        $this->held(1, $fromGroup, link: true);
        $this->held(1, $onlyHere, link: true);
        $this->held(2, $elsewhere, link: true);

        TelegramAccountContactState::of(1)->update(['in_account' => 2, 'synced_at' => now()]);

        $done = $this->app->make(AccountContactsService::class)->clearDatabase(TelegramAccount::query()->findOrFail(1));

        $this->assertSame(['links' => 2, 'holders' => 2, 'people' => 1], $done);
        $this->assertSame(0, AccountContactsService::inDatabase(1));
        $this->assertSame(1, AccountContactsService::inDatabase(2), 'the other account is untouched');
        $this->assertNotNull(TelegramScrapedUser::query()->where('telegram_user_id', 40)->first(), 'in a watched group: it stays');
        $this->assertNull(TelegramScrapedUser::query()->where('telegram_user_id', 41)->first(), 'known only from the account: it goes');

        /* The account's own number is what it was: that is how "0 here, 2 there" shows. */
        $account = $this->accounts()[1];
        $this->assertSame(['database_less', 0, 2], [$account['agree'], $account['in_database'], $account['in_account']]);
        $this->assertNotNull($account['cleared_db_at']);
    }

    public function test_the_catalog_marks_who_is_on_the_account_and_who_is_not(): void
    {
        $on = $this->person(50);
        $off = $this->person(51);
        $unknown = $this->person(52);

        $this->held(1, $on, link: true);
        $this->held(1, $off, link: false);
        $this->held(2, $unknown, link: false);

        /* Account 1 was read; account 2 never. */
        TelegramAccountContactState::of(1)->update(['in_account' => 1, 'synced_at' => now()]);

        $rows = collect($this->app->make(ContactCatalogController::class)->index(Request::create('/', 'GET'))->getData(true)['data'])->keyBy('telegram_user_id');

        $this->assertTrue($rows[50]['on_account']);
        $this->assertSame('+998900000001', $rows[50]['holders'][0]['phone']);
        $this->assertFalse($rows[51]['on_account'], 'the account was read and does not have them');
        $this->assertNull($rows[52]['on_account'], 'the account was never read: not known');

        $names = fn (string $presence) => collect($this->app->make(ContactCatalogController::class)->index(Request::create('/', 'GET', ['presence' => $presence]))->getData(true)['data'])->pluck('telegram_user_id')->sort()->values()->all();

        $this->assertSame([50], $names('on_account'));
        $this->assertSame([51, 52], $names('not_on_account'));
    }

    public function test_somebody_already_held_by_one_account_is_not_collected_for_another(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'one']);
        $person = $this->person(60);
        $person->groups()->attach($group->id);

        $service = $this->app->make(\App\Application\Telegram\Services\ContactSyncService::class);
        $unclaimed = new \ReflectionMethod($service, 'hasUnclaimed');
        $groups = collect([$group]);

        $this->assertTrue($unclaimed->invoke($service, $groups, false), 'nobody has them: the sync may take them');

        /* Account 2 was read and holds them - no owner row, only the account's own contact. */
        DB::table('telegram_account_contact_links')->insert(['telegram_account_id' => 2, 'scraped_user_id' => $person->id, 'synced_at' => now()]);

        $this->assertFalse($unclaimed->invoke($service, $groups, false), 'held by an account: not for a second one');

        /* Held by two accounts is what a read of both gives; the person is still one row. */
        DB::table('telegram_account_contact_links')->insert(['telegram_account_id' => 1, 'scraped_user_id' => $person->id, 'synced_at' => now()]);

        $this->assertSame(2, DB::table('telegram_account_contact_links')->where('scraped_user_id', $person->id)->count());
        $this->assertSame(1, TelegramScrapedUser::query()->where('telegram_user_id', 60)->count());
    }

    public function test_a_phone_is_stored_in_one_form_whoever_creates_the_row(): void
    {
        $messy = ['+998 99 910-62-35', '998999106235', '00998 (99) 910 62 35', '+998999106235'];

        foreach ($messy as $spelling) {
            $this->assertSame('+998999106235', TelegramAccount::normalizePhone($spelling), $spelling);
        }

        $account = TelegramAccount::query()->create(['phone' => '+90 505 516-88-68']);
        $this->assertSame('+905055168868', $account->phone);
        $this->assertSame('+905055168868', DB::table('telegram_accounts')->where('id', $account->id)->value('phone'));

        /* The phone Telegram shows for somebody found in a group or an account: the same form, or nothing. */
        $this->assertSame('+16202202267', $this->person(70, ['phone' => '1 (620) 220-22-67'])->phone);
        $this->assertNull($this->person(71, ['phone' => ''])->phone);
        $this->assertNull($this->person(72)->phone);
    }

    public function test_a_dead_session_is_told_from_a_failed_call(): void
    {
        $service = $this->app->make(AccountContactsService::class);

        $this->assertSame('AUTH_KEY_UNREGISTERED', $service->accountError(new \RuntimeException('AUTH_KEY_UNREGISTERED')));
        $this->assertSame('SESSION_REVOKED', $service->accountError(new \RuntimeException('start failed', 0, new \RuntimeException('SESSION_REVOKED'))), 'wrapped, as MadelineProto does');
        $this->assertNull($service->accountError(new \RuntimeException('FLOOD_WAIT_30')));
    }

    public function test_a_dead_session_is_written_down_as_revoked_and_nothing_else_is(): void
    {
        $dead = new \RuntimeException('start failed', 0, new \RuntimeException('SESSION_REVOKED'));

        $this->assertSame('SESSION_REVOKED', \App\Application\Telegram\Support\DeadSession::code($dead));
        $this->assertNull(\App\Application\Telegram\Support\DeadSession::code(new \RuntimeException('FLOOD_WAIT_30')));
        $this->assertNull(\App\Application\Telegram\Support\DeadSession::code(new \RuntimeException('PHONE_NOT_OCCUPIED')));

        DB::table('telegram_accounts')->where('id', 2)->update(['is_authorized' => true]);
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->string('status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
        });

        \App\Application\Telegram\Support\DeadSession::retire(2, 'AUTH_KEY_UNREGISTERED');
        \App\Application\Telegram\Support\DeadSession::retire(null, 'AUTH_KEY_UNREGISTERED');

        $account = DB::table('telegram_accounts')->where('id', 2)->first();

        $this->assertSame([0, 'revoked', 'AUTH_KEY_UNREGISTERED'], [(int) $account->is_authorized, $account->status, $account->last_error]);
        $this->assertSame(1, (int) DB::table('telegram_accounts')->where('id', 1)->value('is_authorized'), 'the others are untouched');
    }

    public function test_the_listener_account_cannot_be_read_or_cleaned_from_the_account(): void
    {
        $response = $this->app->make(ContactAccountsController::class)->import(TelegramAccount::query()->findOrFail(3));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, TelegramAccountContactState::query()->count(), 'nothing was queued');
    }
}
