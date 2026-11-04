<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\GroupJoinService;
use App\Application\Telegram\Services\GroupJoinSettings;
use App\Application\Telegram\Services\TelegramAccountProcessService;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Api\Telegram\ContactSyncController;
use App\Http\Controllers\Api\Telegram\GroupJoinController;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramJoinKeyword;
use App\Models\Telegram\TelegramJoinMembership;
use App\Models\Telegram\TelegramJoinSearch;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use danog\MadelineProto\RPCError\FloodWaitError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Join group without Telegram: what is kept from a search and from a
 * group's messages, what a check decides, which group an account takes
 * next and when, how the workers share the work, how the people land in
 * the system's one people table, what a flood wait does, and that the
 * contact sync keeps to its own groups.
 */
final class GroupJoinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->string('session_path')->nullable();
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

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_20_100000_create_telegram_group_join_tables.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001', 'session_path' => '/sessions/1', 'telegram_user_id' => 9001],
            ['id' => 2, 'phone' => '+998900000002', 'session_path' => '/sessions/2', 'telegram_user_id' => 9002],
            ['id' => 3, 'phone' => '+998900000003', 'session_path' => '/sessions/3', 'telegram_user_id' => 9003],
            ['id' => 4, 'phone' => '+998900000004', 'session_path' => '/sessions/4', 'telegram_user_id' => 9004],
        ]);

        /* Account 3 is the listener's: never in Join group. */
        config()->set('services.telegram.driver_check_account_id', 3);
        config()->set('group_join.places', ['Toshkent', 'Москва']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function invokeService(string $method, array $args = [], ?GroupJoinService $service = null)
    {
        $service ??= $this->app->make(GroupJoinService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    private function config(array $overrides = []): array
    {
        return array_replace(app(GroupJoinSettings::class)->current(), $overrides);
    }

    private function account(int $id): TelegramAccount
    {
        return TelegramAccount::query()->findOrFail($id);
    }

    private function meta(int $accountId): array
    {
        $meta = TelegramAccountProcess::query()
            ->where('telegram_account_id', $accountId)
            ->where('process', ProcessEnum::GroupJoin->value)
            ->value('meta');

        return is_array($meta) ? $meta : (array) json_decode((string) $meta, true);
    }

    /** A group Join group found. */
    private function group(string $link, array $extra = []): TelegramScrapeGroup
    {
        return TelegramScrapeGroup::create([
            'link' => $link,
            'type' => TelegramScrapeGroup::TYPE_DATABASE,
            'is_active' => false,
            'join_status' => TelegramScrapeGroup::JOIN_APPROVED,
            'decided_by' => 'auto',
            'checked_at' => now(),
            ...$extra,
        ]);
    }

    private function alive(int $count = 40, string $text = 'Toshkentdan Samarqandga yuk bor, mashina kerak'): array
    {
        $now = now()->timestamp;
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $messages[] = ['_' => 'message', 'date' => $now - $i * 900, 'from_id' => $i % 8, 'message' => $text];
        }

        return $messages;
    }

    public function test_the_logistics_keywords_come_with_the_migration(): void
    {
        $this->assertSame('seed', TelegramJoinKeyword::query()->where('keyword', 'yuk')->value('source'));
        $this->assertTrue(TelegramJoinKeyword::query()->where('keyword', 'yuk')->value('expand'));
        $this->assertFalse(TelegramJoinKeyword::query()->where('keyword', 'yuk bor')->value('expand'));
    }

    public function test_a_keyword_is_searched_alone_and_with_every_city(): void
    {
        TelegramJoinKeyword::query()->delete();

        TelegramJoinKeyword::create(['keyword' => 'yuk', 'expand' => true]);
        TelegramJoinKeyword::create(['keyword' => 'yuk bor', 'expand' => false]);
        $off = TelegramJoinKeyword::create(['keyword' => 'gruz', 'is_active' => false]);

        $service = $this->app->make(GroupJoinService::class);

        $this->invokeService('refresh', [], $service);

        $this->assertEqualsCanonicalizing(['yuk', 'yuk Toshkent', 'yuk Москва', 'yuk bor'], TelegramJoinSearch::query()->pluck('query')->all());

        /* Switched on: its query comes; a keyword without cities any more loses them. */
        $off->update(['is_active' => true]);
        TelegramJoinKeyword::query()->where('keyword', 'yuk')->update(['expand' => false]);

        $fresh = $this->app->make(GroupJoinService::class);
        $this->invokeService('refresh', [], $fresh);

        $this->assertEqualsCanonicalizing(['yuk', 'yuk bor', 'gruz'], TelegramJoinSearch::query()->pluck('query')->all());
    }

    public function test_a_search_keeps_public_groups_for_the_database_and_leaves_the_contact_syncs_alone(): void
    {
        $keyword = TelegramJoinKeyword::query()->where('keyword', 'yuk')->firstOrFail();
        $search = TelegramJoinSearch::create(['keyword_id' => $keyword->id, 'query' => 'yuk Toshkent']);

        /* The contact sync's own group, added on its page. */
        TelegramScrapeGroup::create(['link' => 'drivers_chat', 'title' => 'Drivers']);

        $chats = [
            ['_' => 'channel', 'megagroup' => true, 'username' => 'Yuk_Bor', 'title' => 'Yuk bor', 'id' => -1001234567890, 'participants_count' => 900],
            ['_' => 'channel', 'megagroup' => true, 'usernames' => [['username' => 'gruz_uz', 'active' => true]], 'title' => 'Gruz', 'id' => 555],
            ['_' => 'channel', 'megagroup' => true, 'username' => 'Drivers_Chat', 'title' => 'Drivers chat'],
            /* A channel; a chat without a username. */
            ['_' => 'channel', 'broadcast' => true, 'username' => 'news', 'title' => 'News', 'id' => 777],
            ['_' => 'channel', 'megagroup' => true, 'title' => 'Private', 'id' => 888],
        ];

        $this->assertSame([3, 2], $this->invokeService('storeFound', [$search, $chats]));

        $found = TelegramScrapeGroup::query()->where('link', 'yuk_bor')->firstOrFail();

        $this->assertSame(TelegramScrapeGroup::TYPE_DATABASE, $found->type);
        $this->assertSame(TelegramScrapeGroup::JOIN_NEW, $found->join_status);
        $this->assertSame('search', $found->source);
        $this->assertSame('yuk Toshkent', $found->found_via);
        $this->assertSame($keyword->id, $found->keyword_id);
        $this->assertFalse($found->is_active);
        $this->assertSame(-1000000000555, TelegramScrapeGroup::query()->where('link', 'gruz_uz')->value('telegram_chat_id'));

        $own = TelegramScrapeGroup::query()->where('link', 'drivers_chat')->firstOrFail();

        $this->assertSame(TelegramScrapeGroup::TYPE_CONTACTS, $own->type);
        $this->assertNull($own->join_status);
    }

    public function test_a_check_decides_by_life_topic_and_letters_and_respects_a_person(): void
    {
        $now = now()->timestamp;

        $alive = $this->group('alive', ['join_status' => TelegramScrapeGroup::JOIN_NEW, 'checked_at' => null, 'locked_until' => now()->addMinutes(5)]);
        $this->invokeService('applyCheck', [$alive, ['title' => 'Yuk markazi'], 500, $this->alive(), $this->config(), $now]);

        $alive->refresh();
        $this->assertSame(TelegramScrapeGroup::JOIN_APPROVED, $alive->join_status);
        $this->assertSame(40, $alive->topic_hits);
        $this->assertSame(100, $alive->script_share);
        $this->assertNull($alive->locked_until);

        /* Busy, but about something else. */
        $other = $this->group('flea_market');
        $this->invokeService('applyCheck', [$other, ['title' => 'Барахолка'], 500, $this->alive(40, 'Продам диван, недорого, самовывоз'), $this->config(), $now]);

        $this->assertSame(TelegramScrapeGroup::JOIN_REJECTED, $other->fresh()->join_status);
        $this->assertSame('off_topic', $other->fresh()->reject_reason);

        /* A joined group that went quiet keeps its accounts and gets a warning. */
        $joined = $this->group('joined', ['join_status' => TelegramScrapeGroup::JOIN_JOINED]);
        $this->invokeService('applyCheck', [$joined, [], 500, [], $this->config(), $now]);

        $this->assertSame(TelegramScrapeGroup::JOIN_JOINED, $joined->fresh()->join_status);
        $this->assertSame('inactive', $joined->fresh()->reject_reason);

        /* Approved by a person: the check refreshes numbers, not the decision. */
        $manual = $this->group('manual', ['decided_by' => 'manual']);
        $this->invokeService('applyCheck', [$manual, [], 10, [], $this->config(), $now]);

        $this->assertSame(TelegramScrapeGroup::JOIN_APPROVED, $manual->fresh()->join_status);
        $this->assertSame('too_small', $manual->fresh()->reject_reason);
    }

    public function test_a_groups_messages_lead_to_new_groups(): void
    {
        $source = $this->group('yuk_markazi');

        $messages = [
            ['_' => 'message', 'message' => 'Boshqa guruhlarimiz: t.me/gruz_uz va https://t.me/+AbCdEfGhIjKl'],
            ['_' => 'message', 'message' => 'Kanal @yuk_bor_uz'],
        ];
        $chats = [
            ['_' => 'channel', 'megagroup' => true, 'username' => 'fura_moskva', 'title' => 'Фура Москва', 'participants_count' => 3000],
        ];

        $new = $this->invokeService('followLinks', [$this->account(1), $source, $messages, $chats]);

        $this->assertSame(4, $new);

        $forward = TelegramScrapeGroup::query()->where('link', 'fura_moskva')->firstOrFail();

        $this->assertSame('forward', $forward->source);
        $this->assertSame('yuk_markazi', $forward->found_via);
        $this->assertSame(TelegramScrapeGroup::JOIN_NEW, $forward->join_status);
        $this->assertTrue(TelegramScrapeGroup::query()->where('link', '+AbCdEfGhIjKl')->firstOrFail()->isInvite());

        /* Found again: nothing new. */
        $this->assertSame(0, $this->invokeService('followLinks', [$this->account(1), $source, $messages, $chats]));
    }

    public function test_good_titles_teach_new_keywords(): void
    {
        foreach (['Yuk tashish Toshkent', 'Yuk tashish Samarqand', 'Yuk tashish Andijon'] as $i => $title) {
            $this->group('g' . $i, ['title' => $title]);
        }

        TelegramJoinKeyword::query()->where('keyword', 'yuk tashish')->delete();

        $this->invokeService('learnKeywords', [$this->account(1)]);

        $learned = TelegramJoinKeyword::query()->where('keyword', 'yuk tashish')->firstOrFail();

        $this->assertSame('learned', $learned->source);
        $this->assertTrue($learned->is_active);
    }

    public function test_an_account_takes_the_best_group_it_is_not_in_yet(): void
    {
        $quiet = $this->group('quiet', ['score' => 10]);
        $busy = $this->group('busy', ['score' => 900]);
        $this->group('stale', ['score' => 5000, 'checked_at' => now()->subDays(30)]);
        $this->group('rejected', ['score' => 9000, 'join_status' => TelegramScrapeGroup::JOIN_REJECTED]);
        $this->group('taken', ['score' => 8000, 'locked_until' => now()->addMinutes(5)]);
        /* The contact sync's group is never Join group's to join. */
        TelegramScrapeGroup::create(['link' => 'contacts_group', 'score' => 99999]);

        $config = $this->config(['accounts_per_group' => 1]);

        $this->assertSame($busy->id, $this->invokeService('nextGroupFor', [1, $config])->id);

        TelegramJoinMembership::create(['group_id' => $busy->id, 'telegram_account_id' => 2, 'status' => 'joined', 'joined_at' => now()]);

        $this->assertSame($quiet->id, $this->invokeService('nextGroupFor', [1, $config])->id);

        $two = $this->config(['accounts_per_group' => 2]);

        $this->assertSame($busy->id, $this->invokeService('nextGroupFor', [1, $two])->id);
        $this->assertSame($quiet->id, $this->invokeService('nextGroupFor', [2, $two])->id);

        $manual = $this->group('manual', ['score' => 0, 'decided_by' => 'manual', 'checked_at' => null]);

        $this->assertSame($manual->id, $this->invokeService('nextGroupFor', [1, $config])->id);
    }

    public function test_an_account_joins_only_after_its_pause_and_under_its_limits(): void
    {
        $config = $this->config(['joins_per_day' => 2, 'max_groups_per_account' => 3]);
        $account = $this->account(1);

        $this->assertTrue($this->invokeService('canJoin', [$account, $config, []]));
        $this->assertFalse($this->invokeService('canJoin', [$account, $config, ['next_join_at' => now()->addMinute()->toIso8601String()]]));
        $this->assertFalse($this->invokeService('canJoin', [$account, $config, ['full' => true]]));

        foreach (['a', 'b'] as $link) {
            TelegramJoinMembership::create(['group_id' => $this->group($link)->id, 'telegram_account_id' => 1, 'status' => 'joined', 'joined_at' => now()]);
        }

        $this->assertFalse($this->invokeService('canJoin', [$account, $config, []]));

        Carbon::setTestNow(now()->addDay());

        $this->assertTrue($this->invokeService('canJoin', [$account, $config, []]));

        TelegramJoinMembership::create(['group_id' => $this->group('c')->id, 'telegram_account_id' => 1, 'status' => 'requested', 'joined_at' => now()]);

        $this->assertFalse($this->invokeService('canJoin', [$account, $config, []]));
    }

    public function test_two_workers_never_take_the_same_row(): void
    {
        $group = $this->group('shared');

        $this->assertTrue($this->invokeService('take', [TelegramScrapeGroup::class, $group->id]));
        $this->assertFalse($this->invokeService('take', [TelegramScrapeGroup::class, $group->id]));

        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertTrue($this->invokeService('take', [TelegramScrapeGroup::class, $group->id]));
    }

    public function test_the_people_land_in_the_shared_tables_and_count_on_the_contacts_pages_apart(): void
    {
        $first = $this->group('first');
        $second = $this->group('second');
        $contacts = TelegramScrapeGroup::create(['link' => 'contacts_group']);

        $this->invokeService('storeMembers', [$first, [
            ['_' => 'user', 'id' => 501, 'first_name' => 'Ali', 'username' => 'ali', 'phone' => '998901112233'],
            ['_' => 'user', 'id' => 502, 'first_name' => 'Bot', 'bot' => true],
            /* Our own account: not stored. */
            ['_' => 'user', 'id' => 9001, 'first_name' => 'Own'],
        ]]);

        $this->invokeService('storeMembers', [$second, [
            ['_' => 'user', 'id' => 501, 'first_name' => 'Ali R.', 'usernames' => [['username' => 'ali_r', 'active' => true]]],
            ['_' => 'user', 'id' => 503, 'first_name' => 'Vali'],
        ]]);

        $this->assertSame(3, TelegramScrapedUser::query()->count());
        $this->assertSame(4, DB::table('telegram_scrape_group_user')->count());

        $ali = TelegramScrapedUser::query()->where('telegram_user_id', 501)->firstOrFail();

        $this->assertSame('Ali R.', $ali->first_name);
        $this->assertSame('ali_r', $ali->username);
        $this->assertNotNull($ali->phone, 'a later read without the phone does not wipe it');
        $this->assertEqualsCanonicalizing(['first', 'second'], $ali->groups()->pluck('link')->all());

        /* Vali is in a contacts group too: the contact sync's person. Ali is only in database groups. */
        TelegramScrapedUser::query()->where('telegram_user_id', 503)->firstOrFail()->groups()->attach($contacts->id);

        $this->assertSame([503], TelegramScrapedUser::query()->forContacts()->pluck('telegram_user_id')->all());

        $totals = $this->app->make(ContactSyncController::class)->show()->getData(true)['data'];

        /* Everybody found counts (the bot aside), as on the group invites' page (2026-11-04); the sync's own groups' people apart. Its groups list stays its own. */
        $this->assertSame([2, 1], [$totals['totals']['users'], $totals['totals']['users_own_groups']]);
        $this->assertSame(['contacts_group'], array_column($totals['groups'], 'link'));
    }

    public function test_a_flood_wait_rests_the_account_for_what_telegram_said_plus_the_extra(): void
    {
        $config = $this->config(['flood_extra_min' => 20, 'flood_extra_max' => 30]);

        $this->assertTrue($this->invokeService('trouble', [$this->account(1), new FloodWaitError('FLOOD_WAIT_120', 120, 420, 'channels.joinChannel'), $config, null]));

        $meta = $this->meta(1);
        $waits = Carbon::parse($meta['flood_until'])->timestamp - now()->timestamp;

        $this->assertGreaterThanOrEqual(140, $waits);
        $this->assertLessThanOrEqual(150, $waits);
        $this->assertTrue((bool) TelegramAccountProcess::query()->where('telegram_account_id', 1)->value('is_available'));
        $this->assertDatabaseHas('telegram_join_logs', ['telegram_account_id' => 1, 'event' => 'flood']);
    }

    public function test_too_many_groups_marks_the_account_full_and_a_group_error_does_not_stop_it(): void
    {
        $config = $this->config();

        $this->assertTrue($this->invokeService('trouble', [$this->account(2), RPCErrorException::make('CHANNELS_TOO_MUCH', 400, ''), $config, null]));
        $this->assertTrue($this->meta(2)['full']);

        $this->assertFalse($this->invokeService('trouble', [$this->account(2), RPCErrorException::make('USER_BANNED_IN_CHANNEL', 400, ''), $config, null]));
    }

    public function test_a_group_three_accounts_could_not_join_is_given_up(): void
    {
        $group = $this->group('closed');
        $config = $this->config();

        foreach ([1, 2, 4] as $id) {
            $this->invokeService('failed', [$group, $this->account($id), 'USER_BANNED_IN_CHANNEL', $config]);
        }

        $this->assertSame(TelegramScrapeGroup::JOIN_DEAD, $group->fresh()->join_status);
        $this->assertTrue(Carbon::parse($this->meta(1)['next_join_at'])->isFuture());
    }

    public function test_the_marked_accounts_are_kept_away_from_the_pool_while_join_group_is_on(): void
    {
        $settings = app(GroupJoinSettings::class);

        $saved = $settings->save(['enabled' => '1', 'accounts' => [1, 2, 4, 3], 'joins_per_day' => 500]);

        $this->assertSame([1, 2, 4], $saved['accounts']);
        $this->assertSame(50, $saved['joins_per_day']);
        $this->assertSame([1, 2, 4], GroupJoinSettings::dedicatedIds());

        $pool = app(TelegramAccountProcessService::class);

        $this->assertNull($pool->claimRandomAvailableAccount(ProcessEnum::ResolverPhone));

        $settings->save(['enabled' => false, 'accounts' => [1, 2, 4]]);

        $this->assertSame([], GroupJoinSettings::dedicatedIds());
        $this->assertNotNull($pool->claimRandomAvailableAccount(ProcessEnum::ResolverPhone));
    }

    public function test_a_worker_runs_only_for_a_marked_authorized_account(): void
    {
        $service = $this->app->make(GroupJoinService::class);
        $settings = app(GroupJoinSettings::class);

        $this->assertFalse($service->mayWork($this->account(1), $settings->save(['enabled' => true, 'accounts' => [2]])));
        $this->assertTrue($service->mayWork($this->account(1), $settings->save(['enabled' => true, 'accounts' => [1]])));
        $this->assertFalse($service->mayWork($this->account(1), $settings->save(['enabled' => false, 'accounts' => [1]])));

        DB::table('telegram_accounts')->where('id', 1)->update(['is_authorized' => false]);

        $this->assertFalse($service->mayWork($this->account(1), $settings->save(['enabled' => true, 'accounts' => [1]])));
    }

    public function test_the_section_lists_joined_waiting_and_people_and_hands_a_group_to_contacts(): void
    {
        app(GroupJoinSettings::class)->save(['enabled' => true, 'accounts' => [1, 2]]);

        $joined = $this->group('joined_one', ['join_status' => TelegramScrapeGroup::JOIN_JOINED, 'members_read' => 2]);
        $this->group('waiting_one', ['score' => 50]);
        $this->group('new_one', ['join_status' => TelegramScrapeGroup::JOIN_NEW]);
        $this->group('rejected_one', ['join_status' => TelegramScrapeGroup::JOIN_REJECTED]);
        TelegramScrapeGroup::create(['link' => 'contacts_group']);

        $this->invokeService('storeMembers', [$joined, [
            ['_' => 'user', 'id' => 601, 'first_name' => 'Ali', 'username' => 'ali_aka', 'phone' => '998901112233'],
            ['_' => 'user', 'id' => 602, 'first_name' => 'Vali'],
        ]]);

        $controller = $this->app->make(GroupJoinController::class);

        $data = $controller->show()->getData(true)['data'];

        $this->assertSame(4, $data['totals']['groups']);
        $this->assertSame(1, $data['totals']['joined']);
        $this->assertSame(1, $data['totals']['approved']);
        $this->assertSame(2, $data['totals']['people']);
        $this->assertSame([1, 2], array_column($data['accounts'], 'id'));

        $list = fn (string $view) => array_column($controller->groups(Request::create('/', 'GET', ['view' => $view]))->getData(true)['data'], 'link');

        $this->assertSame(['joined_one'], $list('joined'));
        $this->assertSame(['waiting_one', 'new_one'], $list('queue'));
        $this->assertSame(['rejected_one'], $list('other'));

        $people = fn (array $query) => $controller->people(Request::create('/', 'GET', $query))->getData(true);

        $this->assertSame(2, $people([])['meta']['total']);
        $this->assertSame(['ali_aka'], array_column($people(['search' => '@ali'])['data'], 'username'));
        $this->assertSame(['ali_aka'], array_column($people(['search' => '99890111'])['data'], 'username'));
        $this->assertSame(1, $people(['phone' => 1])['meta']['total']);
        $this->assertSame('joined_one', $people(['group_id' => $joined->id])['group']['link']);

        /* Handed to the contact sync: it reads the group from now on. */
        $controller->updateGroup(Request::create('/', 'PATCH', ['action' => 'contacts']), $joined);

        $this->assertSame(TelegramScrapeGroup::TYPE_CONTACTS, $joined->fresh()->type);
        $this->assertTrue($joined->fresh()->is_active);
        $this->assertSame(2, TelegramScrapeGroup::query()->forContacts()->count());
    }
}
