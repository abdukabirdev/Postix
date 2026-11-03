<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\JoinBotSettings;
use App\Application\Telegram\Services\JoinRequestService;
use App\Application\Telegram\Services\SpamCleaner;
use App\Http\Controllers\Api\Telegram\GroupInviteSpamController;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use App\Models\Telegram\TelegramSetting;
use App\Models\Telegram\TelegramSpamMessage;
use App\Models\Telegram\TelegramSpamSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The spam list: our join request bot deletes what the people of the list
 * write in a group once their seconds are up, tells the owner without a
 * sound, and leaves everybody else - and the service lines - alone.
 * Telegram is faked at the HTTP level.
 */
final class SpamCleanerTest extends TestCase
{
    private const TOKEN = '123456789:AAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CHAT = -1001234;

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

    public function test_the_lists_messages_go_once_their_time_is_up_and_the_owner_is_told_without_a_sound(): void
    {
        TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        TelegramSpamSender::query()->create(['username' => 'spam_two', 'note' => 'taxi ads']);

        $this->fakeTelegram(updates: [
            $this->message(10, 1, ['id' => 701, 'first_name' => 'Ali', 'username' => 'ali']),
            /* Typed by @username: matched whatever the case, and its id is learnt. */
            $this->message(11, 2, ['id' => 702, 'first_name' => 'Vali', 'username' => 'Spam_Two'], ['photo' => [['file_id' => 'x']], 'caption' => 'Cheap taxi']),
            /* Not on the list, a service line, a private chat: left alone. */
            $this->message(12, 3, ['id' => 703, 'first_name' => 'Good']),
            $this->message(13, 4, ['id' => 701, 'first_name' => 'Ali'], ['new_chat_members' => [['id' => 701, 'is_bot' => false, 'first_name' => 'Ali']]]),
            $this->message(14, 5, ['id' => 701, 'first_name' => 'Ali'], chat: ['id' => 701, 'type' => 'private', 'first_name' => 'Ali']),
        ]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $deletes = collect($this->sent)->whereIn('method', ['deleteMessage', 'deleteMessages'])->values();
        $this->assertCount(1, $deletes);
        $this->assertSame('deleteMessages', $deletes[0]['method']);
        $this->assertSame([self::CHAT, [1, 2]], [$deletes[0]['params']['chat_id'], $deletes[0]['params']['message_ids']]);

        $this->assertSame(
            [TelegramSpamMessage::STATUS_DELETED, TelegramSpamMessage::STATUS_DELETED],
            TelegramSpamMessage::query()->orderBy('message_id')->pluck('status')->all(),
        );
        $this->assertSame([702, 'spam_two', 'Vali'], array_values(TelegramSpamSender::query()->where('note', 'taxi ads')->first(['telegram_user_id', 'username', 'name'])->toArray()));
        $this->assertSame([1, 1], TelegramSpamSender::query()->orderBy('id')->pluck('deleted_count')->all());

        $reports = collect($this->sent)->where('method', 'sendMessage')->values();
        $this->assertCount(2, $reports);
        $this->assertSame([true, true], $reports->pluck('params.disable_notification')->all());
        $this->assertSame("🗑 Удалил сообщение от Ali (@ali, ID 701) в «Drivers UZ»\n«Buy now»", $reports[0]['params']['text']);
        $this->assertStringContainsString('[фото] «Cheap taxi»', $reports[1]['params']['text']);

        $this->assertContains('message', collect($this->sent)->firstWhere('method', 'getUpdates')['params']['allowed_updates']);
    }

    public function test_a_message_waits_its_seconds_before_it_goes(): void
    {
        TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        $this->fakeTelegram(updates: [$this->message(20, 7, ['id' => 701, 'first_name' => 'Ali'], ago: 0)]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $row = TelegramSpamMessage::query()->sole();
        $this->assertSame(TelegramSpamMessage::STATUS_PENDING, $row->status);
        $this->assertSame(15, (int) $row->sent_at->diffInSeconds($row->delete_at));
        $this->assertFalse(collect($this->sent)->contains('method', 'deleteMessage'));

        /* The poll asks Telegram no longer than until the message is due. */
        $this->assertLessThanOrEqual(15, $this->app->make(SpamCleaner::class)->dueIn());

        $this->travel(16)->seconds();
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(TelegramSpamMessage::STATUS_DELETED, $row->fresh()->status);
        $this->assertSame([self::CHAT, 7], array_values(collect($this->sent)->firstWhere('method', 'deleteMessage')['params']));
    }

    public function test_the_wait_and_the_reports_follow_the_panel(): void
    {
        TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        $this->app->make(JoinBotSettings::class)->saveSpam(0, false);
        $this->fakeTelegram(updates: [$this->message(30, 8, ['id' => 701, 'first_name' => 'Ali'], ago: 0)]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(TelegramSpamMessage::STATUS_DELETED, TelegramSpamMessage::query()->sole()->status);
        $this->assertFalse(collect($this->sent)->contains('method', 'sendMessage'));
    }

    public function test_a_message_the_bot_may_not_delete_is_marked_and_the_owner_is_told(): void
    {
        TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        $this->fakeTelegram(
            updates: [$this->message(40, 9, ['id' => 701, 'first_name' => 'Ali'])],
            delete: fn () => ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be deleted"],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $row = TelegramSpamMessage::query()->sole();
        $this->assertSame([TelegramSpamMessage::STATUS_FAILED, 'MESSAGE_DELETE_FORBIDDEN'], [$row->status, $row->error]);
        $this->assertSame(0, TelegramSpamSender::query()->sole()->deleted_count);

        $report = collect($this->sent)->firstWhere('method', 'sendMessage')['params'];
        $this->assertTrue($report['disable_notification']);
        $this->assertStringContainsString('Не смог удалить сообщение от Ali (ID 701)', $report['text']);
        $this->assertStringContainsString('У бота нет права «Удалять сообщения»', $report['text']);
    }

    public function test_telegram_out_of_reach_leaves_the_message_for_a_later_sweep(): void
    {
        TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        $this->fakeTelegram(
            updates: [$this->message(50, 10, ['id' => 701, 'first_name' => 'Ali'])],
            delete: fn () => ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'],
        );

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame(TelegramSpamMessage::STATUS_PENDING, TelegramSpamMessage::query()->sole()->status);
        $this->assertFalse(collect($this->sent)->contains('method', 'sendMessage'));
    }

    public function test_a_username_counts_until_the_id_is_known_then_the_id_does(): void
    {
        TelegramSpamSender::query()->create(['username' => 'old_name']);
        /* Typed twice: by @username and, later, by id. */
        TelegramSpamSender::query()->create(['username' => 'old_name', 'note' => 'twice']);

        $this->fakeTelegram(updates: [
            $this->message(60, 11, ['id' => 901, 'first_name' => 'Spam', 'username' => 'old_name']),
            /* Renamed: still them. */
            $this->message(61, 12, ['id' => 901, 'first_name' => 'Spam', 'username' => 'new_name']),
            /* Somebody else took the old @username: not them. */
            $this->message(62, 13, ['id' => 902, 'first_name' => 'Other', 'username' => 'old_name']),
        ]);

        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->assertSame([11, 12], TelegramSpamMessage::query()->orderBy('message_id')->pluck('message_id')->all());

        $sender = TelegramSpamSender::query()->sole();
        $this->assertSame([901, 'new_name', 'twice', 2], [$sender->telegram_user_id, $sender->username, $sender->note, $sender->deleted_count]);
    }

    public function test_off_the_list_their_waiting_messages_stay(): void
    {
        $sender = TelegramSpamSender::query()->create(['telegram_user_id' => 701]);
        $this->fakeTelegram(updates: [$this->message(70, 14, ['id' => 701, 'first_name' => 'Ali'], ago: 0)]);
        $this->app->make(JoinRequestService::class)->poll(2, fn () => null);

        $this->app->make(GroupInviteSpamController::class)->destroy($sender);

        $this->assertSame(0, TelegramSpamMessage::query()->count());
        $this->assertSame(0, TelegramSpamSender::query()->count());
    }

    public function test_what_the_panel_types_is_understood(): void
    {
        $parsed = SpamCleaner::parse("123456789\n@Spam_Bot, https://t.me/Some_User -1001234567890; Ali junk! t.me/+abcDEF @spam_bot");

        $this->assertSame([
            ['telegram_user_id' => 123456789, 'username' => null],
            ['telegram_user_id' => null, 'username' => 'spam_bot'],
            ['telegram_user_id' => null, 'username' => 'some_user'],
            ['telegram_user_id' => -1001234567890, 'username' => null],
        ], $parsed['entries']);
        $this->assertSame(['Ali', 'junk!', 't.me/+abcDEF'], $parsed['invalid']);
    }

    public function test_the_panel_puts_each_one_on_once_and_names_who_the_base_knows(): void
    {
        TelegramScrapedUser::query()->create(['telegram_user_id' => 801, 'first_name' => 'Vali', 'last_name' => 'Aliyev', 'username' => 'vali']);
        $controller = $this->app->make(GroupInviteSpamController::class);

        $first = $controller->store(new \Illuminate\Http\Request(['entries' => "801\n@X_User\n801", 'note' => 'ads']))->getData(true);
        $this->assertSame([2, 0], [$first['added'], $first['known']]);

        $again = $controller->store(new \Illuminate\Http\Request(['entries' => '@x_user 801 Ali']))->getData(true);
        $this->assertSame([0, 2, ['Ali']], [$again['added'], $again['known'], $again['invalid']]);

        $this->assertSame(422, $controller->store(new \Illuminate\Http\Request(['entries' => 'Ali Valiyev']))->getStatusCode());

        $senders = collect($controller->show(new \Illuminate\Http\Request)->getData(true)['senders']);
        $this->assertSame('Vali Aliyev', $senders->firstWhere('telegram_user_id', 801)['name']);
        $this->assertSame(['ads', 'ads'], $senders->pluck('note')->all());
        $this->assertNull($senders->firstWhere('username', 'x_user')['telegram_user_id']);
    }

    public function test_the_page_tells_where_the_bot_may_delete(): void
    {
        TelegramInviteGroup::query()->create(['link' => 'drivers', 'title' => 'Drivers UZ', 'telegram_chat_id' => self::CHAT, 'is_active' => true]);
        $this->fakeTelegram(member: ['status' => 'administrator', 'can_invite_users' => true, 'can_restrict_members' => true, 'can_delete_messages' => false]);

        $group = $this->app->make(JoinRequestService::class)->groups()->sole();

        $this->assertSame(['no_delete', false], [$group['state'], $group['can_delete']]);
    }

    /**
     * @param array<string, mixed>      $from
     * @param array<string, mixed>      $content
     * @param array<string, mixed>|null $chat
     *
     * @return array<string, mixed>
     */
    private function message(int $updateId, int $messageId, array $from, array $content = ['text' => 'Buy now'], int $ago = 20, ?array $chat = null): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => ['is_bot' => false, ...$from],
                'chat' => $chat ?? ['id' => self::CHAT, 'title' => 'Drivers UZ', 'type' => 'supergroup'],
                'date' => now()->timestamp - $ago,
                ...$content,
            ],
        ];
    }

    /**
     * The Bot API: getUpdates (the updates once, nothing after the offset
     * passed them), deleteMessage(s), sendMessage, getChatMember.
     *
     * @param list<array<string, mixed>> $updates
     * @param array<string, mixed>       $member
     */
    private function fakeTelegram(array $updates = [], ?callable $delete = null, array $member = ['status' => 'creator']): void
    {
        $this->sent = [];

        Http::fake(function (Request $request) use ($updates, $delete, $member) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $params = $request->data();
            $this->sent[] = ['method' => $method, 'params' => $params];

            return Http::response(match ($method) {
                'getUpdates' => ['ok' => true, 'result' => array_values(array_filter(
                    $updates,
                    fn (array $update) => $update['update_id'] >= (int) ($params['offset'] ?? 0),
                ))],
                'deleteMessage', 'deleteMessages' => $delete !== null ? $delete($params) : ['ok' => true, 'result' => true],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                'getChatMember' => ['ok' => true, 'result' => ['user' => ['id' => 555, 'is_bot' => true], ...$member]],
                default => ['ok' => false, 'error_code' => 404, 'description' => 'Not Found'],
            });
        });
    }
}
