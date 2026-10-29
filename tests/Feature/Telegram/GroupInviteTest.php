<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\GroupInviteService;
use App\Application\Telegram\Services\GroupInviteSettings;
use App\Application\Telegram\Services\MadelineService;
use App\Application\Telegram\Support\UsernameLookup;
use ArrayObject;
use danog\MadelineProto\API;
use Mockery;
use ReflectionClass;
use App\Enums\Telegram\TelegramAccountProcess as ProcessEnum;
use App\Http\Controllers\Api\Telegram\GroupInviteController;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountContact;
use App\Models\Telegram\TelegramAccountProcess;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramGroupInviteLog;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Who an account may invite - its contacts, then people with a @username -
 * the one-try claim, what is remembered on a person, how an error is told
 * apart, and how an account rests after a flood. The Telegram call itself
 * is not here.
 */
final class GroupInviteTest extends TestCase
{
    private GroupInviteService $service;

    /** @var ArrayObject<int, int> every person looked up in the group */
    private ArrayObject $checks;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.driver_check_account_id' => null]);

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
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

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_17_100000_create_telegram_account_contacts_state_tables.php'))->up();
        (include database_path('migrations/2026_10_29_120000_create_telegram_group_invite_tables.php'))->up();
        (include database_path('migrations/2026_10_29_140000_add_invite_flags_to_telegram_tables.php'))->up();
        (include database_path('migrations/2026_10_29_160000_check_telegram_group_invites.php'))->up();

        DB::table('telegram_accounts')->insert([
            ['id' => 1, 'phone' => '+998900000001', 'telegram_user_id' => 9001, 'session_path' => '/sessions/1'],
            ['id' => 2, 'phone' => '+998900000002', 'telegram_user_id' => 9002, 'session_path' => '/sessions/2'],
        ]);

        $this->service = $this->app->make(GroupInviteService::class);
    }

    public function test_defaults_are_twenty_at_a_time_eighty_times_a_day_then_usernames(): void
    {
        $values = $this->app->make(GroupInviteSettings::class)->current();

        $this->assertSame(20, $values['batch_size']);
        $this->assertSame(80, $values['batches_per_day']);
        $this->assertTrue($values['use_usernames']);
        $this->assertSame(100, $values['usernames_per_day']);
    }

    public function test_only_this_accounts_untried_contacts_are_candidates(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $account = TelegramAccount::query()->find(1);
        $other = TelegramAccount::query()->find(2);

        $mine = $this->person(101, 'Ali');
        $bot = $this->person(102, 'Bot', true);
        $theirs = $this->person(103, 'Vali');
        $done = $this->person(104, 'Done');
        $own = $this->person(9001, 'Us');
        $refuses = $this->person(105, 'Private');
        $refuses->update(['can_add_to_group' => false]);

        foreach ([$mine, $bot, $done, $own, $refuses] as $person) {
            $this->hold(1, $person);
        }
        $this->hold(2, $theirs);

        TelegramGroupInvite::query()->create([
            'invite_group_id' => $group->id,
            'scraped_user_id' => $done->id,
            'telegram_account_id' => 1,
            'status' => TelegramGroupInvite::STATUS_INVITED,
            'attempted_at' => now(),
        ]);

        $this->assertSame([101], $this->service->candidates($account, $group, 20)->pluck('telegram_user_id')->all());
        $this->assertSame([103], $this->service->candidates($other, $group, 20)->pluck('telegram_user_id')->all());
    }

    public function test_a_person_is_claimed_once_and_telegrams_answer_stays_on_the_person(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $riders = TelegramInviteGroup::query()->create(['link' => 'riders', 'is_active' => true]);
        $account = TelegramAccount::query()->find(1);

        $first = $this->person(201, 'One');
        $second = $this->person(202, 'Two');
        $this->hold(1, $first);
        $this->hold(1, $second);

        $batch = (string) Str::uuid();
        $claimed = $this->service->claim($account, $group, collect([$first, $second]), $batch);

        $this->assertCount(2, $claimed);
        $this->assertCount(0, $this->service->candidates($account, $group, 20));
        $this->assertCount(0, $this->service->claim($account, $group, collect([$first]), (string) Str::uuid()));

        $this->assertSame(
            ['invited' => 1, 'not_added' => 0, 'already' => 0, 'sent' => 0, 'failed' => 1],
            $this->service->settle($batch, [202 => 'missing_invitee'], [201 => ['status' => TelegramGroupInvite::STATUS_INVITED, 'error' => null]]),
        );

        $this->assertSame(TelegramGroupInvite::STATUS_INVITED, $this->inviteOf($first)->status);
        $this->assertNotNull($this->inviteOf($first)->checked_at);
        $this->assertSame('missing_invitee', $this->inviteOf($second)->error);

        $this->assertTrue($first->fresh()->can_add_to_group);
        $this->assertFalse($second->fresh()->can_add_to_group);
        $this->assertSame('missing_invitee', $second->fresh()->add_to_group_error);

        /* Privacy keeps us out of every group: the other one does not try them. */
        $this->assertSame([201], $this->service->candidates($account, $riders, 20)->pluck('telegram_user_id')->all());
    }

    public function test_a_released_claim_can_be_tried_again_and_a_failure_cannot(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $account = TelegramAccount::query()->find(1);

        $person = $this->person(301, 'Retry');
        $this->hold(1, $person);

        $batch = (string) Str::uuid();
        $this->service->claim($account, $group, collect([$person]), $batch);
        $this->service->releaseBatch($batch);

        $this->assertSame([301], $this->service->candidates($account, $group, 20)->pluck('telegram_user_id')->all());

        $failed = (string) Str::uuid();
        $this->service->claim($account, $group, collect([$person]), $failed);
        $this->service->failBatch($failed, 'USER_KICKED');

        $this->assertSame([], $this->service->candidates($account, $group, 20)->pluck('telegram_user_id')->all());
        $this->assertNull($person->fresh()->can_add_to_group);

        TelegramGroupInvite::query()->where('status', TelegramGroupInvite::STATUS_FAILED)->delete();

        $this->assertSame([301], $this->service->candidates($account, $group, 20)->pluck('telegram_user_id')->all());

        $refused = (string) Str::uuid();
        $this->service->claim($account, $group, collect([$person]), $refused);
        $this->service->failBatch($refused, 'USER_CHANNELS_TOO_MUCH', true);

        $this->assertFalse($person->fresh()->can_add_to_group);
    }

    public function test_people_with_a_username_are_everybodys_once_contacts_run_out(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $account = TelegramAccount::query()->find(1);

        $older = $this->person(401, 'Older', false, 'older');
        $newer = $this->person(402, 'Newer', false, 'newer');
        $this->person(403, 'NoName');
        $this->person(404, 'Bot', true, 'some_bot');
        $this->person(9002, 'Us', false, 'our_account');
        $this->person(405, 'Private', false, 'private')->update(['can_add_to_group' => false]);
        $tried = $this->person(406, 'Tried', false, 'tried');

        TelegramGroupInvite::query()->create([
            'invite_group_id' => $group->id,
            'scraped_user_id' => $tried->id,
            'status' => TelegramGroupInvite::STATUS_FAILED,
            'attempted_at' => now(),
        ]);

        $this->assertSame([402, 401], $this->service->usernameCandidates($group, 20)->pluck('telegram_user_id')->all());

        $batch = (string) Str::uuid();
        $this->service->claim($account, $group, collect([$older, $newer]), $batch, TelegramGroupInvite::VIA_USERNAME);

        $this->assertSame(TelegramGroupInvite::VIA_USERNAME, $this->inviteOf($older)->via);
        $this->assertSame(0, $this->service->resolvesToday(1));

        $this->service->settle($batch, []);

        $this->assertSame(2, $this->service->resolvesToday(1));
        $this->assertSame(0, $this->service->resolvesToday(2));
    }

    public function test_the_days_calls_are_counted_from_the_log(): void
    {
        $call = fn (int $accountId, string $event = TelegramGroupInviteLog::EVENT_CALL) => TelegramGroupInviteLog::query()->create([
            'run_id' => 'run',
            'telegram_account_id' => $accountId,
            'event' => $event,
            'message' => 'x',
        ]);

        $call(1);
        $call(1);
        $call(1, 'flood');
        $call(2);
        $call(1)->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertSame(2, $this->service->callsToday(1));
        $this->assertSame([1 => 2, 2 => 1], TelegramGroupInviteLog::callsToday());
    }

    public function test_an_error_is_told_apart_by_whose_it_is(): void
    {
        $rpc = fn (string $code, int $status = 400) => RPCErrorException::make($code, $status, '');

        /* The person's deleted account is not our own account's. */
        $this->assertSame(GroupInviteService::KIND_PERSON, GroupInviteService::classify($rpc('INPUT_USER_DEACTIVATED')));
        $this->assertSame(GroupInviteService::KIND_DEAD, GroupInviteService::classify($rpc('USER_DEACTIVATED', 401)));
        $this->assertSame(GroupInviteService::KIND_DEAD, GroupInviteService::classify($rpc('AUTH_KEY_UNREGISTERED', 401)));

        $this->assertSame(GroupInviteService::KIND_PERSON, GroupInviteService::classify($rpc('USER_PRIVACY_RESTRICTED', 403)));
        $this->assertSame(GroupInviteService::KIND_PERSON_HERE, GroupInviteService::classify($rpc('USER_KICKED')));
        $this->assertSame(GroupInviteService::KIND_ALREADY, GroupInviteService::classify($rpc('USER_ALREADY_PARTICIPANT')));
        /* Left the group: not junk, only not by this call. */
        $this->assertSame(GroupInviteService::KIND_NOT_ADDED, GroupInviteService::classify($rpc('USER_NOT_MUTUAL_CONTACT')));

        $this->assertSame(GroupInviteService::KIND_SPAM, GroupInviteService::classify($rpc('PEER_FLOOD')));
        $this->assertSame(GroupInviteService::KIND_WAIT, GroupInviteService::classify(new RateLimitError('FLOOD_WAIT_30', 30, 420, '')));

        $this->assertSame(GroupInviteService::KIND_GROUP_DENIED, GroupInviteService::classify($rpc('CHAT_ADMIN_REQUIRED')));
        $this->assertSame(GroupInviteService::KIND_GROUP_GONE, GroupInviteService::classify($rpc('USERS_TOO_MUCH')));

        /* A missing @name is the group's at the join, the person's at the lookup. */
        $this->assertSame(GroupInviteService::KIND_GROUP_GONE, GroupInviteService::classify($rpc('USERNAME_NOT_OCCUPIED'), GroupInviteService::STEP_JOIN));
        $this->assertSame(GroupInviteService::KIND_PERSON_HERE, GroupInviteService::classify($rpc('USERNAME_NOT_OCCUPIED'), GroupInviteService::STEP_RESOLVE));

        /* An unknown refusal is somebody in the call; anything else is passing trouble. */
        $this->assertSame(GroupInviteService::KIND_PERSON_HERE, GroupInviteService::classify($rpc('SOMETHING_NEW')));
        $this->assertSame(GroupInviteService::KIND_GROUP_DENIED, GroupInviteService::classify($rpc('SOMETHING_NEW'), GroupInviteService::STEP_JOIN));
        $this->assertSame(GroupInviteService::KIND_TRANSIENT, GroupInviteService::classify(new RuntimeException('Connection reset')));

        /* MadelineProto may wrap the error. */
        $this->assertSame(GroupInviteService::KIND_SPAM, GroupInviteService::classify(new RuntimeException('wrapped', 0, $rpc('PEER_FLOOD'))));
    }

    public function test_a_peer_flood_rests_the_account_longer_each_time_and_lowers_its_cap(): void
    {
        $account = TelegramAccount::query()->find(1);
        $config = $this->app->make(GroupInviteSettings::class)->current();

        for ($i = 0; $i < 10; $i++) {
            TelegramGroupInviteLog::query()->create([
                'run_id' => 'run',
                'telegram_account_id' => 1,
                'event' => TelegramGroupInviteLog::EVENT_CALL,
                'message' => 'x',
            ]);
        }

        $this->service->spamLimited($account, $config, 'PEER_FLOOD');
        $meta = $this->service->meta($account);

        $this->assertSame(1, $meta['peer_floods']);
        $this->assertSame(7, $meta['cap']);
        $this->assertSame(7, $this->service->dailyCap($account, $config));
        $this->assertEqualsWithDelta(24 * 3600 + 900, strtotime($meta['rest_until']) - time(), 905);

        $this->service->spamLimited($account, $config, 'PEER_FLOOD');
        $meta = $this->service->meta($account);

        $this->assertSame(2, $meta['peer_floods']);
        $this->assertSame(4, $meta['cap']);
        $this->assertEqualsWithDelta(48 * 3600 + 900, strtotime($meta['rest_until']) - time(), 905);

        /* A clean day after: a quarter more, one flood fewer to remember. */
        $state = TelegramAccountProcess::query()
            ->where('telegram_account_id', 1)
            ->where('process', ProcessEnum::GroupInvite->value)
            ->first();
        $state->update(['meta' => [...$state->meta, 'clean_day' => now()->subDay()->toDateString()]]);

        $this->assertSame(5, $this->service->dailyCap($account, $config));
        $this->assertSame(1, $this->service->meta($account)['peer_floods']);
        $this->assertArrayNotHasKey('clean_day', $this->service->meta($account));
    }

    public function test_a_call_refused_for_one_person_is_split_until_that_person_is_found(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach ([501, 502, 503, 504] as $id) {
            $this->hold(1, $this->person($id, "P{$id}"));
        }

        $calls = $this->fakeTelegram(invite: function (array $users) {
            if (in_array(503, $users, true)) {
                throw RPCErrorException::make('USER_CHANNELS_TOO_MUCH', 400, '');
            }

            return $this->added($users);
        });

        $totals = $this->service->run();

        /* 4 refused, 2 in, 2 refused, 1 refused, 1 in. */
        $this->assertSame([[501, 502, 503, 504], [501, 502], [503, 504], [503], [504]], $calls->getArrayCopy());
        $this->assertSame(['accounts' => 1, 'batches' => 5, 'invited' => 3, 'not_added' => 0, 'already' => 0, 'sent' => 0, 'failed' => 1], $totals);
        /* Telegram's own message named everybody added: nobody had to be looked up. */
        $this->assertCount(0, $this->checks);
        $this->assertSame(5, $this->service->callsToday(1));

        $this->assertSame(
            [501 => 'invited', 502 => 'invited', 503 => 'failed', 504 => 'invited'],
            TelegramGroupInvite::query()->with('user')->get()->mapWithKeys(fn ($row) => [$row->user->telegram_user_id => $row->status])->sortKeys()->all(),
        );
        $this->assertFalse(TelegramScrapedUser::query()->where('telegram_user_id', 503)->value('can_add_to_group'));
        $this->assertSame(-1000000000777, TelegramInviteGroup::query()->value('telegram_chat_id'));
    }

    public function test_without_contacts_an_account_invites_people_by_their_username(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        $this->person(601, 'Alpha', false, 'alpha');
        $this->person(602, 'Beta', false, 'beta');

        $calls = $this->fakeTelegram(
            invite: fn (array $users) => $this->added($users),
            resolve: fn (string $username) => match ($username) {
                'alpha' => ['_' => 'user', 'id' => 601],
                /* The name is somebody else's by now. */
                'beta' => ['_' => 'user', 'id' => 999],
            },
        );

        $this->service->run();

        $this->assertSame([[601]], $calls->getArrayCopy());

        $alpha = TelegramGroupInvite::query()->whereHas('user', fn ($q) => $q->where('telegram_user_id', 601))->first();
        $beta = TelegramGroupInvite::query()->whereHas('user', fn ($q) => $q->where('telegram_user_id', 602))->first();

        $this->assertSame([TelegramGroupInvite::STATUS_INVITED, TelegramGroupInvite::VIA_USERNAME], [$alpha->status, $alpha->via]);
        $this->assertSame([TelegramGroupInvite::STATUS_FAILED, 'USERNAME_OWNER_CHANGED'], [$beta->status, $beta->error]);
        $this->assertNull(TelegramScrapedUser::query()->where('telegram_user_id', 602)->value('can_add_to_group'));
        /* Either account may take them: neither has contacts of its own. */
        $this->assertSame(2, $this->service->resolvesToday($alpha->telegram_account_id));
        $this->assertSame($alpha->telegram_account_id, $beta->telegram_account_id);
        $this->assertSame($group->id, $alpha->invite_group_id);
    }

    public function test_a_peer_flood_gives_the_people_back_and_rests_the_account(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $this->hold(1, $this->person(701, 'One'));

        $this->fakeTelegram(invite: fn () => throw RPCErrorException::make('PEER_FLOOD', 400, ''));

        $totals = $this->service->run();

        $this->assertSame(0, $totals['batches']);
        $this->assertSame(0, TelegramGroupInvite::query()->count());
        $this->assertNull(TelegramScrapedUser::query()->where('telegram_user_id', 701)->value('can_add_to_group'));

        $meta = $this->service->meta(TelegramAccount::query()->find(1));

        $this->assertSame(GroupInviteService::KIND_SPAM, $meta['rest_kind']);
        $this->assertSame(1, $meta['peer_floods']);
        $this->assertGreaterThan(now()->addHours(23)->timestamp, strtotime($meta['rest_until']));

        /* Resting, it is not taken by the next run. */
        $this->assertSame(['accounts' => 0, 'batches' => 0, 'invited' => 0, 'not_added' => 0, 'already' => 0, 'sent' => 0, 'failed' => 0], $this->service->run());
    }

    public function test_a_stop_keeps_what_the_account_did_before_it(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach ([1001, 1002, 1003] as $id) {
            $this->hold(1, $this->person($id, "P{$id}"));
        }

        $tries = 0;
        $this->fakeTelegram(invite: function (array $users) use (&$tries) {
            if (++$tries === 3) {
                throw RPCErrorException::make('PEER_FLOOD', 400, '');
            }

            return $this->added($users);
        });

        $settings = $this->app->make(GroupInviteSettings::class);
        $settings->save([...$settings->current(), 'batch_size' => 1]);

        $this->service->run();

        $stop = TelegramGroupInviteLog::query()->whereIn('event', TelegramGroupInviteLog::STOP_EVENTS)->sole();
        $counters = $stop->context['counters'];

        $this->assertSame(['flood', GroupInviteService::KIND_SPAM, 'PEER_FLOOD'], [$stop->event, $stop->context['kind'], $stop->context['rpc']]);
        $this->assertSame([2, 2, 0, 2], [$counters['calls'], $counters['invited'], $counters['failed'], $counters['calls_today']]);
        $this->assertSame([GroupInviteService::STEP_INVITE, TelegramGroupInvite::VIA_CONTACT, 1], [$counters['step'], $counters['via'], $counters['size']]);
        $this->assertSame(0, $counters['peer_floods_before']);
        $this->assertNull($counters['since']);
    }

    public function test_a_short_flood_wait_is_waited_out_on_the_spot(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $this->hold(1, $this->person(801, 'One'));

        $tries = 0;
        $calls = $this->fakeTelegram(invite: function (array $users) use (&$tries) {
            if (++$tries === 1) {
                throw new RateLimitError('FLOOD_WAIT_1', 1, 420, '');
            }

            return $this->added($users);
        });

        $totals = $this->service->run();

        $this->assertCount(2, $calls);
        $this->assertSame(1, $totals['invited']);
        $this->assertArrayNotHasKey('rest_until', $this->service->meta(TelegramAccount::query()->find(1)));
    }

    public function test_a_clean_answer_is_not_trusted_each_person_is_looked_up(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach ([1101 => 'Named', 1102 => 'Private', 1103 => 'Dropped', 1104 => 'Member', 1105 => 'Joined'] as $id => $name) {
            $this->hold(1, $this->person($id, $name));
        }

        $calls = $this->fakeTelegram(
            invite: fn (array $users) => [
                ...$this->added([1101]),
                'missing_invitees' => [['_' => 'missingInvitee', 'user_id' => 1102, 'premium_would_allow_invite' => false]],
            ],
            member: fn (int $id) => match ($id) {
                /* Telegram said nothing and left them out. */
                1103 => throw RPCErrorException::make('USER_NOT_PARTICIPANT', 400, ''),
                1104 => ['participant' => ['_' => 'channelParticipant', 'user_id' => $id, 'date' => now()->subDays(30)->timestamp]],
                /* In by the call, though no "added" message named them. */
                1105 => ['participant' => ['_' => 'channelParticipant', 'user_id' => $id, 'date' => now()->timestamp]],
            },
        );

        $totals = $this->service->run();

        $this->assertCount(1, $calls);
        $this->assertSame(['invited' => 2, 'not_added' => 1, 'already' => 1, 'sent' => 0, 'failed' => 1], array_intersect_key($totals, array_flip(['invited', 'not_added', 'already', 'sent', 'failed'])));

        /* Neither the one the message named nor the one Telegram listed as missing is looked up. */
        $this->assertSame([1103, 1104, 1105], $this->checks->getArrayCopy());

        $this->assertSame([
            1101 => [TelegramGroupInvite::STATUS_INVITED, null],
            1102 => [TelegramGroupInvite::STATUS_FAILED, 'missing_invitee'],
            1103 => [TelegramGroupInvite::STATUS_NOT_ADDED, GroupInviteService::NOT_IN_GROUP],
            1104 => [TelegramGroupInvite::STATUS_ALREADY, null],
            1105 => [TelegramGroupInvite::STATUS_INVITED, null],
        ], TelegramGroupInvite::query()->with('user')->get()
            ->mapWithKeys(fn ($row) => [$row->user->telegram_user_id => [$row->status, $row->error]])
            ->sortKeys()
            ->all());

        /* Junk is refused everywhere; the dropped one could still be added elsewhere. */
        $this->assertFalse(TelegramScrapedUser::query()->where('telegram_user_id', 1102)->value('can_add_to_group'));
        $this->assertNull(TelegramScrapedUser::query()->where('telegram_user_id', 1103)->value('can_add_to_group'));

        /* The member count before and after: the message added one. */
        $group->refresh();
        $this->assertSame([100, 101], [$group->members_before, $group->members_after]);

        $members = TelegramGroupInviteLog::query()->where('event', TelegramGroupInviteLog::EVENT_MEMBERS)->sole();
        $this->assertSame([100, 101, 1, 2, 1], [
            $members->context['before'],
            $members->context['after'],
            $members->context['grew'],
            $members->context['invited'],
            $members->context['not_added'],
        ]);
    }

    public function test_calls_that_add_nobody_rest_the_account_until_tomorrow(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach (range(1201, 1206) as $id) {
            $this->hold(1, $this->person($id, "P{$id}"));
        }

        /* Held quietly: every call "succeeds", nobody gets in. */
        $calls = $this->fakeTelegram(invite: fn () => ['missing_invitees' => []]);

        $settings = $this->app->make(GroupInviteSettings::class);
        $settings->save([...$settings->current(), 'batch_size' => 1]);

        $totals = $this->service->run();

        $this->assertCount(5, $calls);
        $this->assertSame([0, 5], [$totals['invited'], $totals['not_added']]);
        $this->assertSame(5, TelegramGroupInvite::query()->where('status', TelegramGroupInvite::STATUS_NOT_ADDED)->count());
        $this->assertSame('NOBODY_ADDED', $this->service->meta(TelegramAccount::query()->find(1))['rest_reason']);
    }

    public function test_left_the_group_is_not_added_here_and_not_refused_elsewhere(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $this->hold(1, $left = $this->person(1301, 'Left'));
        $this->hold(1, $member = $this->person(1302, 'Member'));

        $this->fakeTelegram(invite: function (array $users) {
            if (count($users) > 1) {
                throw RPCErrorException::make('USER_NOT_MUTUAL_CONTACT', 400, '');
            }

            throw RPCErrorException::make($users === [1301] ? 'USER_NOT_MUTUAL_CONTACT' : 'USER_ALREADY_PARTICIPANT', 400, '');
        });

        $this->service->run();

        $this->assertSame([TelegramGroupInvite::STATUS_NOT_ADDED, 'USER_NOT_MUTUAL_CONTACT'], [$this->inviteOf($left)->status, $this->inviteOf($left)->error]);
        $this->assertNull($left->fresh()->can_add_to_group);
        $this->assertSame(TelegramGroupInvite::STATUS_ALREADY, $this->inviteOf($member)->status);
    }

    public function test_people_sent_earlier_are_checked_and_nobody_is_invited(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $sentAt = now()->subHours(2);

        foreach ([1401, 1402, 1403, 1404] as $id) {
            TelegramGroupInvite::query()->create([
                'invite_group_id' => $group->id,
                'scraped_user_id' => $this->person($id, "P{$id}")->id,
                'telegram_account_id' => 1,
                'status' => TelegramGroupInvite::STATUS_SENT,
                'attempted_at' => $sentAt,
            ]);
        }

        /* An untried contact: a check invites nobody. */
        $this->hold(1, $this->person(1405, 'Untried'));

        $calls = $this->fakeTelegram(
            invite: fn (array $users) => $this->added($users),
            member: fn (int $id) => match ($id) {
                1401 => ['participant' => ['_' => 'channelParticipant', 'user_id' => $id, 'date' => $sentAt->timestamp + 5]],
                1402 => throw RPCErrorException::make('USER_NOT_PARTICIPANT', 400, ''),
                1403 => ['participant' => ['_' => 'channelParticipant', 'user_id' => $id, 'date' => now()->subDays(10)->timestamp]],
                1404 => ['participant' => ['_' => 'channelParticipantLeft', 'peer' => $id]],
            },
        );

        $this->service->run(checkOnly: true);

        $this->assertCount(0, $calls);
        $this->assertSame([1401, 1402, 1403, 1404], $this->checks->getArrayCopy());
        $this->assertSame(
            [1401 => 'invited', 1402 => 'not_added', 1403 => 'already', 1404 => 'not_added'],
            TelegramGroupInvite::query()->with('user')->where('status', '!=', TelegramGroupInvite::STATUS_PENDING)->get()
                ->mapWithKeys(fn ($row) => [$row->user->telegram_user_id => $row->status])
                ->sortKeys()
                ->all(),
        );
        $this->assertSame(1, TelegramGroupInviteLog::query()->where('event', TelegramGroupInviteLog::EVENT_CHECK)->count());
        /* The time of the call stays the call's. */
        $this->assertSame($sentAt->timestamp, TelegramGroupInvite::query()->whereHas('user', fn ($q) => $q->where('telegram_user_id', 1401))->first()->attempted_at->timestamp);
    }

    public function test_a_group_that_shows_its_members_to_admins_only_leaves_people_unchecked(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach ([1501, 1502, 1503] as $id) {
            $this->hold(1, $this->person($id, "P{$id}"));
        }

        $this->fakeTelegram(
            invite: fn () => ['missing_invitees' => []],
            member: fn () => throw RPCErrorException::make('CHAT_ADMIN_REQUIRED', 400, ''),
        );

        $totals = $this->service->run();

        /* Looked up once; the others are not tried in this run. */
        $this->assertCount(1, $this->checks);
        $this->assertSame(3, $totals['sent']);
        $this->assertSame(
            ['CHECK_CHAT_ADMIN_REQUIRED'],
            TelegramGroupInvite::query()->where('status', TelegramGroupInvite::STATUS_SENT)->distinct()->pluck('error')->all(),
        );
    }

    public function test_a_stop_from_the_panel_ends_the_run_and_gives_the_people_back(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach ([1601, 1602, 1603] as $id) {
            $this->hold(1, $this->person($id, "P{$id}"));
        }

        /* An old stop is not this run's. */
        GroupInviteService::requestStop('*');

        $calls = $this->fakeTelegram(invite: function (array $users) {
            /* "Stop" pressed while the first call is out; the run looks a few seconds later. */
            GroupInviteService::requestStop('*');
            $this->travel(3)->seconds();

            return $this->added($users);
        });

        $settings = $this->app->make(GroupInviteSettings::class);
        $settings->save([...$settings->current(), 'batch_size' => 1]);

        $totals = $this->service->run();

        $this->assertCount(1, $calls);
        $this->assertSame(1, $totals['invited']);
        /* Nobody else is held: the next run takes them. */
        $this->assertSame(1, TelegramGroupInvite::query()->count());

        $finished = TelegramGroupInviteLog::query()->where('event', 'finished')->sole();
        $this->assertTrue($finished->context['stopped']);

        $now = TelegramSetting::get(GroupInviteService::NOW_KEY);
        $this->assertSame(['finished', true, 1], [$now['doing'], $now['stopped'], $now['totals']['invited']]);
        $this->assertNotNull($now['finished_at']);
    }

    public function test_the_run_says_what_it_does_and_checks_people_a_part_at_a_time(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);

        foreach (range(1701, 1725) as $id) {
            TelegramGroupInvite::query()->create([
                'invite_group_id' => $group->id,
                'scraped_user_id' => $this->person($id, "P{$id}")->id,
                'telegram_account_id' => 1,
                'status' => TelegramGroupInvite::STATUS_SENT,
                'attempted_at' => now()->subHour(),
            ]);
        }

        $seen = [];

        $this->fakeTelegram(
            invite: fn (array $users) => $this->added($users),
            member: function (int $id) use (&$seen) {
                /* While the check goes on, the page can read how far it is. */
                $seen[] = TelegramSetting::get(GroupInviteService::NOW_KEY);

                throw RPCErrorException::make('USER_NOT_PARTICIPANT', 400, '');
            },
        );

        $this->service->run(checkOnly: true);

        $this->assertSame(['checking_sent', 0, 25, '+998900000001', 'Drivers'], [
            $seen[0]['doing'],
            $seen[0]['step']['done'],
            $seen[0]['step']['size'],
            $seen[0]['account'],
            $seen[0]['group'],
        ]);
        $this->assertSame(24, end($seen)['step']['done']);

        /* Written 20, then 5: each part shows on the page as soon as it is checked. */
        $this->assertSame(
            [[20, 20, 25], [5, 25, 25]],
            TelegramGroupInviteLog::query()->where('event', TelegramGroupInviteLog::EVENT_CHECK)->orderBy('id')->get()
                ->map(fn ($log) => [$log->context['size'], $log->context['done'], $log->context['of']])
                ->all(),
        );
    }

    public function test_the_log_of_the_latest_run_is_read_with_the_runs_to_pick_from(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'is_active' => true]);
        $this->hold(1, $this->person(1801, 'One'));

        $this->fakeTelegram(invite: fn (array $users) => $this->added($users));

        $this->service->run();
        $this->service->run(checkOnly: true);

        $json = $this->app->make(GroupInviteController::class)->logs(request())->getData(true);

        $this->assertCount(2, $json['runs']);
        $this->assertTrue($json['runs'][0]['check_only']);
        $this->assertSame($json['runs'][0]['run_id'], $json['run_id']);
        $this->assertSame(['finished', 'skipped', 'started'], array_column($json['lines'], 'event'));

        $first = $this->app->make(GroupInviteController::class)
            ->logs(request()->merge(['run_id' => $json['runs'][1]['run_id']]))
            ->getData(true);

        $batch = collect($first['lines'])->firstWhere('event', TelegramGroupInviteLog::EVENT_CALL);
        /* The join told the group's title. */
        $this->assertSame(['+998900000001', 'Drivers', 1], [$batch['account'], $batch['group'], $batch['context']['invited']]);
    }

    public function test_the_page_shows_how_each_account_stands_and_resume_clears_it(): void
    {
        $account = TelegramAccount::query()->find(1);
        $config = $this->app->make(GroupInviteSettings::class)->current();

        $this->service->spamLimited($account, $config, 'PEER_FLOOD');
        $this->person(901, 'Private')->update(['can_add_to_group' => false]);

        $controller = $this->app->make(GroupInviteController::class);
        $json = $controller->show()->getData(true);
        $row = collect($json['accounts'])->firstWhere('id', 1);

        $this->assertSame(GroupInviteService::KIND_SPAM, $row['rest_kind']);
        $this->assertNotNull($row['rest_until']);
        $this->assertTrue($row['cap_lowered']);
        $this->assertSame(40, $row['cap']);
        $this->assertSame(1, $json['totals']['refused']);
        $this->assertArrayHasKey('use_usernames', $json['settings']);

        $controller->enableAccount($account);
        $row = collect($controller->show()->getData(true)['accounts'])->firstWhere('id', 1);

        $this->assertNull($row['rest_until']);
        $this->assertFalse($row['cap_lowered']);
    }

    public function test_a_link_is_stored_once_without_the_host(): void
    {
        $this->assertSame('drivers', TelegramInviteGroup::normalizeLink('https://t.me/drivers'));
        $this->assertSame('+AbCd', TelegramInviteGroup::normalizeLink('https://t.me/+AbCd'));

        $group = TelegramInviteGroup::query()->create([
            'link' => TelegramInviteGroup::normalizeLink('@drivers'),
        ]);

        $again = TelegramInviteGroup::query()->firstOrCreate([
            'link' => TelegramInviteGroup::normalizeLink('t.me/drivers'),
        ]);

        $this->assertSame($group->id, $again->id);
        $this->assertSame(1, TelegramInviteGroup::query()->count());
    }

    private function person(int $telegramId, string $name, bool $bot = false, ?string $username = null): TelegramScrapedUser
    {
        return TelegramScrapedUser::query()->create([
            'telegram_user_id' => $telegramId,
            'first_name' => $name,
            'username' => $username,
            'is_bot' => $bot,
        ]);
    }

    private function hold(int $accountId, TelegramScrapedUser $user): void
    {
        TelegramAccountContact::query()->create([
            'telegram_account_id' => $accountId,
            'scraped_user_id' => $user->id,
            'status' => TelegramAccountContact::STATUS_ADDED,
            'added_at' => now(),
        ]);
    }

    /**
     * What Telegram answers when it added these people: the "added" service
     * message names them.
     *
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
                    'message' => [
                        '_' => 'messageService',
                        'action' => ['_' => 'messageActionChatAddUser', 'users' => $ids],
                    ],
                ]],
            ],
            'missing_invitees' => [],
        ];
    }

    /**
     * A session stand-in: the class is final, but its namespaces are plain
     * properties. Joining always works; the invite, the @username lookups
     * and the member checks answer as the test says - nobody is a member
     * unless $member says so. The group has 100 members, and every one the
     * "added" message names. No pauses between calls.
     *
     * @return ArrayObject<int, list<int>> the users of every invite call; $this->checks holds every person looked up
     */
    private function fakeTelegram(callable $invite, ?callable $resolve = null, ?callable $member = null): ArrayObject
    {
        $calls = new ArrayObject;
        $this->checks = new ArrayObject;

        $api = (new ReflectionClass(API::class))->newInstanceWithoutConstructor();

        $api->channels = new class($invite, $calls, $this->checks, $member)
        {
            private int $members = 100;

            public function __construct(private $invite, private ArrayObject $calls, private ArrayObject $checks, private $member) {}

            public function joinChannel(mixed ...$args): array
            {
                return ['chats' => [['_' => 'channel', 'id' => 777, 'title' => 'Drivers']]];
            }

            public function inviteToChannel(mixed ...$args): mixed
            {
                $this->calls[] = $args['users'];

                $answer = ($this->invite)($args['users']);

                foreach ((array) ($answer['updates']['updates'] ?? []) as $update) {
                    $this->members += count((array) ($update['message']['action']['users'] ?? []));
                }

                return $answer;
            }

            public function getParticipant(mixed ...$args): mixed
            {
                $this->checks[] = $args['participant'];

                if ($this->member === null) {
                    throw RPCErrorException::make('USER_NOT_PARTICIPANT', 400, '');
                }

                return ($this->member)($args['participant']);
            }

            public function getParticipants(mixed ...$args): array
            {
                return ['count' => $this->members, 'participants' => []];
            }
        };

        $this->app->instance(UsernameLookup::class, new class($resolve) extends UsernameLookup
        {
            public function __construct(private $resolve) {}

            public function user(API $api, string $username): ?array
            {
                return ($this->resolve)($username);
            }
        });

        $madeline = Mockery::mock(MadelineService::class);
        $madeline->shouldReceive('for')->andReturn($api);
        $this->app->instance(MadelineService::class, $madeline);

        $this->app->make(GroupInviteSettings::class)->save([
            ...$this->app->make(GroupInviteSettings::class)->current(),
            'delay_min' => 0,
            'delay_max' => 0,
        ]);

        $this->service = $this->app->make(GroupInviteService::class);

        return $calls;
    }

    private function inviteOf(TelegramScrapedUser $user): TelegramGroupInvite
    {
        return TelegramGroupInvite::query()->where('scraped_user_id', $user->id)->firstOrFail();
    }
}
