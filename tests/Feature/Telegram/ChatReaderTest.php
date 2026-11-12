<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\ChatReader;
use App\Application\Telegram\Services\ChatReadQueue;
use App\Application\Telegram\Services\DutyOwners;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramReadRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What the account's API says, by method: every call is kept, the answer is
 * whatever the test put under the method's name.
 */
final class FakeReaderMethods
{
    /** @var array<string, mixed> callable|array */
    public array $answers = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    public function __call(string $method, array $arguments): mixed
    {
        $params = (array) ($arguments[0] ?? []);

        $this->calls[] = [$method, $params];

        $answer = $this->answers[$method] ?? [];

        return is_callable($answer) ? $answer($params) : $answer;
    }
}

final class FakeReaderApi
{
    public FakeReaderMethods $messages;

    public FakeReaderMethods $channels;

    public FakeReaderMethods $own;

    public function __construct()
    {
        $this->messages = new FakeReaderMethods();
        $this->channels = new FakeReaderMethods();
        $this->own = new FakeReaderMethods();
    }

    public function getInfo(mixed $peer): array
    {
        return (array) $this->own->getInfo(['peer' => $peer]);
    }

    public function getFullInfo(mixed $peer): array
    {
        return (array) $this->own->getFullInfo(['peer' => $peer]);
    }
}

/**
 * The developer's chat reader: the token, the queue the process that holds
 * the session answers, and what is read.
 */
class ChatReaderTest extends TestCase
{
    private const TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    private const DRIVER_CHECK = 1;

    private const HEAD_SALES = 2;

    private FakeReaderApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['telegram_read_requests', 'telegram_listeners', 'telegram_accounts', 'telegram_settings', 'operation_users', 'telegram_client_checks'] as $table) {
            Schema::dropIfExists($table);
        }

        (require base_path('database/migrations/2026_11_12_100000_create_telegram_read_requests_table.php'))->up();

        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->string('session_path')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_listeners', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('duties')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('operation_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('role', 16)->default('operation');
            $table->string('language', 5)->nullable();
            $table->boolean('respectful')->default(false);
            $table->json('address')->nullable();
            $table->json('personal_answers')->nullable();
            $table->string('telegram_username')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dm_enabled')->default(true);
            $table->boolean('penalties_enabled')->default(true);
            $table->boolean('penalty_comments_enabled')->default(true);
            $table->boolean('greet_first_enabled')->default(true);
            $table->boolean('nudge_enabled')->default(true);
            $table->boolean('auto_replies_enabled')->default(true);
            $table->timestamp('dm_last_sent_at')->nullable();
            $table->text('dm_last_error')->nullable();
            $table->timestamp('last_private_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_client_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->timestamps();
        });

        foreach ([self::DRIVER_CHECK, self::HEAD_SALES, 3] as $id) {
            DB::table('telegram_accounts')->insert([
                'id' => $id,
                'phone' => '+99890000000' . $id,
                'first_name' => 'Account',
                'last_name' => (string) $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        TelegramListener::query()->create([
            'key' => 'head_sales',
            'name' => 'Head of sales',
            'telegram_account_id' => self::HEAD_SALES,
            'is_enabled' => true,
            'duties' => [],
        ]);

        config()->set('services.telegram.driver_check_account_id', self::DRIVER_CHECK);
        config()->set('services.telegram.reader_token', self::TOKEN);
        config()->set('app.timezone', 'Asia/Tashkent');

        Cache::flush();

        $this->api = new FakeReaderApi();
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN];
    }

    private function serve(int $account = self::DRIVER_CHECK): void
    {
        app(ChatReader::class)->serve($this->api, $account);
    }

    private function when(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, 'Asia/Tashkent')->format('Y-m-d H:i:s');
    }

    /*
    |--------------------------------------------------------------------------
    | The door
    |--------------------------------------------------------------------------
    */

    public function test_the_reader_answers_only_to_its_own_token(): void
    {
        $url = '/api/telegram/reader/accounts';

        config()->set('services.telegram.reader_token', null);
        $this->getJson($url, $this->auth())->assertNotFound();

        config()->set('services.telegram.reader_token', 'short');
        $this->getJson($url, ['Authorization' => 'Bearer short'])->assertNotFound();

        config()->set('services.telegram.reader_token', self::TOKEN);
        $this->getJson($url)->assertNotFound();
        $this->getJson($url, ['Authorization' => 'Bearer ' . strrev(self::TOKEN)])->assertNotFound();

        /* The report token opens nothing here. */
        config()->set('services.telegram.report_token', str_repeat('a1', 20));
        $this->getJson($url, ['Authorization' => 'Bearer ' . str_repeat('a1', 20)])->assertNotFound();
        $this->getJson('/api/telegram/reader/history?account=1&chat=1')->assertNotFound();

        $this->getJson($url, $this->auth())->assertOk();
        $this->getJson($url, ['X-Reader-Token' => self::TOKEN])->assertOk();
    }

    public function test_the_accounts_are_those_a_process_holds_and_show_no_phone(): void
    {
        $response = $this->getJson('/api/telegram/reader/accounts', $this->auth())->assertOk();

        $this->assertStringNotContainsString('998900000', $response->getContent());

        $accounts = collect($response->json('accounts'))->keyBy('id');

        $this->assertSame([self::DRIVER_CHECK, self::HEAD_SALES], $accounts->keys()->all());
        $this->assertSame('driver_check', $accounts[self::DRIVER_CHECK]['held_by']);
        $this->assertSame('listener:head_sales', $accounts[self::HEAD_SALES]['held_by']);
    }

    public function test_a_read_is_asked_checked_and_answered_by_the_process_that_holds_the_account(): void
    {
        $this->getJson('/api/telegram/reader/history?account=1', $this->auth())->assertUnprocessable();

        /* An invite link would be joined: never. */
        $this->getJson('/api/telegram/reader/history?account=1&chat=' . urlencode('https://t.me/+abcdef'), $this->auth())->assertUnprocessable();
        $this->getJson('/api/telegram/reader/history?account=1&chat=1&limit=5000', $this->auth())->assertUnprocessable();

        /* Not held by anybody. */
        $this->getJson('/api/telegram/reader/history?account=3&chat=1', $this->auth())->assertUnprocessable();
        $this->getJson('/api/telegram/reader/nothing?account=1', $this->auth())->assertNotFound();

        $this->assertSame(0, TelegramReadRequest::query()->count());

        $this->api->messages->answers['getHistory'] = ['messages' => [], 'users' => [], 'chats' => []];

        $asked = $this->getJson('/api/telegram/reader/history?account=1&chat=-1001&wait=0', $this->auth())->assertStatus(202);

        $id = $asked->json('request_id');

        $row = TelegramReadRequest::query()->findOrFail($id);
        $this->assertSame(['chat' => '-1001'], $row->params);
        $this->assertSame(TelegramReadRequest::STATUS_PENDING, $row->status);

        /* Another account's process does not take it. */
        $this->serve(self::HEAD_SALES);
        $this->assertSame(TelegramReadRequest::STATUS_PENDING, $row->fresh()->status);

        $this->serve();

        $answer = $this->getJson("/api/telegram/reader/requests/{$id}", $this->auth())->assertOk();

        $this->assertSame('done', $answer->json('status'));
        $this->assertSame(0, $answer->json('count'));
        $this->assertTrue($answer->json('reached_start'));

        /* Taken once. */
        $this->serve();
        $this->assertCount(1, $this->api->messages->calls);
    }

    public function test_a_failed_read_says_why_and_a_forgotten_one_is_given_up(): void
    {
        $this->api->messages->answers['getHistory'] = static fn () => throw new \RuntimeException('CHANNEL_PRIVATE');

        $id = $this->getJson('/api/telegram/reader/history?account=1&chat=-1001&wait=0', $this->auth())->json('request_id');

        $this->serve();

        $this->getJson("/api/telegram/reader/requests/{$id}", $this->auth())
            ->assertStatus(502)
            ->assertJsonPath('error', 'CHANNEL_PRIVATE');

        /* Nobody holds the account any more (or it was not restarted): the read is not left waiting for ever. */
        $stale = $this->getJson('/api/telegram/reader/dialogs?account=1&wait=0', $this->auth())->json('request_id');

        Carbon::setTestNow(now()->addSeconds(ChatReadQueue::STALE_PENDING_SECONDS + 1));

        $this->getJson("/api/telegram/reader/requests/{$stale}", $this->auth())
            ->assertStatus(502)
            ->assertJsonPath('status', 'failed');

        Carbon::setTestNow();
    }

    public function test_what_was_read_is_not_kept_but_the_audit_row_is(): void
    {
        $this->api->messages->answers['getHistory'] = ['messages' => [], 'users' => [], 'chats' => []];

        $id = $this->getJson('/api/telegram/reader/history?account=1&chat=-1001&wait=0', $this->auth())->json('request_id');
        $this->serve();

        $this->assertNotNull(TelegramReadRequest::query()->find($id)->result);

        Carbon::setTestNow(now()->addMinutes(ChatReadQueue::KEEP_RESULT_MINUTES + 1));
        Cache::flush();

        app(ChatReadQueue::class)->prune();

        $row = TelegramReadRequest::query()->find($id);
        $this->assertNull($row->result);
        $this->assertSame('history', $row->op);

        $this->getJson("/api/telegram/reader/requests/{$id}", $this->auth())->assertStatus(410);

        /* Two weeks on, the row goes too. */
        Carbon::setTestNow(now()->addDays(ChatReadQueue::KEEP_ROWS_DAYS + 1));
        Cache::flush();

        app(ChatReadQueue::class)->prune();

        $this->assertNull(TelegramReadRequest::query()->find($id));

        Carbon::setTestNow();
    }

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    private function person(string $name, string $role, array $attributes = []): OperationUser
    {
        return OperationUser::query()->create([
            'name' => $name,
            'name_normalized' => OperationUser::normalizeName($name),
            'role' => $role,
            'dm_enabled' => true,
            ...$attributes,
        ]);
    }

    public function test_the_people_come_with_the_account_that_writes_to_them_and_their_chat_is_read_through_it(): void
    {
        app(DutyOwners::class)->saveRoles(['sales' => 'head_sales']);

        $operator = $this->person('PULATOV AFZAL', 'operation', ['telegram_username' => 'afzal', 'telegram_id' => 555]);
        $sales = $this->person('RAJABOVA MAKHBUBA', 'sales', ['telegram_id' => 777]);
        $nobody = $this->person('NO TELEGRAM', 'sales');

        $people = collect($this->getJson('/api/telegram/reader/people', $this->auth())->assertOk()->json('people'))->keyBy('id');

        $this->assertSame(self::DRIVER_CHECK, $people[$operator->id]['account_id']);
        $this->assertSame(self::HEAD_SALES, $people[$sales->id]['account_id']);
        $this->assertFalse($people[$nobody->id]['reachable']);

        $this->assertSame(
            [$nobody->id, $sales->id],
            collect($this->getJson('/api/telegram/reader/people?role=sales', $this->auth())->json('people'))->pluck('id')->all(),
        );

        $this->api->messages->answers['getHistory'] = ['messages' => [], 'users' => [], 'chats' => []];

        $this->getJson("/api/telegram/reader/people/{$sales->id}/history?wait=0", $this->auth())->assertStatus(202);
        $this->getJson("/api/telegram/reader/people/{$operator->id}/history?wait=0", $this->auth())->assertStatus(202);
        $this->getJson("/api/telegram/reader/people/{$nobody->id}/history?wait=0", $this->auth())->assertUnprocessable();

        $rows = TelegramReadRequest::query()->orderBy('id')->get();

        $this->assertSame([self::HEAD_SALES, '777'], [$rows[0]->telegram_account_id, $rows[0]->params['chat']]);
        $this->assertSame([self::DRIVER_CHECK, '555'], [$rows[1]->telegram_account_id, $rows[1]->params['chat']]);
    }

    /*
    |--------------------------------------------------------------------------
    | What is read
    |--------------------------------------------------------------------------
    */

    private function afzal(): array
    {
        return ['id' => 555, 'first_name' => 'Afzal', 'last_name' => 'Pulatov', 'username' => 'afzal', 'phone' => '998901112233'];
    }

    public function test_the_dialogs_are_told_by_kind_title_and_last_message(): void
    {
        $this->api->messages->answers['getDialogs'] = [
            '_' => 'messages.dialogs',
            'dialogs' => [
                ['peer' => ['_' => 'peerUser', 'user_id' => 555], 'top_message' => 10, 'unread_count' => 2],
                ['peer' => ['_' => 'peerChannel', 'channel_id' => 777], 'top_message' => 20, 'unread_count' => 0],
            ],
            'messages' => [
                ['_' => 'message', 'id' => 10, 'peer_id' => ['_' => 'peerUser', 'user_id' => 555], 'from_id' => ['_' => 'peerUser', 'user_id' => 555], 'date' => 1793000000, 'message' => 'Salom', 'out' => false],
                ['_' => 'message', 'id' => 20, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 777], 'from_id' => ['_' => 'peerUser', 'user_id' => 555], 'date' => 1793000100, 'message' => 'Reklama', 'out' => false],
            ],
            'chats' => [['id' => 777, 'title' => 'Drivers', 'megagroup' => true, 'participants_count' => 120]],
            'users' => [$this->afzal()],
        ];

        $all = app(ChatReader::class)->perform($this->api, 'dialogs', []);

        $this->assertSame(2, $all['count']);
        $this->assertSame(
            ['id' => 555, 'kind' => 'private', 'title' => 'Afzal Pulatov', 'username' => 'afzal', 'members' => null, 'unread' => 2, 'archived' => false],
            collect($all['dialogs'][0])->except('last')->all(),
        );
        $this->assertSame(['id' => 10, 'date' => $this->when(1793000000), 'out' => false, 'from' => 'Afzal Pulatov', 'text' => 'Salom'], $all['dialogs'][0]['last']);

        $this->assertSame(-1000000000777, $all['dialogs'][1]['id']);
        $this->assertSame(['group', 'Drivers', 120], [$all['dialogs'][1]['kind'], $all['dialogs'][1]['title'], $all['dialogs'][1]['members']]);

        $groups = app(ChatReader::class)->perform($this->api, 'dialogs', ['kind' => 'group']);
        $this->assertSame([-1000000000777], array_column($groups['dialogs'], 'id'));

        $unread = app(ChatReader::class)->perform($this->api, 'dialogs', ['unread' => true]);
        $this->assertSame([555], array_column($unread['dialogs'], 'id'));

        $found = app(ChatReader::class)->perform($this->api, 'dialogs', ['q' => 'driv']);
        $this->assertSame([-1000000000777], array_column($found['dialogs'], 'id'));
    }

    public function test_a_history_is_read_oldest_first_with_who_said_what(): void
    {
        $peer = ['_' => 'peerUser', 'user_id' => 555];

        $this->api->messages->answers['getHistory'] = [
            'messages' => [
                ['_' => 'message', 'id' => 12, 'peer_id' => $peer, 'date' => 1793000300, 'message' => 'Qildim', 'out' => true, 'reply_to' => ['reply_to_msg_id' => 11], 'edit_date' => 1793000400],
                ['_' => 'message', 'id' => 11, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000200, 'message' => '', 'out' => false, 'media' => ['_' => 'messageMediaDocument', 'document' => ['size' => 4096, 'attributes' => [['_' => 'documentAttributeAudio', 'voice' => true, 'duration' => 7]]]]],
                ['_' => 'message', 'id' => 10, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000100, 'message' => 'Zayavka #TLS04834', 'out' => false, 'fwd_from' => ['from_name' => 'CRM bot']],
            ],
            'users' => [$this->afzal()],
            'chats' => [],
        ];

        $result = app(ChatReader::class)->perform($this->api, 'history', ['chat' => '555', 'limit' => 3]);

        $this->assertSame(['id' => 555, 'kind' => 'private', 'title' => 'Afzal Pulatov', 'username' => 'afzal', 'members' => null], $result['chat']);
        $this->assertSame([10, 11, 12], array_column($result['messages'], 'id'));
        $this->assertSame(10, $result['next_before_id']);
        $this->assertFalse($result['reached_start']);

        [$first, $voice, $mine] = $result['messages'];

        $this->assertSame('Afzal Pulatov', $first['from']);
        $this->assertSame('CRM bot', $first['forwarded_from']);
        $this->assertSame(['type' => 'voice', 'seconds' => 7, 'bytes' => 4096], $voice['media']);
        $this->assertSame(['me', true, 11, $this->when(1793000400)], [$mine['from'], $mine['out'], $mine['reply_to'], $mine['edited']]);

        $this->assertSame(555, $this->api->messages->calls[0][1]['peer']);
        $this->assertSame(0, $this->api->messages->calls[0][1]['offset_id']);

        $newestFirst = app(ChatReader::class)->perform($this->api, 'history', ['chat' => '555', 'limit' => 3, 'order' => 'desc']);
        $this->assertSame([12, 11, 10], array_column($newestFirst['messages'], 'id'));

        /* Older than what was read: from its id. */
        app(ChatReader::class)->perform($this->api, 'history', ['chat' => '@afzal', 'limit' => 3, 'before_id' => 10]);
        $this->assertSame(['@afzal', 10], [$this->api->messages->calls[2][1]['peer'], $this->api->messages->calls[2][1]['offset_id']]);
    }

    public function test_a_history_stops_at_the_date_asked_for_and_at_the_chats_start(): void
    {
        $peer = ['_' => 'peerUser', 'user_id' => 555];

        $this->api->messages->answers['getHistory'] = [
            'messages' => [
                ['_' => 'message', 'id' => 3, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000300, 'message' => 'new'],
                ['_' => 'message', 'id' => 2, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000200, 'message' => 'new too'],
                ['_' => 'message', 'id' => 1, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000100, 'message' => 'old'],
            ],
            'users' => [$this->afzal()],
            'chats' => [],
        ];

        $result = app(ChatReader::class)->perform($this->api, 'history', ['chat' => '555', 'limit' => 50, 'min_date' => 1793000150]);

        $this->assertSame([2, 3], array_column($result['messages'], 'id'));
        $this->assertTrue($result['reached_start']);
        $this->assertNull($result['next_before_id']);

        /* Fewer than asked for: the chat has no more. */
        $all = app(ChatReader::class)->perform($this->api, 'history', ['chat' => '555', 'limit' => 50]);
        $this->assertSame([1, 2, 3], array_column($all['messages'], 'id'));
        $this->assertTrue($all['reached_start']);

        /* Up to a date: Telegram is asked for what is older than the day after. */
        app(ChatReader::class)->perform($this->api, 'history', ['chat' => '555', 'limit' => 50, 'max_date' => 1793000250]);
        $this->assertSame(1793000251, collect($this->api->messages->calls)->last()[1]['offset_date']);
    }

    public function test_a_search_reads_one_chat_or_all_of_them(): void
    {
        $peer = ['_' => 'peerUser', 'user_id' => 555];

        $this->api->messages->answers['search'] = $this->api->messages->answers['searchGlobal'] = [
            'count' => 9,
            'messages' => [['_' => 'message', 'id' => 5, 'peer_id' => $peer, 'from_id' => $peer, 'date' => 1793000100, 'message' => 'narx qancha']],
            'users' => [$this->afzal()],
            'chats' => [],
        ];

        $inChat = app(ChatReader::class)->perform($this->api, 'search', ['q' => 'narx', 'chat' => '555', 'from' => '555']);

        $this->assertSame([1, 9, 5], [$inChat['count'], $inChat['total'], $inChat['messages'][0]['id']]);
        $this->assertSame('Afzal Pulatov', $inChat['messages'][0]['chat']);
        $this->assertSame([555, 555, 'narx'], [$this->api->messages->calls[0][1]['peer'], $this->api->messages->calls[0][1]['from_id'], $this->api->messages->calls[0][1]['q']]);

        $everywhere = app(ChatReader::class)->perform($this->api, 'search', ['q' => 'narx']);

        $this->assertSame('searchGlobal', $this->api->messages->calls[1][0]);
        $this->assertSame(555, $everywhere['messages'][0]['chat_id']);
    }

    public function test_a_group_tells_its_people_and_roles_and_a_private_chat_has_none(): void
    {
        $this->api->own->answers['getInfo'] = ['type' => 'supergroup', 'bot_api_id' => -1000000000777, 'channel_id' => 777];
        $this->api->channels->answers['getParticipants'] = [
            'count' => 2,
            'participants' => [
                ['_' => 'channelParticipantCreator', 'user_id' => 555, 'rank' => 'boss'],
                ['_' => 'channelParticipant', 'user_id' => 556, 'date' => 1793000100, 'inviter_id' => 555],
            ],
            'users' => [$this->afzal(), ['id' => 556, 'first_name' => 'Ali', 'bot' => true]],
        ];

        $result = app(ChatReader::class)->perform($this->api, 'members', ['chat' => '-1000000000777']);

        $this->assertSame([2, 2, false], [$result['total'], $result['count'], $result['more']]);
        $this->assertSame(['creator', 'boss', 'Afzal Pulatov'], [$result['members'][0]['role'], $result['members'][0]['rank'], $result['members'][0]['name']]);
        $this->assertSame(['member', true, 555], [$result['members'][1]['role'], $result['members'][1]['bot'], $result['members'][1]['invited_by']]);
        $this->assertStringNotContainsString('998901112233', json_encode($result));

        $this->api->own->answers['getInfo'] = ['type' => 'user', 'bot_api_id' => 555, 'User' => $this->afzal()];

        $this->expectException(InvalidArgumentException::class);

        app(ChatReader::class)->perform($this->api, 'members', ['chat' => '555']);
    }

    public function test_a_person_is_told_without_the_phone(): void
    {
        $this->api->own->answers['getInfo'] = [
            'type' => 'user',
            'bot_api_id' => 555,
            'User' => $this->afzal() + ['status' => ['_' => 'userStatusOffline', 'was_online' => 1793000000]],
        ];
        $this->api->own->answers['getFullInfo'] = ['full' => ['about' => 'Operator']];

        $result = app(ChatReader::class)->perform($this->api, 'user', ['user' => '@afzal']);

        $this->assertSame(['id' => 555, 'name' => 'Afzal Pulatov', 'username' => 'afzal', 'bot' => false, 'deleted' => false, 'bio' => 'Operator', 'last_seen' => $this->when(1793000000)], $result);
        $this->assertStringNotContainsString('998901112233', json_encode($result));

        $chat = app(ChatReader::class)->perform($this->api, 'chat', ['chat' => '555']);
        $this->assertSame([555, 'private', 'Afzal Pulatov'], [$chat['id'], $chat['kind'], $chat['title']]);
        $this->assertSame('Operator', $chat['about']);
    }

    public function test_a_chat_that_is_not_an_id_or_a_username_is_refused_even_past_the_api(): void
    {
        $id = TelegramReadRequest::query()->create([
            'telegram_account_id' => self::DRIVER_CHECK,
            'op' => 'history',
            'params' => ['chat' => 'https://t.me/+abcdef'],
            'status' => 'pending',
        ])->id;

        $this->serve();

        $row = TelegramReadRequest::query()->find($id);

        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('an id, "me" or an @username', (string) $row->error);
        $this->assertSame([], $this->api->messages->calls);
    }
}
