<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\JoinRequestService;
use App\Application\Telegram\Services\SpamAdmins;
use App\Http\Controllers\Api\Telegram\GroupInviteSpamAdminController;
use App\Models\Telegram\TelegramSetting;
use App\Models\Telegram\TelegramSpamAdmin;
use App\Models\Telegram\TelegramSpamMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The admins of the anti-spam: put on in the panel by a profile link,
 * @username or ID, or by an invite link the panel makes - whoever opens it
 * and presses Start is one, once. Their links in our groups stay.
 * Telegram is faked at the HTTP level.
 */
final class SpamAdminsTest extends TestCase
{
    private const TOKEN = '123456789:AAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CHAT = -1001234;

    private const OWNER = 8028326577;

    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        /* The panel speaks Russian to the owner. */
        $this->app->setLocale('ru');

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
        TelegramSetting::set(JoinBotSettings::KEY, ['token' => self::TOKEN, 'enabled' => true, 'bot' => ['id' => 555, 'username' => 'our_join_bot', 'name' => 'Join']]);
    }

    public function test_the_page_puts_admins_on_by_a_profile_link_a_username_or_an_id(): void
    {
        $controller = $this->app->make(GroupInviteSpamAdminController::class);

        $json = $controller->store(new \Illuminate\Http\Request([
            'entries' => "https://t.me/Ali_Disp\n@second, 123456789\n" . self::OWNER . "\nпривет",
            'note' => 'диспетчеры',
        ]))->getData(true);

        $this->assertSame(['привет'], $json['invalid']);
        $this->assertStringContainsString('Добавлено админов: 3.', $json['message']);
        $this->assertStringContainsString('Уже были админами: 1.', $json['message']);

        $page = $controller->show()->getData(true);
        $this->assertSame(self::OWNER, $page['owner']);
        $this->assertSame(
            [[123456789, null], [null, 'second'], [null, 'ali_disp']],
            collect($page['admins'])->map(fn (array $admin) => [$admin['telegram_user_id'], $admin['username']])->all(),
        );
        $this->assertSame(['диспетчеры'], collect($page['admins'])->pluck('note')->unique()->values()->all());

        /* Typed again: known. */
        $again = $controller->store(new \Illuminate\Http\Request(['entries' => '@Second']))->getData(true);
        $this->assertStringContainsString('Добавлено админов: 0.', $again['message']);
    }

    public function test_an_invite_link_makes_whoever_opens_it_an_admin_once(): void
    {
        $controller = $this->app->make(GroupInviteSpamAdminController::class);
        $made = $controller->invite(new \Illuminate\Http\Request(['note' => 'Ali, диспетчер']))->getData(true);

        $token = TelegramSpamAdmin::query()->sole()->invite_token;
        $this->assertStringStartsWith(SpamAdmins::INVITE_PREFIX, $token);
        $this->assertSame("https://t.me/our_join_bot?start={$token}", $made['link']);
        $this->assertSame($made['link'], $controller->show()->getData(true)['invites'][0]['link']);

        $this->fakeTelegram([
            $this->private(10, 4242, "/start {$token}", ['first_name' => 'Ali', 'username' => 'Ali']),
            /* The same link a second time: spent. */
            $this->private(11, 5353, "/start {$token}", ['first_name' => 'Other']),
            /* The new admin's link in the group stays; somebody else's goes. */
            $this->inGroup(12, 7, 4242, 'https://rules.example'),
            $this->inGroup(13, 8, 5353, 'https://ads.example'),
        ]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $admin = TelegramSpamAdmin::query()->sole();
        $this->assertSame([4242, 'ali', 'Ali', 'Ali, диспетчер', null], [$admin->telegram_user_id, $admin->username, $admin->name, $admin->note, $admin->invite_token]);
        $this->assertNotNull($admin->joined_at);

        $this->assertStringStartsWith('✅ Готово, Ali!', $this->said(4242));
        $this->assertStringStartsWith('⚠️ Эта ссылка уже использована', $this->said(5353));
        $this->assertContains("👮 Ali (@Ali, ID 4242) стал админом антиспама по пригласительной ссылке\nAli, диспетчер", $this->saidAll(self::OWNER));

        $this->assertSame([8], TelegramSpamMessage::query()->pluck('message_id')->all());

        $page = $controller->show()->getData(true);
        $this->assertSame([[], true], [$page['invites'], $page['admins'][0]['by_invite']]);
    }

    public function test_an_old_invite_link_makes_nobody_an_admin(): void
    {
        TelegramSpamAdmin::query()->create(['invite_token' => 'adm_oldoldoldold', 'invite_expires_at' => now()->subMinute()]);
        $this->fakeTelegram([$this->private(20, 4242, '/start adm_oldoldoldold', ['first_name' => 'Ali'])]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertTrue(TelegramSpamAdmin::query()->sole()->isInvite());
        $this->assertStringStartsWith('⚠️ Эта ссылка уже использована или устарела', $this->said(4242));
        $this->assertTrue($this->app->make(GroupInviteSpamAdminController::class)->show()->getData(true)['invites'][0]['expired']);
    }

    public function test_an_admin_already_spends_the_link_on_their_own_line(): void
    {
        TelegramSpamAdmin::query()->create(['username' => 'ali']);
        TelegramSpamAdmin::query()->create(['invite_token' => 'adm_againagainagain', 'invite_expires_at' => now()->addDay(), 'note' => 'Ali']);
        $this->fakeTelegram([$this->private(30, 4242, '/start adm_againagainagain', ['first_name' => 'Ali', 'username' => 'ali'])]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $admin = TelegramSpamAdmin::query()->sole();
        $this->assertSame([4242, 'ali', 'Ali'], [$admin->telegram_user_id, $admin->username, $admin->note]);
    }

    public function test_an_admin_and_a_link_are_removed_and_no_bot_makes_no_link(): void
    {
        $controller = $this->app->make(GroupInviteSpamAdminController::class);
        $admin = TelegramSpamAdmin::query()->create(['telegram_user_id' => 4242]);
        $invite = $this->app->make(SpamAdmins::class)->invite(null);

        $this->assertSame('Ссылка удалена.', $controller->destroy($invite)->getData(true)['message']);
        $this->assertSame('Убран из админов.', $controller->destroy($admin)->getData(true)['message']);
        $this->assertSame(0, TelegramSpamAdmin::query()->count());

        TelegramSetting::set(JoinBotSettings::KEY, ['token' => null, 'enabled' => false, 'bot' => []]);
        $this->assertSame(422, $controller->invite(new \Illuminate\Http\Request)->getStatusCode());
    }

    public function test_the_old_fields_ids_come_over_as_admins(): void
    {
        Schema::drop('telegram_spam_admins');
        TelegramSetting::set(JoinBotSettings::SPAM_KEY, ['delay' => 15, 'notify' => true, 'moderators' => [111222333, '444555666', 0]]);

        (include database_path('migrations/2026_11_06_110000_create_telegram_spam_admins_table.php'))->up();

        $this->assertSame([111222333, 444555666], TelegramSpamAdmin::query()->orderBy('id')->pluck('telegram_user_id')->all());
        $this->assertTrue($this->app->make(SpamAdmins::class)->isAdmin(['id' => 444555666]));
    }

    /**
     * What the bot wrote to this chat last.
     */
    private function said(int $chatId): ?string
    {
        $all = $this->saidAll($chatId);

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * Everything the bot wrote to this chat.
     *
     * @return list<string>
     */
    private function saidAll(int $chatId): array
    {
        return collect($this->sent)
            ->where('method', 'sendMessage')
            ->filter(fn ($call) => (string) $call['params']['chat_id'] === (string) $chatId)
            ->pluck('params.text')
            ->values()
            ->all();
    }

    /**
     * A message to the bot in its own chat.
     *
     * @param array<string, mixed> $from
     *
     * @return array<string, mixed>
     */
    private function private(int $updateId, int $userId, string $text, array $from): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $userId, 'is_bot' => false, ...$from],
                'chat' => ['id' => $userId, 'type' => 'private', 'first_name' => $from['first_name'] ?? ''],
                'date' => now()->timestamp,
                'text' => $text,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inGroup(int $updateId, int $messageId, int $userId, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'User'],
                'chat' => ['id' => self::CHAT, 'title' => 'Drivers UZ', 'type' => 'supergroup'],
                'date' => now()->timestamp - 20,
                'text' => $text,
            ],
        ];
    }

    /**
     * The Bot API: getUpdates (the updates once), sendMessage, copyMessage,
     * deleteMessage(s), getChatAdministrators (nobody but the owner).
     *
     * @param list<array<string, mixed>> $updates
     */
    private function fakeTelegram(array $updates): void
    {
        $this->sent = [];

        Http::fake(function (Request $request) use ($updates) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $params = $request->data();
            $this->sent[] = ['method' => $method, 'params' => $params];

            return Http::response(match ($method) {
                'getUpdates' => ['ok' => true, 'result' => array_values(array_filter(
                    $updates,
                    fn (array $update) => $update['update_id'] >= (int) ($params['offset'] ?? 0),
                ))],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                'copyMessage' => ['ok' => true, 'result' => ['message_id' => 2]],
                'deleteMessage', 'deleteMessages' => ['ok' => true, 'result' => true],
                'getChatAdministrators' => ['ok' => true, 'result' => [['status' => 'creator', 'user' => ['id' => self::OWNER, 'is_bot' => false]]]],
                default => ['ok' => false, 'error_code' => 404, 'description' => 'Not Found'],
            });
        });
    }
}
