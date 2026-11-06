<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\JoinBotReporter;
use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\JoinRequestService;
use App\Http\Controllers\Api\Telegram\GroupInviteBotController;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramJoinRequest;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Our join request bot: it approves whoever asks, writes people into the
 * base and their line into our group's history, lets our own accounts in
 * without writing them, and marks an update read only once it is handled.
 * Telegram is faked at the HTTP level.
 */
final class JoinRequestBotTest extends TestCase
{
    private const TOKEN = '123456789:AAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->boolean('is_authorized')->default(true);
            $table->timestamps();
        });

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_10_14_100000_create_telegram_contact_sync_tables.php'))->up();
        (include database_path('migrations/2026_10_29_120000_create_telegram_group_invite_tables.php'))->up();
        (include database_path('migrations/2026_10_29_140000_add_invite_flags_to_telegram_tables.php'))->up();
        (include database_path('migrations/2026_10_29_160000_check_telegram_group_invites.php'))->up();
        (include database_path('migrations/2026_10_30_100000_keep_telegram_group_invite_history.php'))->up();
        (include database_path('migrations/2026_11_02_110000_create_telegram_join_requests_table.php'))->up();
        (include database_path('migrations/2026_11_03_100000_create_telegram_spam_tables.php'))->up();
        (include database_path('migrations/2026_11_06_100000_add_reason_to_telegram_spam_messages.php'))->up();
        (include database_path('migrations/2026_11_06_110000_create_telegram_spam_admins_table.php'))->up();

        DB::table('telegram_accounts')->insert(['id' => 1, 'phone' => '+998900000001', 'telegram_user_id' => 9001]);
    }

    public function test_a_token_of_a_bot_we_already_have_or_with_a_webhook_is_refused(): void
    {
        config(['telegram.bots.mybot.token' => self::TOKEN]);

        $this->expectExceptionMessage('taken');
        $this->app->make(JoinRequestService::class)->connect(self::TOKEN);
    }

    public function test_a_bot_with_a_webhook_is_left_alone(): void
    {
        $this->fakeTelegram(webhook: 'https://gateway.example/hook');

        try {
            $this->app->make(JoinRequestService::class)->connect(self::TOKEN);
            $this->fail('A webhook belongs to another program.');
        } catch (\DomainException $e) {
            $this->assertSame('webhook', $e->getMessage());
        }

        $this->assertNull($this->app->make(JoinBotSettings::class)->token());
    }

    public function test_a_token_is_saved_with_the_bot_and_shown_masked(): void
    {
        $this->fakeTelegram();

        $bot = $this->app->make(JoinRequestService::class)->connect(self::TOKEN);

        $this->assertSame(['id' => 555, 'username' => 'our_join_bot', 'name' => 'Join'], $bot);
        $this->assertTrue($this->app->make(JoinBotSettings::class)->enabled());
        $this->assertSame('123456789:••••bbbb', JoinBotSettings::mask(self::TOKEN));
    }

    public function test_every_request_is_approved_people_go_into_the_base_our_accounts_do_not(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        $known = TelegramScrapedUser::query()->create(['telegram_user_id' => 502, 'first_name' => 'Old', 'username' => 'old_name']);
        $this->connect();

        $this->fakeTelegram(updates: [
            $this->request(10, -1001234, ['id' => 501, 'first_name' => 'Ali', 'last_name' => 'Valiyev', 'username' => 'ali']),
            $this->request(11, -1001234, ['id' => 502, 'first_name' => 'Vali']),
            $this->request(12, -1001234, ['id' => 9001, 'first_name' => 'Us']),
            /* Not one of our groups: approved, the person is still written. */
            $this->request(13, -1009999, ['id' => 503, 'first_name' => 'Elsewhere']),
        ]);

        $totals = $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(['approved' => 4, 'failed' => 0, 'ours' => 1, 'new' => 2], $totals);
        $this->assertSame([501, 502, 9001, 503], collect($this->sent)->where('method', 'approveChatJoinRequest')->pluck('params.user_id')->all());

        /* New and known people are in the base, with their names as they are now; our account is not. */
        $this->assertSame('Valiyev', TelegramScrapedUser::query()->where('telegram_user_id', 501)->value('last_name'));
        $this->assertSame(['Vali', null], [$known->fresh()->first_name, $known->fresh()->username]);
        $this->assertFalse(TelegramScrapedUser::query()->where('telegram_user_id', 9001)->exists());
        $this->assertTrue(TelegramScrapedUser::query()->where('telegram_user_id', 503)->exists());

        /* In our group they joined by request: the invite does not try them there. */
        $this->assertSame(
            [TelegramGroupInvite::STATUS_JOINED, TelegramGroupInvite::STATUS_JOINED],
            TelegramGroupInvite::query()->where('invite_group_id', $group->id)->orderBy('id')->pluck('status')->all(),
        );

        $ours = TelegramJoinRequest::query()->where('telegram_user_id', 9001)->sole();
        $this->assertSame([1, null, $group->id], [$ours->telegram_account_id, $ours->scraped_user_id, $ours->invite_group_id]);
        $this->assertNull(TelegramJoinRequest::query()->where('telegram_user_id', 503)->value('invite_group_id'));

        $state = $this->app->make(JoinBotSettings::class)->state();
        $this->assertSame(14, $state['offset']);
        $this->assertNotNull($state['polled_at']);
    }

    public function test_a_public_group_is_found_by_its_name_and_keeps_its_id(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'Drivers_UZ', 'is_active' => true]);
        $this->connect();

        $this->fakeTelegram(updates: [$this->request(20, -1005555, ['id' => 601, 'first_name' => 'Ali'], 'drivers_uz')]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(-1005555, $group->fresh()->telegram_chat_id);
        $this->assertSame($group->id, TelegramJoinRequest::query()->sole()->invite_group_id);
    }

    public function test_a_refused_approval_is_written_and_a_line_of_the_invite_stays(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        $tried = TelegramScrapedUser::query()->create(['telegram_user_id' => 702, 'first_name' => 'Tried']);
        $left = TelegramScrapedUser::query()->create(['telegram_user_id' => 703, 'first_name' => 'Left']);

        foreach ([[$tried, TelegramGroupInvite::STATUS_NOT_ADDED], [$left, TelegramGroupInvite::STATUS_LEFT]] as [$person, $status]) {
            TelegramGroupInvite::query()->create([
                'invite_group_id' => $group->id,
                'scraped_user_id' => $person->id,
                'telegram_account_id' => 1,
                'status' => $status,
                'attempted_at' => now()->subDay(),
            ]);
        }

        $this->connect();
        $this->fakeTelegram(
            updates: [
                $this->request(30, -1001234, ['id' => 701, 'first_name' => 'Late']),
                $this->request(31, -1001234, ['id' => 702, 'first_name' => 'Tried']),
                $this->request(32, -1001234, ['id' => 703, 'first_name' => 'Left']),
            ],
            approve: fn (int $userId) => $userId === 701
                ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: HIDE_REQUESTER_MISSING']
                : ['ok' => true, 'result' => true],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $late = TelegramJoinRequest::query()->where('telegram_user_id', 701)->sole();
        $this->assertSame([TelegramJoinRequest::STATUS_FAILED, 'HIDE_REQUESTER_MISSING'], [$late->status, $late->error]);
        $this->assertTrue(TelegramScrapedUser::query()->where('telegram_user_id', 701)->exists());
        $this->assertFalse(TelegramGroupInvite::query()->where('scraped_user_id', $late->scraped_user_id)->exists());

        /* What the account's call did stays; who had left is in the group again. */
        $this->assertSame(TelegramGroupInvite::STATUS_NOT_ADDED, TelegramGroupInvite::query()->where('scraped_user_id', $tried->id)->value('status'));
        $this->assertSame(TelegramGroupInvite::STATUS_INVITED, TelegramGroupInvite::query()->where('scraped_user_id', $left->id)->value('status'));
    }

    public function test_a_request_met_by_a_network_error_is_asked_about_again(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        $this->connect();

        $this->fakeTelegram(
            updates: [$this->request(40, -1001234, ['id' => 801, 'first_name' => 'Ali'])],
            approve: fn () => ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(0, TelegramJoinRequest::query()->count());
        $this->assertSame(0, $this->app->make(JoinBotSettings::class)->state()['offset']);
        $this->assertNotNull($this->app->make(JoinBotSettings::class)->state()['error']);
    }

    public function test_switched_off_the_bot_asks_telegram_nothing(): void
    {
        $this->connect();
        $this->app->make(JoinBotSettings::class)->setEnabled(false);
        $this->fakeTelegram(updates: [$this->request(50, -1001234, ['id' => 901, 'first_name' => 'Ali'])]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame([], $this->sent);
        $this->assertFalse($this->app->make(JoinBotSettings::class)->alive());
    }

    public function test_every_action_of_the_bot_is_reported_to_the_owner(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'title' => 'Drivers UZ', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        TelegramScrapedUser::query()->create(['telegram_user_id' => 1002, 'first_name' => 'Old']);
        $this->connect();

        $this->fakeTelegram(
            updates: [
                $this->request(60, -1001234, ['id' => 1001, 'first_name' => 'Ali', 'username' => 'ali']),
                $this->request(61, -1001234, ['id' => 1002, 'first_name' => 'Vali']),
                $this->request(62, -1001234, ['id' => 9001, 'first_name' => 'Us']),
                $this->request(63, -1001234, ['id' => 1003, 'first_name' => 'Late']),
                [
                    'update_id' => 64,
                    'my_chat_member' => [
                        'chat' => ['id' => -1005678, 'title' => 'New group', 'type' => 'supergroup'],
                        'new_chat_member' => ['status' => 'administrator', 'can_invite_users' => true, 'user' => ['id' => 555]],
                    ],
                ],
            ],
            approve: fn (int $userId) => $userId === 1003
                ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: HIDE_REQUESTER_MISSING']
                : ['ok' => true, 'result' => true],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(
            [JoinBotSettings::DEFAULT_REPORT_CHAT],
            collect($this->sent)->where('method', 'sendMessage')->pluck('params.chat_id')->unique()->values()->all(),
        );

        $reports = $this->reports();
        $this->assertCount(5, $reports);
        $this->assertStringContainsString('Ali (@ali, ID 1001)', $reports[0]);
        $this->assertStringContainsString('Новый в базе', $reports[0]);
        $this->assertStringContainsString('«Drivers UZ»', $reports[0]);
        $this->assertStringContainsString('Уже был в базе', $reports[1]);
        $this->assertStringContainsString('+998900000001', $reports[2]);
        $this->assertStringContainsString('Не смог принять', $reports[3]);
        $this->assertStringContainsString('HIDE_REQUESTER_MISSING', $reports[3]);
        $this->assertStringContainsString('«New group»', $reports[4]);
        $this->assertNotNull($this->app->make(JoinBotSettings::class)->reportsState()['sent_at']);
    }

    public function test_a_bot_that_comes_into_our_group_may_not_write_there(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'title' => 'Drivers UZ', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        TelegramScrapedUser::query()->create(['telegram_user_id' => 3001, 'first_name' => 'Spam']);
        $this->connect();

        $joined = fn (int $updateId, int $chatId, array $user, string $status = 'member') => [
            'update_id' => $updateId,
            'chat_member' => [
                'chat' => ['id' => $chatId, 'title' => 'Drivers UZ', 'type' => 'supergroup'],
                'old_chat_member' => ['status' => 'left', 'user' => $user],
                'new_chat_member' => ['status' => $status, 'user' => $user],
            ],
        ];

        $this->fakeTelegram(updates: [
            $joined(90, -1001234, ['id' => 3001, 'is_bot' => true, 'first_name' => 'Spam', 'username' => 'spam_bot']),
            /* A person, an admin's bot, a bot in somebody else's group, our own bot: left alone. */
            $joined(91, -1001234, ['id' => 3002, 'is_bot' => false, 'first_name' => 'Ali']),
            $joined(92, -1001234, ['id' => 3003, 'is_bot' => true, 'first_name' => 'Helper'], 'administrator'),
            $joined(93, -1009999, ['id' => 3004, 'is_bot' => true, 'first_name' => 'Elsewhere']),
            $joined(94, -1001234, ['id' => 555, 'is_bot' => true, 'first_name' => 'Join']),
        ]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $muted = collect($this->sent)->where('method', 'restrictChatMember')->pluck('params')->values();
        $this->assertCount(1, $muted);
        $this->assertSame([-1001234, 3001], [$muted[0]['chat_id'], $muted[0]['user_id']]);
        $this->assertNotContains(true, $muted[0]['permissions']);

        $this->assertTrue(TelegramScrapedUser::query()->where('telegram_user_id', 3001)->value('is_bot'));
        $this->assertSame(['🔇 Запретил писать боту Spam (@spam_bot, ID 3001) в «Drivers UZ»'], $this->reports());
        $this->assertContains('chat_member', collect($this->sent)->firstWhere('method', 'getUpdates')['params']['allowed_updates']);
    }

    public function test_our_accounts_request_the_invite_run_let_in_is_left_alone(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        $this->connect();

        $this->fakeTelegram(
            updates: [$this->request(70, -1001234, ['id' => 9001, 'first_name' => 'Us'])],
            approve: fn () => ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: HIDE_REQUESTER_MISSING'],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(0, TelegramJoinRequest::query()->count());
        $this->assertSame([], $this->reports());
        $this->assertSame(71, $this->app->make(JoinBotSettings::class)->state()['offset']);
    }

    public function test_the_bots_link_that_takes_requests_is_made_once_and_kept(): void
    {
        $group = TelegramInviteGroup::query()->create(['link' => 'drivers_uz', 'is_active' => true]);
        $this->connect();
        $this->fakeTelegram();

        $service = $this->app->make(JoinRequestService::class);

        /* A public group known by its @name: its id is looked up and kept. */
        $this->assertSame('Link1', $service->requestLink($group));
        $this->assertSame(-1007777, $group->fresh()->telegram_chat_id);
        $this->assertSame(
            ['chat_id' => -1007777, 'name' => 'Postix: our accounts', 'creates_join_request' => true],
            collect($this->sent)->firstWhere('method', 'createChatInviteLink')['params'],
        );

        /* Kept: asked of nobody. A link revoked in the group: a new one. */
        $this->sent = [];
        $this->assertSame('Link1', $service->requestLink($group->fresh()));
        $this->assertSame([], $this->sent);
        $this->assertSame('Link2', $service->requestLink($group->fresh(), fresh: true));
        $this->assertSame(['-1007777' => 'https://t.me/+Link2'], $this->app->make(JoinBotSettings::class)->links());
    }

    public function test_reports_go_where_the_panel_says_and_nowhere_once_emptied(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'telegram_chat_id' => -1001234, 'is_active' => true]);
        $this->connect();
        $settings = $this->app->make(JoinBotSettings::class);

        $settings->saveReports('-100999', true, 'en');
        $this->fakeTelegram(updates: [$this->request(80, -1001234, ['id' => 1101, 'first_name' => 'Ali'])]);
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame('-100999', collect($this->sent)->firstWhere('method', 'sendMessage')['params']['chat_id']);
        $this->assertStringContainsString('Approved a request', $this->reports()[0]);

        /* Emptied: the bot keeps working, tells nobody. */
        $settings->saveReports('', true, 'ru');
        $this->sent = [];

        $this->assertFalse($this->app->make(JoinBotReporter::class)->send('approved_new', ['group' => 'Drivers', 'who' => 'Vali']));
        $this->assertSame([], $this->sent);
        $this->assertFalse($settings->reports()['enabled']);
    }

    public function test_the_panel_sets_where_the_reports_go_and_sends_a_test(): void
    {
        $this->connect();
        $this->fakeTelegram();
        $controller = $this->app->make(GroupInviteBotController::class);

        /* Never set: the owner's chat. */
        $this->assertSame(JoinBotSettings::DEFAULT_REPORT_CHAT, $controller->show(new \Illuminate\Http\Request)->getData(true)['reports']['chat_id']);

        try {
            $controller->reports(new \Illuminate\Http\Request(['chat_id' => '@someone', 'enabled' => true]));
            $this->fail('A chat id is a number.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $controller->reports(new \Illuminate\Http\Request(['chat_id' => ' 123456789 ', 'enabled' => true]));
        $this->assertSame(['chat_id' => '123456789', 'enabled' => true], array_intersect_key(
            $controller->show(new \Illuminate\Http\Request)->getData(true)['reports'],
            ['chat_id' => 0, 'enabled' => 0],
        ));

        $this->assertSame(200, $controller->testReport()->getStatusCode());
        $this->assertSame('123456789', collect($this->sent)->firstWhere('method', 'sendMessage')['params']['chat_id']);
    }

    public function test_a_trouble_asking_again_will_not_mend_is_reported_once(): void
    {
        $this->connect();
        $conflict = ['ok' => false, 'error_code' => 409, 'description' => 'Conflict: terminated by other getUpdates request'];

        $this->fakeTelegram(getUpdates: $conflict);
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertCount(1, $this->reports());
        $this->assertStringContainsString('terminated by other getUpdates request', $this->reports()[0]);
    }

    private function connect(): void
    {
        TelegramSetting::set(JoinBotSettings::KEY, ['token' => self::TOKEN, 'enabled' => true, 'bot' => ['id' => 555, 'username' => 'our_join_bot', 'name' => 'Join']]);
    }

    /**
     * @param array<string, mixed> $from
     *
     * @return array<string, mixed>
     */
    private function request(int $updateId, int $chatId, array $from, ?string $chatUsername = null): array
    {
        return [
            'update_id' => $updateId,
            'chat_join_request' => [
                'chat' => array_filter(['id' => $chatId, 'title' => 'Drivers', 'type' => 'supergroup', 'username' => $chatUsername]),
                'from' => ['is_bot' => false, ...$from],
                'user_chat_id' => $from['id'],
                'date' => now()->timestamp,
            ],
        ];
    }

    /**
     * The Bot API: getMe, getWebhookInfo, getUpdates (the updates once,
     * nothing after the offset passed them), approveChatJoinRequest.
     *
     * @param list<array<string, mixed>> $updates
     */
    private function fakeTelegram(array $updates = [], ?callable $approve = null, string $webhook = '', ?array $getUpdates = null): void
    {
        $this->sent = [];
        $links = 0;

        Http::fake(function (Request $request) use ($updates, $approve, $webhook, $getUpdates, &$links) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $params = $request->data();
            $this->sent[] = ['method' => $method, 'params' => $params];

            return Http::response(match ($method) {
                'getMe' => ['ok' => true, 'result' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Join', 'username' => 'our_join_bot']],
                'getWebhookInfo' => ['ok' => true, 'result' => ['url' => $webhook]],
                'getUpdates' => $getUpdates ?? ['ok' => true, 'result' => array_values(array_filter(
                    $updates,
                    fn (array $update) => $update['update_id'] >= (int) ($params['offset'] ?? 0),
                ))],
                'approveChatJoinRequest' => $approve !== null ? $approve((int) $params['user_id']) : ['ok' => true, 'result' => true],
                'getChat' => ['ok' => true, 'result' => ['id' => -1007777, 'type' => 'supergroup', 'title' => 'Drivers']],
                'createChatInviteLink' => ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+Link' . (++$links), 'creates_join_request' => true]],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                'restrictChatMember' => ['ok' => true, 'result' => true],
                default => ['ok' => false, 'error_code' => 404, 'description' => 'Not Found'],
            });
        });
    }

    /**
     * @return list<string> the texts of the reports, in order
     */
    private function reports(): array
    {
        return collect($this->sent)->where('method', 'sendMessage')->pluck('params.text')->values()->all();
    }
}
