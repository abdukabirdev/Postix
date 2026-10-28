<?php

namespace Tests\Feature\Telegram;

use App\Http\Controllers\Api\Telegram\ContactCatalogController;
use App\Http\Controllers\Api\Telegram\ContactSyncReportController;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramContactSyncLog;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two read-only endpoints behind /driver-check/contacts (the catalog) and
 * /driver-check/contact-report (the daily report).
 */
final class ContactCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->timestamps();
        });

        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();
        /* The group types and Join group's columns: the contact sync reads only its own groups. */
        (include database_path('migrations/2026_10_20_100000_create_telegram_group_join_tables.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001'],
            ['id' => 2, 'phone' => '+998900000002'],
        ]);

        config()->set('services.telegram.contact_sync.schedule_at', '03:30');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function catalog(array $query = []): array
    {
        $request = Request::create('/', 'GET', $query);

        return $this->app->make(ContactCatalogController::class)->index($request)->getData(true);
    }

    private function report(): array
    {
        $request = Request::create('/', 'GET', ['days' => 5]);

        return $this->app->make(ContactSyncReportController::class)->show($request)->getData(true)['data'];
    }

    private function person(int $id, string $name, array $extra = []): TelegramScrapedUser
    {
        return TelegramScrapedUser::create([
            'telegram_user_id' => $id,
            'first_name' => $name,
            'username' => strtolower($name),
            ...$extra,
        ]);
    }

    public function test_the_catalog_says_who_saved_whom_and_who_is_still_waiting(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'one', 'title' => 'Drivers']);

        $saved = $this->person(10, 'Ali');
        $this->person(11, 'Vali');
        $bot = $this->person(12, 'Robot', ['is_bot' => true]);

        $saved->groups()->attach($group->id);
        TelegramAccountContact::create([
            'telegram_account_id' => 1,
            'scraped_user_id' => $saved->id,
            'status' => 'added',
            'added_at' => now(),
        ]);

        $all = $this->catalog();

        $this->assertSame(2, $all['meta']['total'], 'bots are left out unless asked for');
        $this->assertSame(['people' => 2, 'added' => 1, 'pending' => 0, 'failed' => 0, 'skipped' => 0, 'unassigned' => 0, 'by_link' => 1, 'today' => 1], $all['stats']);

        $row = collect($all['data'])->firstWhere('telegram_user_id', 10);
        $this->assertSame('added', $row['status']);
        $this->assertSame('+998900000001', $row['account']['phone']);
        $this->assertSame('Drivers', $row['groups'][0]['title']);

        $this->assertSame('by_link', collect($all['data'])->firstWhere('telegram_user_id', 11)['status'], 'a @username stays in the database');
        $this->assertSame(['Vali'], collect($this->catalog(['status' => 'by_link'])['data'])->pluck('name')->all());
        $this->assertSame(['Ali'], collect($this->catalog(['status' => 'added'])['data'])->pluck('name')->all());
        $this->assertSame(3, $this->catalog(['bots' => 1])['meta']['total']);
        $this->assertSame(['Vali'], collect($this->catalog(['search' => '@vali'])['data'])->pluck('name')->all());
        $this->assertSame(['Ali'], collect($this->catalog(['search' => '10'])['data'])->pluck('name')->all());
        $this->assertNotNull($bot->id);
    }

    public function test_only_people_without_a_username_wait_for_a_contact_unless_switched_off(): void
    {
        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();

        $this->person(30, 'Ali');
        $this->person(31, 'Vali', ['username' => null]);

        $settings = $this->app->make(\App\Application\Telegram\Services\ContactSyncSettings::class);

        $settings->save(['skip_usernames' => false]);
        $off = $this->catalog();

        $this->assertSame([0, 2], [$off['stats']['by_link'], $off['stats']['unassigned']], 'switched off: everybody waits');
        $this->assertSame([], $this->catalog(['status' => 'by_link'])['data']);

        $settings->save(['skip_usernames' => true]);
        $on = $this->catalog();

        $this->assertSame([1, 1], [$on['stats']['by_link'], $on['stats']['unassigned']]);
        $this->assertSame(['Vali'], collect($this->catalog(['status' => 'unassigned'])['data'])->pluck('name')->all());
        $this->assertSame(['Ali'], collect($this->catalog(['status' => 'by_link'])['data'])->pluck('name')->all());
    }

    public function test_the_catalog_narrows_by_group_and_by_account_and_offers_both(): void
    {
        $drivers = TelegramScrapeGroup::create(['link' => 'one', 'title' => 'Drivers']);
        $other = TelegramScrapeGroup::create(['link' => 'two']);

        $ali = $this->person(20, 'Ali');
        $vali = $this->person(21, 'Vali');

        $ali->groups()->attach($drivers->id);
        $vali->groups()->attach($other->id);

        TelegramAccountContact::create(['telegram_account_id' => 1, 'scraped_user_id' => $ali->id, 'status' => 'added', 'added_at' => now()]);
        TelegramAccountContact::create(['telegram_account_id' => 2, 'scraped_user_id' => $vali->id, 'status' => 'added', 'added_at' => now()]);

        $names = fn (array $query) => collect($this->catalog($query)['data'])->pluck('name')->all();

        $this->assertSame(['Ali'], $names(['group_id' => $drivers->id]));
        $this->assertSame(['Vali'], $names(['account_id' => 2]));
        $this->assertSame([], $names(['group_id' => $drivers->id, 'account_id' => 2]));

        $options = $this->catalog()['options'];

        $this->assertSame(['Drivers', 'two'], array_column($options['groups'], 'title'), 'a group without a title shows its link');
        $this->assertSame([1, 2], array_column($options['accounts'], 'id'));
    }

    public function test_the_catalog_pages_for_infinite_scroll(): void
    {
        foreach (range(1, 7) as $i) {
            $this->person(100 + $i, 'P' . $i);
        }

        $first = $this->catalog(['per_page' => 5]);
        $second = $this->catalog(['per_page' => 5, 'page' => 2]);

        $this->assertSame([5, 2, 2, 7], [count($first['data']), count($second['data']), $first['meta']['last_page'], $first['meta']['total']]);
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }

    public function test_the_report_shows_a_run_that_finished_and_a_day_without_one(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $log = fn (string $event, string $run, string $at, ?array $context = null, ?int $account = null) => TelegramContactSyncLog::create([
            'run_id' => $run,
            'event' => $event,
            'telegram_account_id' => $account,
            'message' => $event,
            'context' => $context,
        ])->forceFill(['created_at' => Carbon::parse($at)])->save();

        $log('started', 'run-1', '2026-10-15 03:30:00');
        $log('added', 'run-1', '2026-10-15 03:31:00', null, 1);
        $log('added', 'run-1', '2026-10-15 03:32:00', null, 1);
        $log('failed', 'run-1', '2026-10-15 03:33:00', null, 1);
        $log('finished', 'run-1', '2026-10-15 03:40:00', ['accounts' => 1, 'added' => 2, 'failed' => 1, 'members' => 40]);

        $report = $this->report();

        $this->assertSame('ok', $report['schedule']['status']);
        $this->assertFalse($report['schedule']['overdue']);
        $this->assertSame(600, $report['schedule']['last_duration_seconds']);
        $this->assertSame(40, $report['schedule']['last_result']['members']);
        $this->assertSame('2026-10-16 03:30', Carbon::parse($report['schedule']['next_run_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i'));

        $today = $report['days'][0];
        $this->assertSame(['2026-10-15', 1, 2, 1, 1, 40], [$today['date'], $today['runs'], $today['added'], $today['failed'], $today['accounts'], $today['members']]);
        $this->assertSame(0, $report['days'][1]['runs'], 'yesterday had no run: a hole, not a missing line');
        $this->assertCount(5, $report['days']);
        $this->assertSame([['id' => 1, 'phone' => '+998900000001', 'username' => null, 'added' => 0, 'failed' => 1]], $report['accounts_today']);
    }

    public function test_a_missed_schedule_is_called_overdue(): void
    {
        /* Past 03:30 + grace, nothing started today. */
        Carbon::setTestNow('2026-10-15 09:00:00');

        $this->assertTrue($this->report()['schedule']['overdue']);
        $this->assertSame('never', $this->report()['schedule']['status']);

        /* Before the time, the same silence is not a problem yet. */
        Carbon::setTestNow('2026-10-15 02:00:00');

        $this->assertFalse($this->report()['schedule']['overdue']);
    }

    public function test_a_run_that_began_and_never_ended_is_interrupted(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        TelegramContactSyncLog::create(['run_id' => 'run-x', 'event' => 'started', 'message' => 's'])
            ->forceFill(['created_at' => Carbon::parse('2026-10-15 03:30:00')])->save();

        $schedule = $this->report()['schedule'];

        $this->assertSame('interrupted', $schedule['status']);
        $this->assertFalse($schedule['overdue']);
        $this->assertNull($schedule['last_finished_at']);
    }
}
