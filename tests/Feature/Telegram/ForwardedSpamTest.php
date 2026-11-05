<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\JoinRequestService;
use App\Http\Controllers\Api\Telegram\GroupInviteSpamController;
use App\Models\Telegram\TelegramSetting;
use App\Models\Telegram\TelegramSpamSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The owner forwards a spammer's message from a group to the join request
 * bot: its author goes onto the spam list and their next messages are
 * deleted; the forwarded one is left alone. The bot answers under the
 * forward. Telegram is faked at the HTTP level.
 */
final class ForwardedSpamTest extends TestCase
{
    private const TOKEN = '123456789:AAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CHAT = -1001234;

    /** The reports' chat: the one who may forward until the panel names others. */
    private const OWNER = 8028326577;

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

        DB::table('telegram_accounts')->insert(['id' => 1, 'phone' => '+998900000001', 'telegram_user_id' => 9001]);
        TelegramSetting::set(JoinBotSettings::KEY, ['token' => self::TOKEN, 'enabled' => true, 'bot' => ['id' => 555, 'username' => 'our_join_bot', 'name' => 'Join']]);
    }

    public function test_the_author_goes_onto_the_list_their_next_message_goes_the_forwarded_one_stays(): void
    {
        $this->fakeTelegram([
            $this->forward(10, 100, ['type' => 'user', 'sender_user' => ['id' => 701, 'is_bot' => false, 'first_name' => 'Ali', 'username' => 'Ali'], 'date' => now()->timestamp - 60]),
            /* What they write next goes as the list's messages go. */
            $this->inGroup(11, 3, ['id' => 701, 'first_name' => 'Ali', 'username' => 'Ali']),
        ]);

        $this->poll();

        $sender = TelegramSpamSender::query()->sole();
        $this->assertSame([701, 'ali', 'Ali', 'Переслано боту'], [$sender->telegram_user_id, $sender->username, $sender->name, $sender->note]);

        /* Only the next message was deleted. */
        $deletes = collect($this->sent)->whereIn('method', ['deleteMessage', 'deleteMessages'])->values();
        $this->assertSame([[self::CHAT, 3]], $deletes->map(fn ($call) => [$call['params']['chat_id'], $call['params']['message_id']])->all());

        $answers = $this->answers();
        $this->assertCount(1, $answers);
        $this->assertSame([self::OWNER, 100], [(int) $answers[0]['chat_id'], $answers[0]['reply_parameters']['message_id']]);
        $this->assertSame('🚫 Ali (@Ali, ID 701) — добавил в «Антиспам». Его новые сообщения в группах буду удалять.', $answers[0]['text']);
    }

    public function test_written_as_a_channel_the_channel_is_listed(): void
    {
        $this->fakeTelegram([
            $this->forward(20, 101, ['type' => 'chat', 'sender_chat' => ['id' => -100555, 'title' => 'Taxi Ads', 'type' => 'channel'], 'date' => now()->timestamp - 60]),
        ]);

        $this->poll();

        $this->assertSame([-100555], TelegramSpamSender::query()->pluck('telegram_user_id')->all());
        $this->assertStringContainsString('Taxi Ads (ID -100555) — добавил', $this->answers()[0]['text']);
    }

    public function test_on_the_list_already_the_line_is_brought_up_to_date(): void
    {
        TelegramSpamSender::query()->create(['username' => 'ali', 'note' => 'typed']);

        $this->fakeTelegram([
            $this->forward(30, 102, ['type' => 'user', 'sender_user' => ['id' => 701, 'is_bot' => false, 'first_name' => 'Ali', 'username' => 'ali'], 'date' => now()->timestamp - 60]),
        ]);

        $this->poll();

        $sender = TelegramSpamSender::query()->sole();
        $this->assertSame([701, 'typed'], [$sender->telegram_user_id, $sender->note]);
        $this->assertStringContainsString('уже в «Антиспаме»', $this->answers()[0]['text']);
    }

    public function test_whom_telegram_does_not_name_is_not_listed(): void
    {
        $at = now()->timestamp - 60;
        $group = ['id' => self::CHAT, 'title' => 'Drivers UZ', 'type' => 'supergroup'];

        $this->fakeTelegram([
            $this->forward(40, 103, ['type' => 'hidden_user', 'sender_user_name' => 'Ghost', 'date' => $at]),
            $this->forward(41, 104, ['type' => 'channel', 'chat' => ['id' => -100777, 'title' => 'Ads Channel', 'type' => 'channel'], 'message_id' => 55, 'date' => $at]),
            /* The group's anonymous admin, our own account. */
            $this->forward(42, 105, ['type' => 'chat', 'sender_chat' => $group, 'date' => $at]),
            $this->forward(43, 106, ['type' => 'user', 'sender_user' => ['id' => 9001, 'is_bot' => false, 'first_name' => 'Ours'], 'date' => $at]),
        ]);

        $this->poll();

        $this->assertSame(0, TelegramSpamSender::query()->count());

        $answers = array_column($this->answers(), 'text');
        $this->assertStringStartsWith('⚠️ Ghost скрыл свой профиль', $answers[0]);
        $this->assertStringContainsString('пост канала Ads Channel', $answers[1]);
        $this->assertStringContainsString('анонимного администратора', $answers[2]);
        $this->assertStringContainsString('Ours (ID 9001) — наш аккаунт', $answers[3]);
    }

    public function test_an_album_gets_one_answer(): void
    {
        $origin = ['type' => 'user', 'sender_user' => ['id' => 701, 'is_bot' => false, 'first_name' => 'Ali'], 'date' => now()->timestamp - 60];

        $this->fakeTelegram([
            $this->forward(50, 107, $origin, ['photo' => [['file_id' => 'a']], 'caption' => 'Sale', 'media_group_id' => 'p1']),
            $this->forward(51, 108, $origin, ['photo' => [['file_id' => 'b']], 'media_group_id' => 'p1']),
        ]);

        $this->poll();

        $this->assertCount(1, $this->answers());
        $this->assertSame(1, TelegramSpamSender::query()->count());
    }

    public function test_the_owner_gets_a_hint_and_strangers_nothing(): void
    {
        $this->fakeTelegram([
            $this->forward(60, 110, ['type' => 'user', 'sender_user' => ['id' => 701, 'is_bot' => false, 'first_name' => 'Ali'], 'date' => now()->timestamp - 60], from: 4242),
            $this->forward(61, 111, null, ['text' => '/start']),
        ]);

        $this->poll();

        $this->assertSame(0, TelegramSpamSender::query()->count());

        $answers = $this->answers();
        $this->assertCount(1, $answers);
        $this->assertSame([self::OWNER, 111], [(int) $answers[0]['chat_id'], $answers[0]['reply_parameters']['message_id']]);
        $this->assertStringStartsWith('👋 Перешлите мне сообщение спамера', $answers[0]['text']);
    }

    public function test_the_panel_names_who_may_forward(): void
    {
        $settings = $this->app->make(JoinBotSettings::class);
        $controller = $this->app->make(GroupInviteSpamController::class);

        $this->assertSame([self::OWNER], $settings->moderators());

        $controller->settings(new \Illuminate\Http\Request(['delay' => 10, 'notify' => true, 'moderators' => '111222333, 444555666 111222333']));
        $this->assertSame([111222333, 444555666], $settings->moderators());
        $this->assertSame([111222333, 444555666], $controller->show(new \Illuminate\Http\Request)->getData(true)['forward']['moderators']);

        $this->assertSame(422, $controller->settings(new \Illuminate\Http\Request(['delay' => 10, 'notify' => true, 'moderators' => '111222333 @ali']))->getStatusCode());

        /* Not sent: kept. */
        $controller->settings(new \Illuminate\Http\Request(['delay' => 5, 'notify' => false]));
        $this->assertSame([5, false, [111222333, 444555666]], array_values($settings->spam()));

        /* Emptied: whoever the reports go to again. */
        $controller->settings(new \Illuminate\Http\Request(['delay' => 5, 'notify' => false, 'moderators' => '']));
        $this->assertSame([self::OWNER], $settings->moderators());
    }

    private function poll(): void
    {
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);
    }

    /**
     * What the bot answered under the forwards.
     *
     * @return list<array<string, mixed>>
     */
    private function answers(): array
    {
        return collect($this->sent)
            ->where('method', 'sendMessage')
            ->filter(fn ($call) => isset($call['params']['reply_parameters']))
            ->pluck('params')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $from
     *
     * @return array<string, mixed>
     */
    private function inGroup(int $updateId, int $messageId, array $from): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => ['is_bot' => false, ...$from],
                'chat' => ['id' => self::CHAT, 'title' => 'Drivers UZ', 'type' => 'supergroup'],
                'date' => now()->timestamp - 20,
                'text' => 'Buy now',
            ],
        ];
    }

    /**
     * A message to the bot in its own chat; with an origin - a forward.
     *
     * @param array<string, mixed>|null $origin
     * @param array<string, mixed>      $content
     *
     * @return array<string, mixed>
     */
    private function forward(int $updateId, int $messageId, ?array $origin, array $content = ['text' => 'Buy now'], int $from = self::OWNER): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat' => ['id' => $from, 'type' => 'private', 'first_name' => 'Owner'],
                'date' => now()->timestamp,
                ...($origin !== null ? ['forward_origin' => $origin] : []),
                ...$content,
            ],
        ];
    }

    /**
     * The Bot API: getUpdates (the updates once, nothing after the offset
     * passed them), deleteMessage(s), sendMessage.
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
                'deleteMessage', 'deleteMessages' => ['ok' => true, 'result' => true],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                default => ['ok' => false, 'error_code' => 404, 'description' => 'Not Found'],
            });
        });
    }
}
