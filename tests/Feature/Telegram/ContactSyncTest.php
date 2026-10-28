<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\ContactSyncService;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramScrapeGroup;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCError\FloodWaitError;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    public function test_links_are_normalised(): void
    {
        foreach (['https://t.me/taxi_group', 't.me/taxi_group', '@taxi_group', ' taxi_group '] as $input) {
            $this->assertSame('taxi_group', TelegramScrapeGroup::normalizeLink($input));
        }

        $this->assertSame('+AbCdEf', TelegramScrapeGroup::normalizeLink('https://t.me/+AbCdEf'));
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

        return $api;
    }
}
