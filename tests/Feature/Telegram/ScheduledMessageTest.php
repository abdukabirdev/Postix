<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\ScheduledMessageSender;
use App\Models\Role;
use App\Models\Telegram\TelegramFileRoute;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramScheduledMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Records what the listener's account is asked to send.
 */
final class FakeScheduledMessages
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<int> */
    public array $failFor = [];

    public function sendMessage(array $params): array
    {
        if (in_array($params['peer'], $this->failFor, true)) {
            throw new RuntimeException('CHAT_WRITE_FORBIDDEN');
        }

        $this->sent[] = $params;

        return [];
    }

    /**
     * @return list<int>
     */
    public function peers(): array
    {
        return array_map(static fn (array $call): int => $call['peer'], $this->sent);
    }
}

final class FakeScheduledMessageHandler extends SimpleEventHandler
{
}

class ScheduledMessageTest extends TestCase
{
    private const FIRST = -1004431008937;

    private const SECOND = -5524265426;

    private FakeScheduledMessages $messages;

    private SimpleEventHandler $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['telegram_scheduled_messages', 'telegram_listeners', 'telegram_accounts', 'telegram_file_forwards', 'telegram_file_routes', 'telegram_driver_check_chats'] as $table) {
            Schema::dropIfExists($table);
        }

        (require base_path('database/migrations/2026_09_22_090000_create_telegram_driver_check_chats_table.php'))->up();
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_17_130000_create_telegram_listeners_table.php'))->up();
        (require base_path('database/migrations/2026_10_12_110000_create_telegram_file_routes_tables.php'))->up();

        (require base_path('database/migrations/2026_10_17_120000_create_telegram_scheduled_messages_table.php'))->up();

        $this->messages = new FakeScheduledMessages();

        $handler = (new ReflectionClass(FakeScheduledMessageHandler::class))
            ->newInstanceWithoutConstructor();

        $handler->messages = $this->messages;

        $this->telegram = $handler;
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function make(array $attributes = []): TelegramScheduledMessage
    {
        return TelegramScheduledMessage::query()->create($attributes + [
            'name' => 'Report',
            'text' => 'Отчет эсдан чикмасин',
            'send_at' => '17:00',
            'days' => [],
            'chat_ids' => [self::FIRST, self::SECOND],
            'is_active' => true,
        ]);
    }

    private function flush(): void
    {
        app(ScheduledMessageSender::class)->flush($this->telegram);
    }

    public function test_it_goes_out_at_its_time_once_a_day(): void
    {
        $message = $this->make();

        $this->travelTo(now()->setTime(16, 59));
        $this->flush();
        $this->assertSame([], $this->messages->sent);

        $this->travelTo(now()->setTime(17, 0));
        $this->flush();
        $this->flush();

        $this->assertSame([self::FIRST, self::SECOND], $this->messages->peers());
        $this->assertSame('Отчет эсдан чикмасин', $this->messages->sent[0]['message']);

        $message->refresh();
        $this->assertSame(TelegramScheduledMessage::STATUS_SENT, $message->last_status);
        $this->assertSame(now()->toDateString(), $message->last_sent_on->toDateString());

        $this->travelTo(now()->addDay()->setTime(17, 1));
        $this->flush();

        $this->assertCount(4, $this->messages->sent);
    }

    public function test_a_listener_that_was_down_catches_up_but_not_at_midnight(): void
    {
        $this->make();

        $this->travelTo(now()->setTime(23, 30));
        $this->flush();

        $this->assertSame([], $this->messages->sent);

        $this->travelTo(now()->setTime(18, 30));
        $this->flush();

        $this->assertCount(2, $this->messages->sent);
    }

    public function test_only_its_days_off_messages_and_messages_without_groups_stay_quiet(): void
    {
        $this->travelTo(now()->next('Wednesday')->setTime(17, 0));

        $this->make(['name' => 'Mon-Tue', 'days' => [1, 2]]);
        $this->make(['name' => 'Off', 'is_active' => false]);
        $this->make(['name' => 'Nowhere', 'chat_ids' => []]);

        $this->flush();

        $this->assertSame([], $this->messages->sent);

        $this->make(['name' => 'Wed', 'days' => [3]]);

        $this->flush();

        $this->assertCount(2, $this->messages->sent);
    }

    public function test_a_refusing_group_does_not_stop_the_others(): void
    {
        $this->messages->failFor = [self::FIRST];

        $message = $this->make();

        $this->travelTo(now()->setTime(17, 0));
        $this->flush();

        $this->assertSame([self::SECOND], $this->messages->peers());

        $message->refresh();
        $this->assertSame(TelegramScheduledMessage::STATUS_PARTIAL, $message->last_status);
        $this->assertStringContainsString('CHAT_WRITE_FORBIDDEN', (string) $message->last_error);

        /* One try a day: the refused group is not hammered every few seconds. */
        $this->flush();
        $this->assertCount(1, $this->messages->sent);
    }

    public function test_send_now_goes_out_at_once_and_leaves_the_schedule_alone(): void
    {
        $this->travelTo(now()->setTime(10, 0));

        $message = $this->make();
        $message->update(['send_requested_at' => now()]);

        $this->flush();

        $this->assertCount(2, $this->messages->sent);

        $message->refresh();
        $this->assertNull($message->send_requested_at);
        $this->assertNull($message->last_sent_on);

        $this->travelTo(now()->setTime(17, 0));
        $this->flush();

        $this->assertCount(4, $this->messages->sent);
    }

    public function test_the_panel_saves_lists_asks_to_send_now_and_deletes(): void
    {
        $this->signIn();

        $payload = [
            'name' => 'Report',
            'text' => 'Отчет эсдан чикмасин',
            'send_at' => '17:00',
            'days' => [1, 2, 3, 4, 5],
            'chat_ids' => [self::FIRST],
            'is_active' => true,
        ];

        $this->postJson('/api/telegram/scheduled-messages', $payload)
            ->assertCreated()
            ->assertJsonPath('data.send_at', '17:00')
            ->assertJsonPath('data.chat_ids', [self::FIRST]);

        $this->getJson('/api/telegram/scheduled-messages')
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('groups.0.id', self::FIRST);

        $this->postJson('/api/telegram/scheduled-messages', ['chat_ids' => ['x']] + $payload)
            ->assertUnprocessable();

        $id = TelegramScheduledMessage::query()->value('id');

        $this->postJson("/api/telegram/scheduled-messages/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.queued', true);

        $this->deleteJson("/api/telegram/scheduled-messages/{$id}")->assertOk();

        $this->assertSame(0, TelegramScheduledMessage::query()->count());
    }

    public function test_a_message_saved_after_its_time_waits_for_tomorrow(): void
    {
        $this->signIn();

        $this->travelTo(now()->setTime(18, 0));

        $this->postJson('/api/telegram/scheduled-messages', [
            'name' => 'Late',
            'text' => 'x',
            'send_at' => '17:00',
            'days' => [],
            'chat_ids' => [self::FIRST],
            'is_active' => true,
        ])->assertCreated();

        $this->flush();

        $this->assertSame([], $this->messages->sent);
    }

    public function test_an_empty_slot_is_saved_but_never_goes_out(): void
    {
        $this->signIn();

        $this->travelTo(now()->next('Monday')->setTime(9, 0));

        $this->postJson('/api/telegram/scheduled-messages', [
            'name' => 'Slot',
            'text' => '',
            'send_at' => '10:00',
            'days' => [1, 2, 3, 4, 5],
            'chat_ids' => [self::FIRST],
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.text', '')
            ->assertJsonPath('data.has_text', false)
            ->assertJsonPath('data.next_at', null);

        $id = TelegramScheduledMessage::query()->value('id');

        $this->postJson("/api/telegram/scheduled-messages/{$id}/send")->assertUnprocessable();

        $this->travelTo(now()->setTime(10, 0));
        $this->flush();

        $this->assertSame([], $this->messages->sent);
        $this->assertNull(TelegramScheduledMessage::query()->find($id)->last_status);
    }

    public function test_a_slot_filled_before_its_time_goes_out_today_after_it_tomorrow(): void
    {
        $this->signIn();

        $monday = now()->next('Monday');

        $this->travelTo($monday->copy()->setTime(8, 0));

        $early = $this->make(['name' => 'Early', 'text' => '', 'send_at' => '10:00', 'days' => [1, 2, 3, 4, 5], 'chat_ids' => [self::FIRST]]);
        $late = $this->make(['name' => 'Late', 'text' => '', 'send_at' => '10:00', 'days' => [1, 2, 3, 4, 5], 'chat_ids' => [self::SECOND]]);

        $fill = fn (TelegramScheduledMessage $m, string $text) => $this->putJson("/api/telegram/scheduled-messages/{$m->id}", [
            'name' => $m->name,
            'text' => $text,
            'send_at' => $m->send_at,
            'days' => $m->weekdays(),
            'chat_ids' => $m->targets(),
            'is_active' => true,
        ])->assertOk();

        $this->travelTo($monday->copy()->setTime(9, 0));
        $fill($early, 'Утреннее');

        $this->travelTo($monday->copy()->setTime(10, 30));
        $fill($late, 'Опоздавшее');

        $this->flush();

        $this->assertSame([self::FIRST], $this->messages->peers());
        $this->assertSame('Утреннее', $this->messages->sent[0]['message']);

        $this->travelTo($monday->copy()->addDay()->setTime(10, 0));
        $this->flush();

        $this->assertSame([self::FIRST, self::FIRST, self::SECOND], $this->messages->peers());
    }

    public function test_the_migration_sets_up_two_empty_weekday_slots_from_the_files_account(): void
    {
        Schema::dropIfExists('telegram_settings');
        (require base_path('database/migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (require base_path('database/migrations/2026_10_19_100000_add_listener_key_to_telegram_file_routes.php'))->up();

        $accountId = DB::table('telegram_accounts')->insertGetId(['created_at' => now(), 'updated_at' => now()]);

        TelegramListener::query()->create([
            'key' => 'ceo',
            'name' => 'CEO',
            'telegram_account_id' => $accountId,
            'is_enabled' => true,
            'duties' => [],
        ]);

        TelegramFileRoute::query()->create([
            'name' => 'Zitic',
            'keywords' => 'zitic',
            'target_chat_ids' => [-5243539111],
            'listener_key' => 'ceo',
            'is_active' => true,
            'sort' => 0,
        ]);

        $migration = require base_path('database/migrations/2026_11_02_100000_add_file_sender_scheduled_message_slots.php');

        $migration->up();
        $migration->up();

        $slots = TelegramScheduledMessage::query()->orderBy('send_at')->get();

        $this->assertSame(['10:00', '16:00'], $slots->pluck('send_at')->all());

        foreach ($slots as $slot) {
            $this->assertSame('', $slot->text);
            $this->assertSame([1, 2, 3, 4, 5], $slot->weekdays());
            $this->assertSame([-5243539111], $slot->targets());
            $this->assertSame('ceo', $slot->listener_key);
            $this->assertTrue($slot->is_active);
        }

        $slots->first()->update(['text' => 'Заполнено']);

        $migration->down();

        $this->assertSame(['Заполнено'], TelegramScheduledMessage::query()->pluck('text')->all());
    }

    private function signIn(): void
    {
        foreach (['users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        $role = Role::query()->create(['name' => 'driverCheck']);

        $this->actingAs(User::query()->create([
            'name' => 'Checker',
            'role_id' => $role->id,
        ]));
    }
}
