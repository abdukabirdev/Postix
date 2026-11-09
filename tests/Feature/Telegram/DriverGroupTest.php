<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\DriverGroupService;
use App\Application\Telegram\Services\DriverGroupSettings;
use App\Application\Telegram\Services\MadelineService;
use App\Application\Telegram\Support\SessionProbe;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Jobs\Telegram\StartDriverGroupAddJob;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramDriverGroupAdd;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramResolvedPhone;
use App\Models\Telegram\TelegramSetting;
use ArrayObject;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Drivers into a group: who comes into the queue (the button, a check
 * while "add on resolve" is on), who adds (the account that found the
 * driver, with the access hash of its own lookup; another one that finds
 * the driver by the number again when the first cannot work), where a try
 * ends, and how Telegram's trouble stops an account. The calls are a stand-in.
 */
final class DriverGroupTest extends TestCase
{
    private TelegramInviteGroup $group;

    /** @var ArrayObject<int, array{account: int, method: string, args: array}> */
    private ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.driver_check_account_id' => null]);

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('session_path')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->string('status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
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

        Schema::create('telegram_drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_normalized')->nullable();
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_resolved_phones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('phone_normalized', 32)->unique();
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->json('telegram_raw')->nullable();
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_driver_checks', function (Blueprint $table) {
            $table->id();
            $table->string('driver_name')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->unsignedBigInteger('telegram_resolved_phone_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();
        (include database_path('migrations/2026_10_29_120000_create_telegram_group_invite_tables.php'))->up();
        (include database_path('migrations/2026_10_29_140000_add_invite_flags_to_telegram_tables.php'))->up();
        (include database_path('migrations/2026_10_29_160000_check_telegram_group_invites.php'))->up();
        (include database_path('migrations/2026_10_30_100000_keep_telegram_group_invite_history.php'))->up();
        (include database_path('migrations/2026_11_02_110000_create_telegram_join_requests_table.php'))->up();
        (include database_path('migrations/2026_11_05_120000_create_telegram_driver_group_adds_table.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001', 'telegram_user_id' => 9001, 'session_path' => '/sessions/1', 'is_authorized' => true],
            ['id' => 2, 'phone' => '+998900000002', 'telegram_user_id' => 9002, 'session_path' => '/sessions/2', 'is_authorized' => true],
        ]);

        $this->group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => false]);

        $this->app->make(DriverGroupSettings::class)->save([
            'group_id' => $this->group->id,
            'on_resolve' => true,
            'delay_min' => 0,
            'delay_max' => 0,
        ]);

        /* Every session answers, wherever the tests run. */
        $this->app->instance(SessionProbe::class, new class extends SessionProbe
        {
            public function check(TelegramAccount $account, int $seconds, callable $stopping): array
            {
                return ['status' => self::OK, 'error' => null];
            }
        });
    }

    public function test_the_button_queues_every_driver_found_in_telegram_once_with_the_account_that_found_them(): void
    {
        DB::table('telegram_drivers')->insert(['id' => 5, 'name' => 'ALI VALIEV']);
        $this->phone('+998901110001', 501, 1, driverId: 5);
        $this->phone('+998901110002', 502, 2);
        /* Not in Telegram: nothing to add. */
        $this->phone('+998901110003', null, 1);
        DB::table('telegram_driver_checks')->insert([
            'driver_name' => 'Vali Aliev',
            'telegram_resolved_phone_id' => TelegramResolvedPhone::query()->where('phone_normalized', '+998901110002')->value('id'),
            'checked_at' => now(),
        ]);

        $service = $this->app->make(DriverGroupService::class);

        $this->assertSame(2, $service->queueAll());
        $this->assertSame(0, $service->queueAll());

        $rows = TelegramDriverGroupAdd::query()->orderBy('id')->get();

        $this->assertSame(['+998901110001', '+998901110002'], $rows->pluck('phone')->all());
        $this->assertSame([1, 2], $rows->pluck('resolver_account_id')->all());
        $this->assertSame(['ALI VALIEV', 'Vali Aliev'], $rows->pluck('driver_name')->all());
        $this->assertSame([TelegramDriverGroupAdd::STATUS_PENDING], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame([TelegramDriverGroupAdd::SOURCE_ALL], $rows->pluck('source')->unique()->values()->all());
    }

    public function test_a_driver_checked_while_add_on_resolve_is_on_is_queued_the_name_matched_or_not(): void
    {
        $before = $this->phone('+998901110001', 501, 1);
        $matched = $this->phone('+998901110002', 502, 1);
        $notMatched = $this->phone('+998901110003', 503, 2);

        $since = now()->parse($this->app->make(DriverGroupSettings::class)->current()['on_resolve_since']);

        DB::table('telegram_driver_checks')->insert([
            ['driver_name' => 'Old', 'telegram_resolved_phone_id' => $before->id, 'status' => 'confirmed', 'checked_at' => $since->copy()->subHour()],
            ['driver_name' => 'Ok', 'telegram_resolved_phone_id' => $matched->id, 'status' => 'confirmed', 'checked_at' => $since->copy()->addMinute()],
            ['driver_name' => 'Other name', 'telegram_resolved_phone_id' => $notMatched->id, 'status' => 'not_confirmed', 'checked_at' => $since->copy()->addMinute()],
            ['driver_name' => 'Ok again', 'telegram_resolved_phone_id' => $matched->id, 'status' => 'confirmed', 'checked_at' => $since->copy()->addMinutes(2)],
        ]);

        $service = $this->app->make(DriverGroupService::class);

        $this->assertSame(2, $service->queueResolved());
        $this->assertSame(0, $service->queueResolved());
        $this->assertEqualsCanonicalizing([$matched->id, $notMatched->id], TelegramDriverGroupAdd::query()->pluck('resolved_phone_id')->all());
        $this->assertSame([TelegramDriverGroupAdd::SOURCE_RESOLVE], TelegramDriverGroupAdd::query()->pluck('source')->unique()->values()->all());

        /* Switched off: nobody checked from now on is queued. */
        $this->app->make(DriverGroupSettings::class)->save(['on_resolve' => false]);
        $late = $this->phone('+998901110004', 504, 1);
        DB::table('telegram_driver_checks')->insert(['telegram_resolved_phone_id' => $late->id, 'checked_at' => now()->addMinute()]);

        $this->assertSame(0, $service->queueResolved());
    }

    public function test_the_account_that_found_the_driver_adds_them_with_its_own_access_hash_and_each_try_ends_where_telegram_says(): void
    {
        $this->phone('+998901110001', 501, 1, hash: 111);
        $this->phone('+998901110002', 502, 1, hash: 222);
        $this->phone('+998901110003', 503, 1, hash: 333);

        $this->fakeTelegram(invite: function (int $account, int $user) {
            return match ($user) {
                501 => $this->added([501]),
                502 => ['missing_invitees' => [], 'updates' => ['_' => 'updates', 'updates' => []]],
                503 => throw RPCErrorException::make('USER_PRIVACY_RESTRICTED', 400, 'channels.inviteToChannel'),
            };
        });

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        $totals = $service->run(trigger: 'manual');

        $this->assertSame([1, 0, 1, 1], [$totals['invited'], $totals['already'], $totals['not_added'], $totals['failed']]);

        $rows = TelegramDriverGroupAdd::query()->orderBy('id')->get()->keyBy('telegram_user_id');

        $this->assertSame(TelegramDriverGroupAdd::STATUS_INVITED, $rows[501]->status);
        $this->assertSame(TelegramDriverGroupAdd::STATUS_NOT_ADDED, $rows[502]->status);
        $this->assertSame('NOT_IN_GROUP', $rows[502]->error);
        $this->assertSame(TelegramDriverGroupAdd::STATUS_FAILED, $rows[503]->status);
        $this->assertSame('USER_PRIVACY_RESTRICTED', $rows[503]->error);
        $this->assertSame([1], $rows->pluck('telegram_account_id')->unique()->values()->all());
        $this->assertFalse($rows[501]->re_resolved);

        /* By the access hash its own lookup got: nothing looked up again. */
        $invites = collect($this->calls)->where('method', 'inviteToChannel')->values();

        $this->assertSame(['_' => 'inputUser', 'user_id' => 501, 'access_hash' => 111], $invites[0]['args']['users'][0]);
        $this->assertCount(0, collect($this->calls)->where('method', 'resolvePhone'));
        $this->assertSame(-1000000000777, $this->group->fresh()->telegram_chat_id);
        $this->assertFalse((bool) TelegramAccountProcess::query()->where('telegram_account_id', 1)->value('is_busy'));
    }

    public function test_the_drivers_of_an_account_that_cannot_work_any_more_are_found_again_and_added_by_another(): void
    {
        $this->phone('+998901110001', 501, 1, hash: 111);
        TelegramAccount::query()->whereKey(1)->update(['is_authorized' => false]);

        $this->fakeTelegram(
            invite: fn (int $account, int $user) => $this->added([$user]),
            resolve: fn (int $account, string $phone) => ['users' => [['_' => 'user', 'id' => 501, 'access_hash' => 999]]],
        );

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        $totals = $service->run();

        $row = TelegramDriverGroupAdd::query()->firstOrFail();

        $this->assertSame(1, $totals['invited']);
        $this->assertSame(1, $totals['re_resolved']);
        $this->assertSame(2, $row->telegram_account_id);
        $this->assertSame(1, $row->resolver_account_id);
        $this->assertTrue($row->re_resolved);

        $resolve = collect($this->calls)->firstWhere('method', 'resolvePhone');
        $invite = collect($this->calls)->firstWhere('method', 'inviteToChannel');

        $this->assertSame([2, '+998901110001'], [$resolve['account'], $resolve['args']['phone']]);
        /* The other account's own access hash, not the first one's. */
        $this->assertSame(999, $invite['args']['users'][0]['access_hash']);
    }

    public function test_a_number_nobody_has_in_telegram_any_more_ends_the_try(): void
    {
        $this->phone('+998901110001', 501, null);

        $this->fakeTelegram(
            invite: fn (int $account, int $user) => $this->added([$user]),
            resolve: fn (int $account, string $phone) => throw RPCErrorException::make('PHONE_NOT_OCCUPIED', 400, 'contacts.resolvePhone'),
        );

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        $service->run();

        $row = TelegramDriverGroupAdd::query()->firstOrFail();

        $this->assertSame([TelegramDriverGroupAdd::STATUS_FAILED, 'NOT_REGISTERED'], [$row->status, $row->error]);
        $this->assertCount(0, collect($this->calls)->where('method', 'inviteToChannel'));
    }

    public function test_peer_flood_rests_the_account_and_its_drivers_wait_for_it(): void
    {
        $this->phone('+998901110001', 501, 1, hash: 111);
        $this->phone('+998901110002', 502, 1, hash: 222);

        $this->fakeTelegram(invite: fn (int $account, int $user) => throw RPCErrorException::make('PEER_FLOOD', 400, 'channels.inviteToChannel'));

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        $service->run();

        $this->assertSame(2, TelegramDriverGroupAdd::query()->where('status', TelegramDriverGroupAdd::STATUS_PENDING)->count());
        $this->assertCount(1, collect($this->calls)->where('method', 'inviteToChannel'));

        $meta = (array) TelegramAccountProcess::query()
            ->where('telegram_account_id', 1)
            ->where('process', ProcessEnum::DriverGroupAdd->value)
            ->value('meta');

        $this->assertSame('PEER_FLOOD', $meta['rest_reason']);
        $this->assertTrue(now()->addHours(23)->lt(now()->parse($meta['rest_until'])));

        /* Resting, not gone: account 2 does not take its drivers. */
        $this->calls->exchangeArray([]);
        $service->run();

        $this->assertCount(0, $this->calls);
        $this->assertSame('resting', $service->states([1], $this->group)[1]['state']);
    }

    public function test_an_account_adds_its_daily_share_and_the_rest_waits_for_tomorrow(): void
    {
        $this->app->make(DriverGroupSettings::class)->save(['per_account_day' => 2]);

        foreach ([501, 502, 503] as $i => $id) {
            $this->phone('+99890111000' . $i, $id, 1, hash: $id);
        }

        $this->fakeTelegram(invite: fn (int $account, int $user) => $this->added([$user]));

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        $service->run();

        $this->assertSame(2, TelegramDriverGroupAdd::query()->where('status', TelegramDriverGroupAdd::STATUS_INVITED)->count());
        $this->assertSame(1, TelegramDriverGroupAdd::query()->where('status', TelegramDriverGroupAdd::STATUS_PENDING)->count());
        $this->assertSame('capped', $service->states([1], $this->group)[1]['state']);
    }

    public function test_a_scheduled_run_with_nobody_who_may_add_now_leaves_the_page_as_it_was(): void
    {
        $this->phone('+998901110001', 501, 1, hash: 111);
        TelegramSetting::set(DriverGroupService::NOW_KEY, ['run_id' => 'old', 'finished_at' => '2026-11-05T10:00:00+05:00']);

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();

        TelegramAccountProcess::query()->create([
            'telegram_account_id' => 1,
            'process' => ProcessEnum::GroupInvite->value,
            'is_busy' => true,
            'busy_at' => now(),
        ]);

        $this->fakeTelegram(invite: fn (int $account, int $user) => $this->added([$user]));

        $totals = $service->run(trigger: 'schedule');

        $this->assertSame(0, $totals['accounts']);
        $this->assertSame('old', TelegramSetting::get(DriverGroupService::NOW_KEY)['run_id']);
    }

    public function test_another_group_picked_drops_who_waited_for_the_old_one_and_keeps_what_it_got(): void
    {
        $this->phone('+998901110001', 501, 1);
        $this->phone('+998901110002', 502, 1);

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        TelegramDriverGroupAdd::query()->where('telegram_user_id', 501)->update(['status' => TelegramDriverGroupAdd::STATUS_INVITED]);

        $other = TelegramInviteGroup::query()->create(['link' => 'drivers2', 'is_active' => true]);

        $this->actingAsDriverCheck()
            ->putJson(route('api.telegram.group-invites.drivers.settings'), [
                'group_id' => $other->id,
                'on_resolve' => true,
                'per_account_day' => 25,
                'delay_min' => 0,
                'delay_max' => 0,
            ])
            ->assertOk();

        $this->assertSame([501], TelegramDriverGroupAdd::query()->pluck('telegram_user_id')->all());
        $this->assertSame($other->id, $this->app->make(DriverGroupSettings::class)->current()['group_id']);
        /* The invites' own current group is not touched. */
        $this->assertFalse($this->group->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_the_page_counts_the_drivers_of_the_group_and_tells_how_each_account_stands(): void
    {
        $this->phone('+998901110001', 501, 1, hash: 111);
        $this->phone('+998901110002', 502, 1, hash: 222);
        $this->phone('+998901110003', 503, 2, hash: 333);
        $this->phone('+998901110004', 504, 2, hash: 444);

        $service = $this->app->make(DriverGroupService::class);
        $service->queueAll();
        TelegramDriverGroupAdd::query()->where('telegram_user_id', 504)->delete();
        TelegramDriverGroupAdd::query()->where('telegram_user_id', 501)->update([
            'status' => TelegramDriverGroupAdd::STATUS_INVITED,
            'telegram_account_id' => 1,
            'attempted_at' => now(),
        ]);
        TelegramAccount::query()->whereKey(2)->update(['is_authorized' => false]);

        $this->withoutMiddleware();

        $json = $this->getJson(route('api.telegram.group-invites.drivers'))->assertOk()->json();

        $this->assertSame(4, $json['counts']['found']);
        $this->assertSame(3, $json['counts']['queued']);
        $this->assertSame(1, $json['counts']['not_queued']);
        $this->assertSame([1, 2], [$json['counts']['invited'], $json['counts']['pending']]);
        $this->assertSame(['tried' => 1, 'added' => 1], $json['today']);

        $accounts = collect($json['accounts'])->keyBy('id');

        $this->assertSame([1, 1, 1], [$accounts[1]['waiting'], $accounts[1]['tried_today'], $accounts[1]['added_today']]);
        $this->assertSame('ready', $accounts[1]['state']);
        $this->assertSame(['gone', 'logged_out'], [$accounts[2]['state'], $accounts[2]['reason']]);

        $rows = $this->getJson(route('api.telegram.group-invites.drivers.rows', ['status' => 'pending']))->assertOk()->json();

        $this->assertSame(['+998901110002', '+998901110003'], array_column($rows['data'], 'phone'));

        $found = $this->getJson(route('api.telegram.group-invites.drivers.rows', ['q' => '0003']))->assertOk()->json();

        $this->assertSame(['+998901110003'], array_column($found['data'], 'phone'));
    }

    public function test_stop_holds_the_runs_every_minute_until_continue(): void
    {
        Queue::fake();
        $this->phone('+998901110001', 501, 1, hash: 111);
        $this->fakeTelegram(invite: fn (int $account, int $user) => $this->added([$user]));

        $service = $this->app->make(DriverGroupService::class);
        $settings = $this->app->make(DriverGroupSettings::class);
        $service->queueAll();

        /* Nothing runs: the stop still holds the scheduler. */
        $this->actingAsDriverCheck()->postJson(route('api.telegram.group-invites.drivers.stop'))->assertOk();

        $this->assertTrue($settings->paused());
        $this->assertFalse($settings->active());
        $this->assertTrue($this->getJson(route('api.telegram.group-invites.drivers'))->json('paused'));

        $this->artisan('telegram:driver-group-add', ['--trigger' => 'schedule'])->assertSuccessful();
        /* A run started some other way while stopped ends at once. */
        $service->run(trigger: 'manual');

        $this->assertCount(0, $this->calls);
        $this->assertSame(1, TelegramDriverGroupAdd::query()->where('status', TelegramDriverGroupAdd::STATUS_PENDING)->count());
        /* The account it took for the first turn is not left busy for the invites. */
        $this->assertFalse((bool) TelegramAccountProcess::query()->where('telegram_account_id', 1)->where('process', ProcessEnum::DriverGroupAdd->value)->value('is_busy'));

        /* The settings saved meanwhile keep the stop. */
        $this->putJson(route('api.telegram.group-invites.drivers.settings'), [
            'group_id' => $this->group->id,
            'on_resolve' => true,
            'per_account_day' => 25,
            'delay_min' => 0,
            'delay_max' => 0,
        ])->assertOk();

        $this->assertTrue($settings->paused());

        /* "Continue" lifts it and starts the run. */
        $this->postJson(route('api.telegram.group-invites.drivers.run'))->assertOk();

        $this->assertFalse($settings->paused());
        $this->assertTrue($settings->active());
        Queue::assertPushed(StartDriverGroupAddJob::class);
    }

    public function test_the_accounts_report_tells_who_holds_each_account_and_why_the_invites_pass_it_by(): void
    {
        config(['services.telegram.report_token' => str_repeat('t', 40)]);

        $this->phone('+998901110001', 501, 1, hash: 111);
        $this->phone('+998901110002', 502, 2, hash: 222);
        $this->app->make(DriverGroupService::class)->queueAll();

        DB::table('telegram_accounts')->insert(['id' => 3, 'phone' => '+998900000003', 'telegram_user_id' => 9003, 'session_path' => '/sessions/3', 'is_authorized' => true]);

        TelegramAccountProcess::query()->create([
            'telegram_account_id' => 1,
            'process' => ProcessEnum::ContactSync->value,
            'is_busy' => true,
            'busy_at' => now()->subHours(8),
        ]);
        TelegramAccountProcess::query()->create([
            'telegram_account_id' => 2,
            'process' => ProcessEnum::GroupInvite->value,
            'meta' => ['rest_until' => now()->addHours(3)->toIso8601String(), 'rest_kind' => 'wait', 'rest_reason' => 'FLOOD_WAIT_X'],
        ]);

        $this->getJson(route('telegram.report.accounts'))->assertNotFound();

        $json = $this->getJson(route('telegram.report.accounts'), ['Authorization' => 'Bearer ' . str_repeat('t', 40)])
            ->assertOk()
            ->json();

        $accounts = collect($json['accounts'])->keyBy('id');

        $this->assertSame(['busy:contact_sync'], $accounts[1]['invite']['blockers']);
        $this->assertSame(480, $accounts[1]['processes']['contact_sync']['busy_minutes']);
        $this->assertSame(['resting:wait'], $accounts[2]['invite']['blockers']);
        $this->assertTrue($accounts[3]['invite']['free_now']);

        $this->assertSame(1, $json['summary']['invite']['free_now']);
        $this->assertSame(['busy:contact_sync' => 1, 'resting:wait' => 1], $json['summary']['invite']['stopped_by']);
        $this->assertSame(['rows' => 1, 'busy' => 1, 'busy_stale' => 1, 'resting' => 0, 'unavailable' => 0, 'failing' => 0], $json['summary']['processes']['contact_sync']);

        /* The drivers wait for the account that found them, as the page sees it. */
        $this->assertSame(['state' => 'ready', 'until' => null, 'reason' => null], $accounts[2]['drivers']['state']);
        $this->assertSame(1, $accounts[1]['drivers']['waiting']);
        $this->assertSame(2, $json['summary']['drivers']['waiting']);
        $this->assertStringNotContainsString('+99890', json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function phone(string $phone, ?int $telegramId, ?int $accountId, ?int $driverId = null, ?int $hash = null): TelegramResolvedPhone
    {
        return TelegramResolvedPhone::query()->create([
            'phone_normalized' => $phone,
            'telegram_user_id' => $telegramId,
            'telegram_first_name' => 'Driver',
            'telegram_raw' => $telegramId !== null && $hash !== null
                ? ['_' => 'contacts.resolvedPeer', 'users' => [['_' => 'user', 'id' => $telegramId, 'access_hash' => $hash]]]
                : null,
            'telegram_account_id' => $accountId,
            'driver_id' => $driverId,
            'resolved_at' => now(),
        ]);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function added(array $ids): array
    {
        return [
            '_' => 'messages.invitedUsers',
            'updates' => [
                '_' => 'updates',
                'updates' => [[
                    '_' => 'updateNewChannelMessage',
                    'message' => ['_' => 'messageService', 'action' => ['_' => 'messageActionChatAddUser', 'users' => $ids]],
                ]],
            ],
            'missing_invitees' => [],
        ];
    }

    /**
     * A session stand-in per account: joining always works, the invite and
     * the phone lookup answer as the test says, nobody is in the group
     * unless the invite's answer names them.
     *
     * @param callable(int, int): mixed         $invite  account id, the driver's telegram id
     * @param callable(int, string): mixed|null $resolve account id, the phone
     */
    private function fakeTelegram(callable $invite, ?callable $resolve = null): void
    {
        $this->calls = new ArrayObject;
        $calls = $this->calls;

        $make = function (TelegramAccount $account) use ($invite, $resolve, $calls): API {
            $api = (new ReflectionClass(API::class))->newInstanceWithoutConstructor();
            $id = $account->id;

            $api->channels = new class($id, $invite, $calls)
            {
                public function __construct(private int $account, private $invite, private ArrayObject $calls) {}

                public function joinChannel(mixed ...$args): array
                {
                    $this->calls[] = ['account' => $this->account, 'method' => 'joinChannel', 'args' => $args];

                    return ['chats' => [['_' => 'channel', 'id' => 777, 'title' => 'Drivers']]];
                }

                public function inviteToChannel(mixed ...$args): mixed
                {
                    $this->calls[] = ['account' => $this->account, 'method' => 'inviteToChannel', 'args' => $args];
                    $user = $args['users'][0];

                    return ($this->invite)($this->account, (int) (is_array($user) ? $user['user_id'] : $user));
                }

                public function getParticipant(mixed ...$args): never
                {
                    $this->calls[] = ['account' => $this->account, 'method' => 'getParticipant', 'args' => $args];

                    throw RPCErrorException::make('USER_NOT_PARTICIPANT', 400, 'channels.getParticipant');
                }
            };

            $api->contacts = new class($id, $resolve, $calls)
            {
                public function __construct(private int $account, private $resolve, private ArrayObject $calls) {}

                public function resolvePhone(mixed ...$args): mixed
                {
                    $this->calls[] = ['account' => $this->account, 'method' => 'resolvePhone', 'args' => $args];

                    return ($this->resolve)($this->account, (string) $args['phone']);
                }
            };

            return $api;
        };

        $madeline = Mockery::mock(MadelineService::class);
        $madeline->shouldReceive('for')->andReturnUsing($make);
        $this->app->instance(MadelineService::class, $madeline);
    }

    private function actingAsDriverCheck(): self
    {
        $this->withoutMiddleware();

        return $this;
    }
}
