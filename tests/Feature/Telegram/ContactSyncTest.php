<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\ContactSyncService;
use App\Http\Controllers\Api\Telegram\ContactSyncController;
use App\Jobs\Telegram\StartContactSyncJob;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCError\FloodWaitError;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The guarantee the contact sync is built on: a Telegram user is stored once
 * and is saved by one account only, however many groups and accounts see them.
 */
final class ContactSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->timestamps();
        });

        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        /* The group types and Join group's columns: the contact sync reads only its own groups. */
        (include database_path('migrations/2026_10_20_100000_create_telegram_group_join_tables.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001'],
            ['id' => 2, 'phone' => '+998900000002'],
        ]);
    }

    private function invokeService(string $method, array $args)
    {
        $service = $this->app->make(ContactSyncService::class);

        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invoke($service, ...$args);
    }

    public function test_a_user_in_two_groups_is_stored_once(): void
    {
        $first = TelegramScrapeGroup::create(['link' => 'one']);
        $second = TelegramScrapeGroup::create(['link' => 'two']);

        $member = ['id' => 555, 'first_name' => 'Ali', 'username' => 'ali', 'type' => 'user'];

        $this->invokeService('storeMembers', [$first, [$member], []]);
        $this->invokeService('storeMembers', [$second, [$member], []]);
        $this->invokeService('storeMembers', [$second, [$member], []]);

        $this->assertSame(1, TelegramScrapedUser::query()->count());
        $this->assertSame(2, DB::table('telegram_scrape_group_user')->count());
    }

    public function test_our_own_accounts_and_nobody_else_are_skipped(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'one']);

        $this->invokeService('storeMembers', [$group, [
            ['id' => 1, 'first_name' => 'Own'],
            ['id' => 2, 'first_name' => 'Other', 'type' => 'bot'],
        ], [1]]);

        $this->assertSame([2], TelegramScrapedUser::query()->pluck('telegram_user_id')->all());
        $this->assertTrue(TelegramScrapedUser::first()->is_bot);
    }

    public function test_only_one_account_can_claim_a_person(): void
    {
        $user = TelegramScrapedUser::create(['telegram_user_id' => 777]);

        $a = \App\Models\Telegram\TelegramAccount::query()->find(1) ?? $this->account(1);
        $b = $this->account(2);

        $this->assertTrue($this->invokeService('claim', [$a, $user, false]));
        $this->assertFalse($this->invokeService('claim', [$b, $user, false]));

        $this->assertSame(1, TelegramAccountContact::query()->count());
        $this->assertSame(1, TelegramAccountContact::query()->value('telegram_account_id'));
    }

    public function test_a_person_already_in_contacts_is_marked_not_added(): void
    {
        $user = TelegramScrapedUser::create(['telegram_user_id' => 888]);

        $this->assertTrue($this->invokeService('claim', [$this->account(1), $user, true]));

        $this->assertSame(
            TelegramAccountContact::STATUS_SKIPPED,
            TelegramAccountContact::query()->value('status'),
        );
    }

    public function test_members_are_read_page_after_page(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'drivers', 'last_error' => 'The operation was cancelled']);

        $api = $this->fakeApi([
            [
                'count' => 3,
                'participants' => [['_' => 'channelParticipant', 'user_id' => 101, 'inviter_id' => 999], ['_' => 'channelParticipant', 'user_id' => 102]],
                'users' => [
                    ['id' => 101, 'first_name' => 'Ali'],
                    ['id' => 102, 'deleted' => true],
                    ['id' => 999, 'first_name' => 'Inviter, not a member here'],
                ],
            ],
            [
                'count' => 3,
                'participants' => [['_' => 'channelParticipant', 'user_id' => 103]],
                'users' => [['id' => 103, 'first_name' => 'Bot', 'bot' => true]],
            ],
        ]);

        $members = $this->invokeService('readMembers', [$api, $group, $this->account(1), ['delay_min' => 0, 'delay_max' => 0]]);

        $this->assertSame([101, 103], array_column($members, 'id'), 'a deleted account is left out, an inviter is not a member');
        $this->assertSame([0, 2], $api->channels->offsets);
        $this->assertSame(0, $api->channels->joins);
        $this->assertSame(3, $group->fresh()->members_count);
        $this->assertNull($group->fresh()->last_error);
    }

    public function test_a_public_group_listing_nobody_is_joined_and_read_again(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $api = $this->fakeApi([
            ['count' => 0, 'participants' => [], 'users' => []],
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 101]], 'users' => [['id' => 101]]],
        ]);

        $members = $this->invokeService('readMembers', [$api, $group, $this->account(1), ['delay_min' => 0, 'delay_max' => 0]]);

        $this->assertSame([101], array_column($members, 'id'));
        $this->assertSame(1, $api->channels->joins);
    }

    public function test_a_flood_wait_is_thrown_at_once_not_taken_for_not_a_member(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $api = $this->fakeApi([new FloodWaitError('FLOOD_WAIT_120', 120, 420, 'channels.getParticipants')]);

        try {
            $this->invokeService('readMembers', [$api, $group, $this->account(1), ['delay_min' => 0, 'delay_max' => 0]]);

            $this->fail('the flood wait was swallowed');
        } catch (FloodWaitError $e) {
            $this->assertSame('FLOOD_WAIT_120', $e->rpc);
        }

        $this->assertSame(0, $api->channels->joins, 'no join, no second read');
    }

    public function test_people_with_a_username_are_nobodys_to_save_when_the_setting_says_so(): void
    {
        /* Who an account holds: somebody held is not for the sync either. */
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $this->invokeService('storeMembers', [$group, [['id' => 501, 'first_name' => 'Ali', 'username' => 'ali']], []]);

        $groups = TelegramScrapeGroup::query()->get();

        $this->assertFalse($this->invokeService('hasUnclaimed', [$groups, true]), 'reached by the link: left in the database');
        $this->assertTrue($this->invokeService('hasUnclaimed', [$groups, false]));

        $this->invokeService('storeMembers', [$group, [['id' => 502, 'first_name' => 'Vali']], []]);

        $this->assertTrue($this->invokeService('hasUnclaimed', [$groups, true]), 'no @username: a contact is the only way');
    }

    public function test_the_people_new_to_the_database_are_counted(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'one']);

        $this->assertSame(2, $this->invokeService('storeMembers', [$group, [['id' => 1], ['id' => 2]], []]));
        $this->assertSame(1, $this->invokeService('storeMembers', [$group, [['id' => 2], ['id' => 3]], []]), 'one of them was known');
    }

    public function test_groups_are_read_into_the_database_two_an_account_whatever_the_limit(): void
    {
        $queue = collect([
            TelegramScrapeGroup::create(['link' => 'one']),
            TelegramScrapeGroup::create(['link' => 'two']),
            TelegramScrapeGroup::create(['link' => 'three']),
        ]);

        $api = $this->fakeApi([
            [
                'count' => 2,
                'participants' => [['_' => 'channelParticipant', 'user_id' => 101], ['_' => 'channelParticipant', 'user_id' => 102]],
                'users' => [['id' => 101, 'first_name' => 'Ali'], ['id' => 102, 'first_name' => 'Vali']],
            ],
            [
                'count' => 2,
                'participants' => [['_' => 'channelParticipant', 'user_id' => 102], ['_' => 'channelParticipant', 'user_id' => 103]],
                'users' => [['id' => 102, 'first_name' => 'Vali'], ['id' => 103, 'first_name' => 'Soli']],
            ],
        ]);

        $result = $this->invokeService('collect', [
            $api,
            $this->account(1),
            $queue,
            ['delay_min' => 0, 'delay_max' => 0],
            static function (string $line): void {},
        ]);

        $this->assertSame(['members' => 4, 'new' => 3], $result, 'a person in both groups is new once');
        $this->assertSame(['three'], $queue->pluck('link')->all(), 'the third waits for the next account');
        $this->assertSame(3, TelegramScrapedUser::query()->count());
        $this->assertSame(0, TelegramAccountContact::query()->count(), 'reading saves nobody into contacts');
        $this->assertNotNull(TelegramScrapeGroup::query()->where('link', 'one')->value('last_synced_at'));
    }

    public function test_an_account_reads_only_the_groups_it_can_still_save_from(): void
    {
        /* Who an account holds: somebody held is not for the sync either. And the name a contact is saved under. */
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();
        (include database_path('migrations/2026_10_16_100000_add_contact_name_to_telegram_account_contacts_table.php'))->up();

        $done =TelegramScrapeGroup::create(['link' => 'done', 'last_synced_at' => now()->subDay()]);
        $fresh = TelegramScrapeGroup::create(['link' => 'fresh']);

        /* Everybody of the group read yesterday has an owner already. */
        $this->invokeService('storeMembers', [$done, [['id' => 701, 'first_name' => 'Ali']], []]);
        $this->invokeService('claim', [$this->account(2), TelegramScrapedUser::query()->firstOrFail(), false]);

        $api = $this->fakeApi([
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 801]], 'users' => [['id' => 801, 'first_name' => 'Vali']]],
        ]);

        $config = ['daily_limit' => 5, 'skip_usernames' => false, 'delay_min' => 0, 'delay_max' => 0, 'nick_base' => ''];
        $say = static function (string $line): void {};

        $result = $this->invokeService('runAccount', [$api, $this->account(1), collect([$done, $fresh]), $config, $say]);

        $this->assertSame([0], $api->channels->offsets, 'only the group never read was read');
        $this->assertSame([801], $api->contacts->added);
        $this->assertSame(1, $result['added']);

        /* Its contacts for today are added: it reads nothing more. */
        $full = $this->fakeApi([]);

        $this->invokeService('runAccount', [$full, $this->account(1), collect([$fresh]), ['daily_limit' => 1] + $config, $say]);

        $this->assertSame([], $full->channels->offsets);
    }

    public function test_the_fetch_button_starts_a_members_only_read_of_the_group_once(): void
    {
        Queue::fake();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $controller = $this->app->make(ContactSyncController::class);

        $this->assertSame(200, $controller->fetchMembers($group)->getStatusCode());

        Queue::assertPushedOn('telegram', StartContactSyncJob::class, fn (StartContactSyncJob $job) => $job->groupId === $group->id && $job->membersOnly);

        $this->assertSame(409, $controller->fetchMembers($group)->getStatusCode(), 'pressed again before the process starts');

        Queue::assertPushed(StartContactSyncJob::class, 1);
    }

    public function test_a_stop_asked_from_the_panel_ends_the_account_before_the_next_contact(): void
    {
        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_16_100000_add_contact_name_to_telegram_account_contacts_table.php'))->up();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $service = $this->app->make(ContactSyncService::class);

        foreach (['runId' => 'run-1', 'stoppable' => true] as $property => $value) {
            (new \ReflectionProperty($service, $property))->setValue($service, $value);
        }

        ContactSyncService::requestStop('run-1');

        $api = $this->fakeApi([
            ['count' => 2, 'participants' => [['_' => 'channelParticipant', 'user_id' => 901], ['_' => 'channelParticipant', 'user_id' => 902]], 'users' => [['id' => 901, 'first_name' => 'Ali'], ['id' => 902, 'first_name' => 'Vali']]],
        ]);

        $config = ['daily_limit' => 5, 'skip_usernames' => false, 'delay_min' => 0, 'delay_max' => 0, 'nick_base' => ''];

        $result = (new ReflectionMethod($service, 'runAccount'))->invoke($service, $api, $this->account(1), collect([$group]), $config, static function (string $line): void {});

        $this->assertSame(0, $result['added']);
        $this->assertSame([], $api->contacts->added);
        $this->assertSame(0, TelegramAccountContact::query()->count(), 'nobody is left taken');

        /* A stop for another run is not this one's. */
        ContactSyncService::requestStop('run-0');
        (new \ReflectionProperty($service, 'stopping'))->setValue($service, false);
        (new \ReflectionProperty($service, 'stopLookedAt'))->setValue($service, 0);

        $this->assertFalse((new ReflectionMethod($service, 'stopping'))->invoke($service));
    }

    public function test_the_stop_button_asks_the_run_and_queues_the_kill_of_a_stuck_one(): void
    {
        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();

        Queue::fake();

        $controller = $this->app->make(ContactSyncController::class);

        $this->assertSame(409, $controller->stop()->getStatusCode(), 'nothing runs');

        $runId = (string) \Illuminate\Support\Str::uuid();
        \App\Models\Telegram\TelegramContactSyncLog::query()->create(['run_id' => $runId, 'event' => 'started', 'message' => 'Sync started', 'context' => ['trigger' => 'schedule']]);

        $this->assertTrue(\App\Telegram\TelegramProcessLock::acquire(\App\Console\Commands\Telegram\TelegramSyncContactsCommand::LOCK_NAME));

        try {
            $this->assertSame(200, $controller->stop()->getStatusCode());
        } finally {
            \App\Telegram\TelegramProcessLock::release(\App\Console\Commands\Telegram\TelegramSyncContactsCommand::LOCK_NAME);
        }

        /* A run from before the button writes no id: whichever runs is asked, and killed if it does not answer. */
        $this->assertSame('*', \App\Models\Telegram\TelegramSetting::get(ContactSyncService::STOP_KEY));
        Queue::assertPushedOn('telegram', \App\Jobs\Telegram\StopContactSyncJob::class, fn ($job) => $job->runId === null && $job->delay !== null);
        $this->assertTrue(\App\Models\Telegram\TelegramContactSyncLog::query()->where('run_id', $runId)->where('event', 'stop')->exists());

        /* Ended by the button: "stopped", not "finished" nor "interrupted". */
        \App\Models\Telegram\TelegramContactSyncLog::query()->create(['run_id' => $runId, 'event' => 'finished', 'message' => 'Stopped from the panel', 'context' => ['accounts' => 1, 'stopped' => true]]);

        $runs = $this->app->make(\App\Http\Controllers\Api\Telegram\ContactSyncRunController::class)->index(request())->getData(true);

        $this->assertSame('stopped', $runs['data'][0]['status']);
    }

    public function test_an_accounts_turn_in_its_worker_saves_contacts_and_says_what_is_left_to_read(): void
    {
        $this->workerTables();

        $mine = TelegramScrapeGroup::create(['link' => 'mine']);
        $other = TelegramScrapeGroup::create(['link' => 'other']);
        $third = TelegramScrapeGroup::create(['link' => 'third']);

        $api = $this->fakeApi([
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 1001]], 'users' => [['id' => 1001, 'first_name' => 'Ali']]],
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 1002]], 'users' => [['id' => 1002, 'first_name' => 'Vali']]],
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 1003]], 'users' => [['id' => 1003, 'first_name' => 'Soli']]],
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 1004]], 'users' => [['id' => 1004, 'first_name' => 'Gani']]],
        ]);

        $madeline = \Mockery::mock(\App\Application\Telegram\Services\MadelineService::class);
        $madeline->shouldReceive('for')->andReturn($api);
        $this->app->instance(\App\Application\Telegram\Services\MadelineService::class, $madeline);

        $lines = [];

        $result = $this->app->make(ContactSyncService::class)->work(
            1,
            'run-1',
            null,
            true,
            [$other->id, $third->id],
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        /* The contacts read all three groups; nothing is left to read into the database. */
        $this->assertSame(3, $result['added']);
        $this->assertEqualsCanonicalizing([$mine->id, $other->id, $third->id], $result['read']);
        $this->assertSame([], $result['queue']);
        $this->assertContains('  added 1001', $lines, 'a line a contact: the run knows the worker is alive');
        $this->assertSame(3, \App\Models\Telegram\TelegramContactSyncLog::query()->where('run_id', 'run-1')->where('event', 'added')->count(), 'logged into the run');

        /* What the run reads back from the worker's last line. */
        $output = "Account #1\n  added 1001\n" . ContactSyncService::RESULT_MARK . json_encode($result) . "\n";

        $this->assertSame($result, ContactSyncService::workerResult($output));
        $this->assertNull(ContactSyncService::workerResult("Account #1\n  added 1001\n"), 'killed before it wrote what it did');
    }

    public function test_a_worker_silent_too_long_puts_its_account_on_hold_and_frees_its_person(): void
    {
        $this->workerTables();

        $person = TelegramScrapedUser::create(['telegram_user_id' => 1101, 'first_name' => 'Ali']);
        $other = TelegramScrapedUser::create(['telegram_user_id' => 1102, 'first_name' => 'Vali']);

        $service = $this->app->make(ContactSyncService::class);

        /* Taken by the hung worker, and one saved by another account. */
        $this->invokeService('claim', [$this->account(1), $person, false]);
        $this->invokeService('claim', [$this->account(2), $other, false]);

        (new \ReflectionProperty($service, 'runId'))->setValue($service, 'run-1');
        (new ReflectionMethod($service, 'stuck'))->invoke($service, $this->account(1), static function (string $line): void {});

        $state = \App\Models\Telegram\TelegramAccountProcess::query()->where('telegram_account_id', 1)->where('process', 'contact_sync')->sole();

        $this->assertFalse((bool) $state->is_available, 'on hold until enabled in the panel');
        $this->assertSame(ContactSyncService::HUNG, $state->disabled_reason);
        $this->assertSame([1102], TelegramAccountContact::query()->with('user')->get()->map(fn ($row) => (int) $row->user->telegram_user_id)->all(), 'the person it was saving is nobody\'s again');
        $this->assertTrue(\App\Models\Telegram\TelegramContactSyncLog::query()->where('run_id', 'run-1')->where('event', 'disabled')->where('telegram_account_id', 1)->exists());
    }

    public function test_an_account_adds_only_people_of_the_pages_it_reads_and_stops_reading_when_its_day_is_full(): void
    {
        $this->workerTables();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        /* From an earlier read: gone from the group since, the session would not know them. */
        $this->invokeService('storeMembers', [$group, [['id' => 600, 'first_name' => 'Gone']], []]);

        $api = $this->fakeApi([
            ['count' => 3, 'participants' => [['_' => 'channelParticipant', 'user_id' => 601], ['_' => 'channelParticipant', 'user_id' => 602]], 'users' => [['id' => 601, 'first_name' => 'Ali'], ['id' => 602, 'first_name' => 'Vali']]],
            ['count' => 3, 'participants' => [['_' => 'channelParticipant', 'user_id' => 603]], 'users' => [['id' => 603, 'first_name' => 'Soli']]],
        ]);

        $config = ['daily_limit' => 2, 'skip_usernames' => false, 'delay_min' => 0, 'delay_max' => 0, 'nick_base' => ''];

        $result = $this->invokeService('runAccount', [$api, $this->account(1), collect([$group]), $config, static function (string $line): void {}]);

        $this->assertSame([601, 602], $api->contacts->added, 'the newest members first, nobody it has not just read');
        $this->assertSame(2, $result['added']);
        $this->assertSame([0], $api->channels->offsets, 'its day is full: the rest of the list is not read');
        $this->assertNull($group->fresh()->last_synced_at, 'read in part: the group is read whole by collect()');
        $this->assertFalse(TelegramAccountContact::query()->whereHas('user', fn ($q) => $q->where('telegram_user_id', 600))->exists(), 'the one who left is not tried');
    }

    public function test_once_its_own_groups_have_nobody_left_an_account_takes_the_people_of_the_other_groups(): void
    {
        $this->workerTables();

        $own = TelegramScrapeGroup::create(['link' => 'mine', 'last_synced_at' => now()]);
        $other = TelegramScrapeGroup::create(['link' => 'theirs', 'type' => TelegramScrapeGroup::TYPE_DATABASE]);
        $empty = TelegramScrapeGroup::create(['link' => 'nobody', 'type' => TelegramScrapeGroup::TYPE_DATABASE]);

        /* Its own group: everybody has an owner. Join group's group: two people wait. */
        $this->invokeService('storeMembers', [$own, [['id' => 1401, 'first_name' => 'Done']], []]);
        $this->invokeService('claim', [$this->account(2), TelegramScrapedUser::query()->where('telegram_user_id', 1401)->sole(), false]);
        $this->invokeService('storeMembers', [$other, [['id' => 1402, 'first_name' => 'Ali'], ['id' => 1403, 'first_name' => 'Vali']], []]);

        $this->assertTrue($this->invokeService('othersWait', [false]));

        $api = $this->fakeApi([
            ['count' => 2, 'participants' => [['_' => 'channelParticipant', 'user_id' => 1402], ['_' => 'channelParticipant', 'user_id' => 1403]], 'users' => [['id' => 1402, 'first_name' => 'Ali'], ['id' => 1403, 'first_name' => 'Vali']]],
        ]);

        $config = ['daily_limit' => 5, 'skip_usernames' => false, 'delay_min' => 0, 'delay_max' => 0, 'nick_base' => ''];

        $result = $this->invokeService('runAccount', [$api, $this->account(1), collect([$own->fresh()]), $config, static function (string $line): void {}]);

        $this->assertSame([1402, 1403], $api->contacts->added, 'the other group\'s people, after its own');
        $this->assertSame(2, $result['added']);
        $this->assertSame([0], $api->channels->offsets, 'its own group with nobody left is not read; the empty other one neither');
        $this->assertNull($other->fresh()->last_error);
    }

    public function test_the_last_page_is_read_to_its_end_and_the_group_marked_read(): void
    {
        $this->workerTables();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $api = $this->fakeApi([
            ['count' => 1, 'participants' => [['_' => 'channelParticipant', 'user_id' => 701]], 'users' => [['id' => 701, 'first_name' => 'Ali']]],
        ]);

        $config = ['daily_limit' => 1, 'skip_usernames' => false, 'delay_min' => 0, 'delay_max' => 0, 'nick_base' => ''];

        $this->invokeService('runAccount', [$api, $this->account(1), collect([$group]), $config, static function (string $line): void {}]);

        $this->assertSame([701], $api->contacts->added);
        $this->assertNotNull($group->fresh()->last_synced_at, 'the day filled on the last page: the list was whole');
        $this->assertSame(1, $group->fresh()->members_count);
    }

    public function test_who_was_not_on_the_latest_read_of_a_group_does_not_wait_for_an_account(): void
    {
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $this->travelTo(now()->subDays(3));
        $this->invokeService('storeMembers', [$group, [['id' => 801, 'first_name' => 'Gone']], []]);
        $this->travelBack();

        $group->update(['last_synced_at' => now()]);

        $this->assertFalse($this->invokeService('hasUnclaimed', [collect([$group->fresh()]), false]), 'left the group: nobody to wait for');

        $this->invokeService('storeMembers', [$group, [['id' => 801, 'first_name' => 'Back']], []]);

        $this->assertTrue($this->invokeService('hasUnclaimed', [collect([$group->fresh()]), false]), 'on the list again');
        $this->assertSame(1, DB::table('telegram_scrape_group_user')->count());
    }

    public function test_a_read_that_shows_no_phone_keeps_the_phone_known(): void
    {
        $group = TelegramScrapeGroup::create(['link' => 'drivers']);

        $this->invokeService('storeMembers', [$group, [['id' => 901, 'first_name' => 'Ali', 'phone' => '998901112233']], []]);
        $this->invokeService('storeMembers', [$group, [['id' => 901, 'first_name' => 'Alijon']], []]);

        $user = TelegramScrapedUser::query()->sole();

        $this->assertSame('998901112233', $user->phone);
        $this->assertSame('Alijon', $user->first_name);
    }

    public function test_a_flood_wait_is_a_rest_that_ends_by_itself_and_a_dead_session_is_not(): void
    {
        $this->workerTables();

        /* A dead session is marked on the account too. */
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->boolean('is_authorized')->default(true);
            $table->string('status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
        });

        $service = $this->app->make(ContactSyncService::class);
        $hold = new ReflectionMethod($service, 'hold');

        $hold->invoke($service, $this->account(1), new FloodWaitError('FLOOD_WAIT_120', 120, 420, 'contacts.addContact'));
        $hold->invoke($service, $this->account(2), new \RuntimeException('AUTH_KEY_UNREGISTERED'));

        $flooded = $this->state(1);
        $dead = $this->state(2);

        $this->assertFalse((bool) $flooded->is_available);
        $this->assertEqualsWithDelta(now()->addSeconds(120 + 300)->getTimestamp(), ContactSyncService::restEnd($flooded)->getTimestamp(), 5, 'the wait Telegram asked for and a margin');
        $this->assertNull(ContactSyncService::restEnd($dead), 'held until somebody enables it');

        (new ReflectionMethod($service, 'endRests'))->invoke($service);
        $this->assertFalse((bool) $this->state(1)->is_available, 'not over yet');

        $this->travel(10)->minutes();

        (new ReflectionMethod($service, 'endRests'))->invoke($service);

        $this->assertTrue((bool) $this->state(1)->is_available, 'rested: back in the sync');
        $this->assertFalse((bool) $this->state(2)->is_available);
        $this->assertTrue(\App\Models\Telegram\TelegramContactSyncLog::query()->where('event', 'back')->exists());
    }

    public function test_a_hold_from_before_the_rests_ends_by_its_reason(): void
    {
        $this->workerTables();

        foreach ([1 => ContactSyncService::FAILURES, 2 => 'PHONE_NUMBER_BANNED'] as $id => $reason) {
            \App\Models\Telegram\TelegramAccountProcess::query()->create([
                'telegram_account_id' => $id,
                'process' => 'contact_sync',
                'is_available' => false,
                'disabled_at' => now()->subHours(ContactSyncService::REST_HOURS + 1),
                'disabled_reason' => $reason,
            ]);
        }

        $service = $this->app->make(ContactSyncService::class);
        (new ReflectionMethod($service, 'endRests'))->invoke($service);

        $this->assertTrue((bool) $this->state(1)->is_available, 'a streak of refusals a day ago: back');
        $this->assertFalse((bool) $this->state(2)->is_available, 'a banned number: not by itself');
    }

    public function test_a_session_that_hangs_rests_once_and_is_held_the_second_time(): void
    {
        $this->workerTables();

        $service = $this->app->make(ContactSyncService::class);
        $stuck = new ReflectionMethod($service, 'stuck');
        $say = static function (string $line): void {};

        $stuck->invoke($service, $this->account(1), $say);
        $this->assertNotNull(ContactSyncService::restEnd($this->state(1)), 'the first time: a rest');

        $this->travel(ContactSyncService::REST_HOURS + 1)->hours();
        (new ReflectionMethod($service, 'endRests'))->invoke($service);
        $this->assertTrue((bool) $this->state(1)->is_available);

        $stuck->invoke($service, $this->account(1), $say);
        $this->assertFalse((bool) $this->state(1)->is_available);
        $this->assertNull(ContactSyncService::restEnd($this->state(1)), 'again within days: held until enabled');
    }

    public function test_a_run_frees_the_accounts_a_process_that_died_left_busy(): void
    {
        $this->workerTables();

        \App\Models\Telegram\TelegramAccountProcess::query()->create(['telegram_account_id' => 1, 'process' => 'contact_sync', 'is_busy' => true, 'busy_at' => now()->subDay()]);
        \App\Models\Telegram\TelegramAccountProcess::query()->create(['telegram_account_id' => 2, 'process' => 'group_invite', 'is_busy' => true, 'busy_at' => now()]);

        $person = TelegramScrapedUser::create(['telegram_user_id' => 1201]);
        $this->invokeService('claim', [$this->account(1), $person, false]);

        $service = $this->app->make(ContactSyncService::class);
        (new ReflectionMethod($service, 'releaseStuck'))->invoke($service);

        $this->assertFalse((bool) $this->state(1)->is_busy);
        $this->assertSame(0, TelegramAccountContact::query()->count(), 'the person it was taking is nobody\'s again');
        $this->assertTrue((bool) \App\Models\Telegram\TelegramAccountProcess::query()->where('telegram_account_id', 2)->value('is_busy'), 'another process\'s own is not touched');

        $this->assertSame([2], (new ReflectionMethod($service, 'busyElsewhere'))->invoke($service), 'and the sync does not take it while it is open there');
    }

    public function test_the_run_follows_workers_running_at_once(): void
    {
        $this->workerTables();

        $service = $this->app->make(ContactSyncService::class);
        (new \ReflectionProperty($service, 'runId'))->setValue($service, 'run-1');

        $start = static function (int $accountId, string $script) use ($service): void {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script]);
            $process->start();

            $workers = (new \ReflectionProperty($service, 'workers'))->getValue($service);
            $workers[$accountId] = ['process' => $process, 'account' => \App\Models\Telegram\TelegramAccount::query()->findOrFail($accountId), 'output' => '', 'buffer' => ''];
            (new \ReflectionProperty($service, 'workers'))->setValue($service, $workers);
        };

        $result = ['added' => 2, 'failed' => 0, 'members' => 5, 'new' => 1, 'read' => [7], 'queue' => [8]];

        /* A PHP string literal of the text, newlines and quotes escaped: one line on any command line. */
        $literal = static fn (string $text): string => '"' . addcslashes($text, "\0..\37\"\\\$") . '"';

        $start(1, 'echo ' . $literal("  added 11\n  add") . '; usleep(100000); echo ' . $literal("ed 12\n" . ContactSyncService::RESULT_MARK . json_encode($result) . "\n") . ';');
        $start(2, 'echo ' . $literal("  added 21\n") . '; exit(1);');

        $lines = [];
        $ended = [];
        $say = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        for ($i = 0; $i < 100 && count($ended) < 2; $i++) {
            usleep(100_000);
            $ended = [...$ended, ...(new ReflectionMethod($service, 'poll'))->invoke($service, $say)];
        }

        $this->assertCount(2, $ended);
        $this->assertContains($result, $ended, 'what a worker did, read from its last line');
        $this->assertContains(null, $ended, 'one that died without a result');
        $this->assertContains('#1   added 12', $lines, 'a line cut in two comes out whole, with its account');
        $this->assertContains('#2   added 21', $lines);
        $this->assertSame([], (new \ReflectionProperty($service, 'workers'))->getValue($service));
    }

    public function test_the_developer_report_answers_only_to_its_token_and_shows_holds_and_rests(): void
    {
        $this->workerTables();

        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->boolean('is_authorized')->default(true);
            $table->string('status')->nullable();
            $table->string('session_path')->nullable();
        });

        $url = '/api/telegram/report/contact-sync';
        $token = str_repeat('b2', 20);

        $group = TelegramScrapeGroup::create(['link' => 'drivers']);
        $this->invokeService('storeMembers', [$group, [['id' => 1301, 'first_name' => 'Ali']], []]);
        $this->invokeService('claim', [$this->account(1), TelegramScrapedUser::query()->sole(), false]);
        $this->invokeService('finishClaim', [$this->account(1), TelegramScrapedUser::query()->sole(), TelegramAccountContact::STATUS_ADDED]);

        (new ReflectionMethod($service = $this->app->make(ContactSyncService::class), 'hold'))
            ->invoke($service, $this->account(2), new FloodWaitError('FLOOD_WAIT_600', 600, 420, 'contacts.addContact'));

        config(['services.telegram.report_token' => null]);
        $this->getJson($url, ['Authorization' => "Bearer {$token}"])->assertNotFound();

        config(['services.telegram.report_token' => $token]);
        $this->getJson($url, ['Authorization' => 'Bearer ' . strrev($token)])->assertNotFound();

        $response = $this->getJson("{$url}?days=2&lines=20", ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertStringNotContainsString('998900000001', $response->getContent());

        $accounts = collect($response->json('accounts'))->keyBy('id');
        $this->assertSame([1, 1], [$accounts[1]['added'], $accounts[1]['added_today']]);
        $this->assertSame('FLOOD_WAIT_600', $accounts[2]['sync_state']['disabled_reason']);
        $this->assertNotNull($accounts[2]['sync_state']['rest_until'], 'resting till a time, not for good');
        $this->assertSame(1, $response->json('days.' . now()->format('Y-m-d') . '.added'));
        $this->assertSame(1, $response->json('holds')['flood: FLOOD_WAIT_N'] ?? null);
        $this->assertSame('drivers', $response->json('groups.0.link'));
    }

    public function test_the_stop_job_reads_every_worker_of_a_run(): void
    {
        $workers = new ReflectionMethod(\App\Jobs\Telegram\StopContactSyncJob::class, 'workers');

        $this->assertSame(
            [['account_id' => 3, 'worker_pid' => 30], ['account_id' => 4, 'worker_pid' => 40]],
            $workers->invoke(null, ['workers' => [['account_id' => 3, 'worker_pid' => 30], ['account_id' => 4, 'worker_pid' => 40]]]),
        );
        $this->assertSame([['account_id' => 5, 'worker_pid' => 50]], $workers->invoke(null, ['account_id' => 5, 'worker_pid' => 50]), 'a run of one account at a time');
        $this->assertSame([], $workers->invoke(null, ['account_id' => null]));
    }

    public function test_links_are_normalised(): void
    {
        foreach (['https://t.me/taxi_group', 't.me/taxi_group', '@taxi_group', ' taxi_group '] as $input) {
            $this->assertSame('taxi_group', TelegramScrapeGroup::normalizeLink($input));
        }

        $this->assertSame('+AbCdEf', TelegramScrapeGroup::normalizeLink('https://t.me/+AbCdEf'));
    }

    /**
     * What an account's turn writes besides the people: its state in the
     * pool, the settings the run reads, the names contacts are saved under.
     */
    private function workerTables(): void
    {
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
        (include database_path('migrations/2026_10_16_100000_add_contact_name_to_telegram_account_contacts_table.php'))->up();
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();

        $this->app->make(\App\Application\Telegram\Services\ContactSyncSettings::class)->save([
            ...$this->app->make(\App\Application\Telegram\Services\ContactSyncSettings::class)->current(),
            'daily_limit' => 10,
            'delay_min' => 0,
            'delay_max' => 0,
        ]);
    }

    private function state(int $accountId): \App\Models\Telegram\TelegramAccountProcess
    {
        return \App\Models\Telegram\TelegramAccountProcess::query()->where('telegram_account_id', $accountId)->where('process', 'contact_sync')->sole();
    }

    private function account(int $id): \App\Models\Telegram\TelegramAccount
    {
        return \App\Models\Telegram\TelegramAccount::query()->findOrFail($id);
    }

    /**
     * An unconstructed API (the class is final) whose channels namespace
     * hands out the given pages, one a call, and counts the joins.
     *
     * @param list<array<string, mixed>|\Throwable> $pages
     */
    private function fakeApi(array $pages): API
    {
        $api = (new ReflectionClass(API::class))->newInstanceWithoutConstructor();

        $api->channels = new class($pages)
        {
            /** @var list<int> */
            public array $offsets = [];

            public int $joins = 0;

            public function __construct(private array $pages) {}

            public function getParticipants(mixed ...$args): array
            {
                $this->offsets[] = $args['offset'];

                $page = array_shift($this->pages);

                if ($page instanceof \Throwable) {
                    throw $page;
                }

                return $page;
            }

            public function joinChannel(mixed ...$args): array
            {
                $this->joins++;

                return [];
            }
        };

        $api->contacts = new class
        {
            /** @var list<int> */
            public array $added = [];

            public function getContacts(mixed ...$args): array
            {
                return ['contacts' => []];
            }

            public function addContact(mixed ...$args): array
            {
                $this->added[] = $args['id'];

                return [];
            }
        };

        return $api;
    }
}
