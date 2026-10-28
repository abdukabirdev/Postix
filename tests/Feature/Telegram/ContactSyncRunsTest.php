<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\ContactSyncSettings;
use App\Http\Controllers\Api\Telegram\ContactSyncRunController;
use App\Http\Controllers\Api\Telegram\ContactSyncSettingsController;
use App\Models\Telegram\TelegramContactSyncLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The settings of the contact sync, the nick its contacts are saved under,
 * and the history of runs with one run in detail.
 */
final class ContactSyncRunsTest extends TestCase
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

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_16_100000_add_contact_name_to_telegram_account_contacts_table.php'))->up();
        /* The group types and Join group's columns: the contact sync reads only its own groups. */
        (include database_path('migrations/2026_10_20_100000_create_telegram_group_join_tables.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001', 'username' => 'one'],
            ['id' => 2, 'phone' => '+998900000002', 'username' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function settings(): ContactSyncSettings
    {
        return $this->app->make(ContactSyncSettings::class);
    }

    private function saveViaPanel(array $input)
    {
        return $this->app->make(ContactSyncSettingsController::class)
            ->update(Request::create('/', 'PUT', $input));
    }

    public function test_settings_fall_back_to_the_env_values_until_saved(): void
    {
        config()->set('services.telegram.driver_check_account_id', null);
        config()->set('services.telegram.contact_sync', ['daily_limit' => 40, 'delay_min' => 1, 'delay_max' => 2, 'schedule_at' => '04:10']);

        $this->assertSame(
            ['enabled' => true, 'schedule_at' => '04:10', 'daily_limit' => 40, 'skip_usernames' => true, 'delay_min' => 1, 'delay_max' => 2, 'nick_base' => '', 'excluded_accounts' => []],
            $this->settings()->current(),
        );
        $this->assertFalse($this->settings()->isSaved());
    }

    public function test_saved_settings_win_and_are_held_to_their_bounds(): void
    {
        $saved = $this->settings()->save([
            'enabled' => false,
            'schedule_at' => '99:99',
            'daily_limit' => 0,
            'delay_min' => 10,
            'delay_max' => 4,
            'nick_base' => "  Aka \n Vali  ",
        ]);

        $this->assertFalse($saved['enabled']);
        $this->assertSame('03:30', $saved['schedule_at'], 'a bad time falls back');
        $this->assertSame(1, $saved['daily_limit']);
        $this->assertArrayNotHasKey('max_accounts', $saved, 'every available account takes part');
        $this->assertSame([10, 10], [$saved['delay_min'], $saved['delay_max']], 'the upper pause is never below the lower');
        $this->assertSame('Aka Vali', $saved['nick_base']);

        $this->assertTrue($this->settings()->isSaved());
        $this->assertSame($saved, $this->settings()->current());

        $this->settings()->reset();
        $this->assertFalse($this->settings()->isSaved());
    }

    public function test_the_driver_check_account_is_left_out_by_default_and_always(): void
    {
        config()->set('services.telegram.driver_check_account_id', 1);

        $this->assertSame([1], $this->settings()->current()['excluded_accounts'], 'out of the box');

        $saved = $this->settings()->save(['excluded_accounts' => [2, '2', 0]]);
        $this->assertSame([1, 2], $saved['excluded_accounts'], 'the chosen ones, once each, and the listener');

        $saved = $this->settings()->save(['excluded_accounts' => []]);
        $this->assertSame([1], $saved['excluded_accounts'], 'it cannot be let in: the pool never hands it to a sync anyway');
    }

    public function test_only_accounts_that_exist_can_be_left_out(): void
    {
        $this->expectException(ValidationException::class);

        $this->saveViaPanel(['enabled' => true, 'schedule_at' => '03:30', 'daily_limit' => 50, 'delay_min' => 1, 'delay_max' => 2, 'excluded_accounts' => [999]]);
    }

    public function test_the_panel_refuses_nonsense(): void
    {
        $this->expectException(ValidationException::class);

        $this->saveViaPanel(['enabled' => true, 'schedule_at' => '3:30', 'daily_limit' => 50, 'delay_min' => 1, 'delay_max' => 2]);
    }

    public function test_the_daily_run_is_due_at_the_set_minute_only_when_on(): void
    {
        $this->settings()->save(['enabled' => true, 'schedule_at' => '05:15']);

        Carbon::setTestNow('2026-10-16 05:15:20');
        $this->assertTrue($this->settings()->dueNow());

        Carbon::setTestNow('2026-10-16 05:16:00');
        $this->assertFalse($this->settings()->dueNow());

        $this->settings()->save(['enabled' => false, 'schedule_at' => '05:15']);
        Carbon::setTestNow('2026-10-16 05:15:20');
        $this->assertFalse($this->settings()->dueNow());
    }

    public function test_nicks_count_up_from_the_base(): void
    {
        $this->assertSame(
            ['xxx', 'xxx-1', 'xxx-2', 'xxx-10'],
            array_map(fn (int $i) => ContactSyncSettings::nick('xxx', $i), [0, 1, 2, 10]),
        );
    }

    public function test_the_next_nick_number_is_counted_per_account_and_base(): void
    {
        foreach ([[1, 'xxx'], [1, 'xxx-1'], [1, 'xxx-2'], [1, 'yyy'], [2, 'xxx']] as $i => [$account, $name]) {
            DB::table('telegram_scraped_users')->insert(['id' => $i + 1, 'telegram_user_id' => 900 + $i]);
            DB::table('telegram_account_contacts')->insert([
                'telegram_account_id' => $account,
                'scraped_user_id' => $i + 1,
                'status' => 'added',
                'contact_name' => $name,
            ]);
        }

        $service = $this->app->make(\App\Application\Telegram\Services\ContactSyncService::class);
        $count = new \ReflectionMethod($service, 'nickCount');
        $account = fn (int $id) => \App\Models\Telegram\TelegramAccount::query()->findOrFail($id);

        $this->assertSame(3, $count->invoke($service, $account(1), 'xxx'), 'xxx, xxx-1, xxx-2 - the next one is xxx-3');
        $this->assertSame(1, $count->invoke($service, $account(1), 'yyy'));
        $this->assertSame(1, $count->invoke($service, $account(2), 'xxx'), 'each account counts for itself');
        $this->assertSame(0, $count->invoke($service, $account(2), 'zzz'));
    }

    private function log(string $run, string $event, string $at, array $extra = []): void
    {
        TelegramContactSyncLog::create([
            'run_id' => $run,
            'event' => $event,
            'message' => $extra['message'] ?? $event,
            'telegram_account_id' => $extra['account'] ?? null,
            'group_id' => $extra['group'] ?? null,
            'context' => $extra['context'] ?? null,
        ])->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    public function test_every_run_is_a_row_and_opens_into_its_accounts(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');

        DB::table('telegram_scrape_groups')->insert(['id' => 1, 'link' => 'drivers', 'title' => 'Drivers']);

        $first = '11111111-1111-4111-8111-111111111111';
        $second = '22222222-2222-4222-8222-222222222222';

        $this->log($first, 'started', '2026-10-15 03:30:00', ['context' => ['trigger' => 'schedule', 'groups' => ['drivers'], 'daily_limit' => 80, 'nick_base' => 'xxx']]);
        $this->log($first, 'members', '2026-10-15 03:31:00', ['account' => 1, 'group' => 1, 'message' => '412 members read']);
        $this->log($first, 'added', '2026-10-15 03:32:00', ['account' => 1, 'group' => 1]);
        $this->log($first, 'added', '2026-10-15 03:33:00', ['account' => 1, 'group' => 1]);
        $this->log($first, 'failed', '2026-10-15 03:34:00', ['account' => 2, 'group' => 1]);
        $this->log($first, 'flood', '2026-10-15 03:35:00', ['account' => 2, 'message' => 'FLOOD_WAIT_300']);
        $this->log($first, 'finished', '2026-10-15 03:40:00', ['context' => ['accounts' => 2, 'added' => 2, 'failed' => 1, 'members' => 412]]);

        $this->log($second, 'started', '2026-10-16 03:30:00', ['context' => ['trigger' => 'manual', 'groups' => ['drivers']]]);

        $controller = $this->app->make(ContactSyncRunController::class);
        $list = $controller->index(Request::create('/', 'GET'))->getData(true);

        $this->assertSame(2, $list['meta']['total']);
        $this->assertSame([$second, $first], array_column($list['data'], 'run_id'), 'newest first');

        $done = $list['data'][1];
        $this->assertSame(['schedule', 'ok', 2, 2, 1, 1, 412, 600], [$done['trigger'], $done['status'], $done['accounts'], $done['added'], $done['failed'], $done['held'], $done['members'], $done['duration_seconds']]);
        $this->assertSame('xxx', $done['settings']['nick_base']);
        $this->assertSame('interrupted', $list['data'][0]['status'], 'began, never ended, nothing holds the lock');

        $detail = $controller->show($first)->getData(true)['data'];

        $this->assertSame(2, $detail['run']['accounts']);
        $this->assertSame(
            [[1, 2, 0, 0, null], [2, 0, 0, 1, 'flood']],
            array_map(fn ($a) => [$a['id'], $a['added'], $a['skipped'], $a['failed'], $a['stopped']], $detail['accounts']),
        );
        $this->assertSame('FLOOD_WAIT_300', $detail['accounts'][1]['stopped_reason']);
        $this->assertSame([['id' => 1, 'title' => 'Drivers', 'members' => 412, 'added' => 2, 'skipped' => 0, 'failed' => 1]], $detail['groups']);
    }

    public function test_an_unknown_run_is_not_found(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->app->make(ContactSyncRunController::class)->show('33333333-3333-4333-8333-333333333333');
    }
}
