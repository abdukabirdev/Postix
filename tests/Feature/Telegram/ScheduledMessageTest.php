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

    /** @var array<int, list<array<string, mixed>>> what each group's history holds */
    public array $history = [];

    public function sendMessage(array $params): array
    {
        if (in_array($params['peer'], $this->failFor, true)) {
            throw new RuntimeException('CHAT_WRITE_FORBIDDEN');
        }

        $this->sent[] = $params;

        return [];
    }

    public function getHistory(array $params): array
    {
        return ['messages' => $this->history[$params['peer']] ?? []];
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
        (require base_path('database/migrations/2026_11_13_100000_add_variants_to_telegram_scheduled_messages_table.php'))->up();
        (require base_path('database/migrations/2026_11_13_110000_add_jitter_and_file_skip_to_telegram_scheduled_messages_table.php'))->up();

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

    public function test_each_group_gets_one_of_the_texts(): void
    {
        $message = $this->make([
            'text' => 'Отчётлар эсдан чиқмасин',
            'variants' => ['Ҳисоботларни унутманг', 'Отчётлар қани?'],
        ]);

        $this->assertSame(
            ['Отчётлар эсдан чиқмасин', 'Ҳисоботларни унутманг', 'Отчётлар қани?'],
            $message->texts(),
        );

        $this->travelTo(now()->setTime(17, 0));
        $this->flush();

        $this->assertCount(2, $this->messages->sent);

        foreach ($this->messages->sent as $call) {
            $this->assertContains($call['message'], $message->texts());
        }

        /* Over many picks every text comes up, and only these. */
        $seen = [];

        for ($i = 0; $i < 300; $i++) {
            $seen[$message->pickText()] = true;
        }

        $this->assertEqualsCanonicalizing($message->texts(), array_keys($seen));
    }

    public function test_variants_alone_are_a_text_and_blank_ones_do_not_count(): void
    {
        $this->assertFalse($this->make(['text' => '', 'variants' => ['', '  ']])->hasText());

        $message = $this->make(['text' => '', 'variants' => ['', 'Нима бўлди отчётлар?']]);

        $this->assertTrue($message->hasText());
        $this->assertSame(['Нима бўлди отчётлар?'], $message->texts());

        $this->travelTo(now()->setTime(17, 0));
        $this->flush();

        $this->assertSame(['Нима бўлди отчётлар?', 'Нима бўлди отчётлар?'], array_column($this->messages->sent, 'message'));
    }

    public function test_the_panel_saves_variants_trimmed_without_blanks_and_keeps_them_when_left_out(): void
    {
        $this->signIn();

        $payload = [
            'name' => 'Report',
            'text' => 'Отчётлар эсдан чиқмасин',
            'variants' => ['  Ҳисоботларни унутманг  ', '', 'Ҳисоботларни унутманг', 'Отчётлар қани?'],
            'send_at' => '17:00',
            'days' => [1, 2, 3, 4, 5],
            'chat_ids' => [self::FIRST],
            'is_active' => true,
        ];

        $this->postJson('/api/telegram/scheduled-messages', $payload)
            ->assertCreated()
            ->assertJsonPath('data.variants', ['Ҳисоботларни унутманг', 'Отчётлар қани?']);

        $id = TelegramScheduledMessage::query()->value('id');

        /* The switch on the page sends the row back with its variants; a client that does not send them changes nothing. */
        unset($payload['variants']);

        $this->putJson("/api/telegram/scheduled-messages/{$id}", ['is_active' => false] + $payload)
            ->assertOk()
            ->assertJsonPath('data.variants', ['Ҳисоботларни унутманг', 'Отчётлар қани?']);

        $this->putJson("/api/telegram/scheduled-messages/{$id}", ['variants' => []] + $payload)
            ->assertOk()
            ->assertJsonPath('data.variants', []);

        $this->postJson('/api/telegram/scheduled-messages', ['variants' => 'x'] + $payload)->assertUnprocessable();
    }

    public function test_the_jitter_moves_the_time_a_little_differently_each_day_but_the_same_all_day(): void
    {
        $message = $this->make(['jitter_minutes' => 5]);

        $day = now()->startOfDay();
        $shifts = [];

        for ($i = 0; $i < 40; $i++) {
            $at = $message->runAt($day->copy()->addDays($i));

            $shift = (int) $day->copy()->addDays($i)->setTime(17, 0)->diffInMinutes($at, false);

            $this->assertGreaterThanOrEqual(-5, $shift);
            $this->assertLessThanOrEqual(5, $shift);
            $this->assertTrue($at->equalTo($message->runAt($day->copy()->addDays($i)->setTime(23, 0))));

            $shifts[$shift] = true;
        }

        $this->assertGreaterThan(3, count($shifts));

        /* No jitter: on the minute. */
        $this->assertSame('17:00', (new TelegramScheduledMessage(['send_at' => '17:00']))->runAt(now())->format('H:i'));

        /* It is due from its own shifted minute, not before. */
        $at = $message->runAt(now());

        $this->travelTo($at->copy()->subMinute());
        $this->flush();
        $this->assertSame([], $this->messages->sent);

        $this->travelTo($at);
        $this->flush();
        $this->assertCount(2, $this->messages->sent);
    }

    public function test_the_file_skip_leaves_out_a_group_with_a_report_but_writes_the_others(): void
    {
        $this->travelTo(now()->setTime(17, 0));

        $message = $this->make(['skip_if_file_today' => true]);

        /* The morning reminder went into both groups today: a file after it is the report. */
        $morning = $this->make(['name' => 'Morning', 'send_at' => '09:59', 'last_sent_on' => now()->toDateString()]);

        $report = ['_' => 'message', 'id' => 5, 'date' => now()->subHour()->getTimestamp(), 'out' => false, 'media' => ['_' => 'messageMediaDocument', 'document' => ['attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'otchet.xlsx']]]]];

        /* A voice message and an old file do not count; neither does our own. */
        $voice = ['_' => 'message', 'id' => 6, 'date' => now()->subHour()->getTimestamp(), 'out' => false, 'media' => ['_' => 'messageMediaDocument', 'document' => ['attributes' => [['_' => 'documentAttributeAudio', 'voice' => true]]]]];
        $old = ['date' => now()->subDay()->getTimestamp()] + $report;
        $mine = ['out' => true] + $report;
        /* A CMR posted by someone (its caption carries the request's hashtag) is no report either. */
        $cmr = ['message' => "#EGS26092200045\n\nМашину выгрузили. Спасибо за сотрудничество!"] + $report;

        $this->messages->history = [self::FIRST => [$voice, $old, $mine, $report], self::SECOND => [$voice, $old, $mine, $cmr]];

        $this->flush();

        $this->assertSame([self::SECOND], $this->messages->peers());
        $this->assertSame(TelegramScheduledMessage::STATUS_SENT, $message->refresh()->last_status);

        /* Everywhere a report: nothing goes out, it is no failure, and today is over for it. */
        $this->travelTo(now()->addDay()->setTime(17, 0));
        $morning->update(['last_sent_on' => now()->toDateString()]);
        $this->messages->sent = [];
        $report['date'] = now()->subHour()->getTimestamp();
        $this->messages->history = [self::FIRST => [$report], self::SECOND => [$report]];

        $this->flush();

        $message->refresh();
        $this->assertSame([], $this->messages->sent);
        $this->assertSame(TelegramScheduledMessage::STATUS_SKIPPED, $message->last_status);
        $this->assertNull($message->last_error);
        $this->assertSame(now()->toDateString(), $message->last_sent_on->toDateString());

        /* "Send now" is somebody asking: it writes. */
        $message->update(['send_requested_at' => now()]);
        $this->flush();

        $this->assertSame([self::FIRST, self::SECOND], $this->messages->peers());
    }

    /**
     * The owner, 2026-11-13: "after the first reminder they sent a file -
     * the next one is not needed". A file before it, or with no reminder
     * sent today, holds nothing back.
     */
    public function test_only_a_file_after_the_day_first_reminder_holds_the_next_one_back(): void
    {
        $this->travelTo(now()->setTime(17, 0));

        $message = $this->make(['skip_if_file_today' => true]);
        $file = fn (string $at): array => ['_' => 'message', 'id' => 5, 'date' => now()->setTimeFromTimeString($at)->getTimestamp(), 'out' => false, 'media' => ['_' => 'messageMediaDocument', 'document' => ['attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'otchet.xlsx']]]]];

        /* No reminder went today: the file of 11:00 is not "after the first one". */
        $this->messages->history = [self::FIRST => [$file('11:00')], self::SECOND => [$file('11:00')]];
        $this->flush();
        $this->assertSame([self::FIRST, self::SECOND], $this->messages->peers());

        /* The next day the morning one went at 09:59 to the first group only: a file at 09:30 there is before it, one at 11:00 after it. */
        $this->travelTo(now()->addDay()->setTime(17, 0));
        $this->messages->sent = [];
        $this->make(['name' => 'Morning', 'send_at' => '09:59', 'chat_ids' => [self::FIRST], 'last_sent_on' => now()->toDateString()]);

        $this->messages->history = [self::FIRST => [$file('09:30')], self::SECOND => [$file('11:00')]];
        $this->flush();
        $this->assertSame([self::FIRST, self::SECOND], $this->messages->peers(), 'before the reminder, or in a group it did not go to');

        $this->travelTo(now()->addDay()->setTime(17, 0));
        $this->messages->sent = [];
        TelegramScheduledMessage::query()->where('name', 'Morning')->update(['last_sent_on' => now()->toDateString()]);

        $this->messages->history = [self::FIRST => [$file('11:00')], self::SECOND => [$file('11:00')]];
        $this->flush();
        $this->assertSame([self::SECOND], $this->messages->peers());
    }

    public function test_the_migration_turns_the_skip_on_for_the_noon_and_evening_reminders_only(): void
    {
        $morning = $this->make(['text' => 'Отчетлар эсдан чикмасин, соат 5 да сорайман']);
        $noon = $this->make(['text' => 'Эслатиб койабман']);
        $evening = $this->make(['text' => 'Нима болди отчетлар?']);
        $other = $this->make(['text' => 'Бошка нарса']);

        (require database_path('migrations/2026_11_13_150000_skip_report_reminders_after_a_file.php'))->up();

        $this->assertFalse($morning->refresh()->skip_if_file_today);
        $this->assertTrue($noon->refresh()->skip_if_file_today);
        $this->assertTrue($evening->refresh()->skip_if_file_today);
        $this->assertFalse($other->refresh()->skip_if_file_today);
    }

    public function test_an_unreadable_group_does_not_hold_the_reminder_back(): void
    {
        $this->travelTo(now()->setTime(17, 0));

        $this->make(['skip_if_file_today' => true]);

        $this->flush();

        $this->assertSame([self::FIRST, self::SECOND], $this->messages->peers());
    }

    public function test_the_panel_saves_the_jitter_and_the_file_skip_and_keeps_them_when_left_out(): void
    {
        $this->signIn();

        $payload = [
            'name' => 'Report',
            'text' => 'x',
            'send_at' => '16:59',
            'jitter_minutes' => 5,
            'skip_if_file_today' => true,
            'days' => [1, 2, 3, 4, 5],
            'chat_ids' => [self::FIRST],
            'is_active' => true,
        ];

        $this->postJson('/api/telegram/scheduled-messages', $payload)
            ->assertCreated()
            ->assertJsonPath('data.jitter_minutes', 5)
            ->assertJsonPath('data.skip_if_file_today', true);

        $id = TelegramScheduledMessage::query()->value('id');

        unset($payload['jitter_minutes'], $payload['skip_if_file_today']);

        $this->putJson("/api/telegram/scheduled-messages/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.jitter_minutes', 5)
            ->assertJsonPath('data.skip_if_file_today', true);

        $this->putJson("/api/telegram/scheduled-messages/{$id}", ['jitter_minutes' => 0, 'skip_if_file_today' => false] + $payload)
            ->assertOk()
            ->assertJsonPath('data.jitter_minutes', 0)
            ->assertJsonPath('data.skip_if_file_today', false);

        $this->putJson("/api/telegram/scheduled-messages/{$id}", ['jitter_minutes' => 31] + $payload)->assertUnprocessable();
    }

    public function test_the_migration_gives_the_three_report_reminders_variants_once(): void
    {
        $morning = $this->make(['name' => 'A', 'text' => 'Отчетлар эсдан чикмасин, соат 5 да сорайман', 'send_at' => '09:59']);
        $noon = $this->make(['name' => 'B', 'text' => 'Эслатиб койабман', 'send_at' => '14:24']);
        $evening = $this->make(['name' => 'C', 'text' => 'Нима болди отчетлар?', 'send_at' => '16:59']);
        $other = $this->make(['name' => 'D', 'text' => 'Юк келди']);
        $own = $this->make(['name' => 'E', 'text' => 'Нима болди очетлар?', 'variants' => ['Мой вариант']]);

        $migration = require base_path('database/migrations/2026_11_13_120000_seed_report_reminder_variants.php');

        $migration->up();

        foreach ([$morning, $noon, $evening] as $message) {
            $message->refresh();

            $this->assertGreaterThanOrEqual(6, count($message->variants));
            $this->assertSame(5, $message->jitter_minutes);
        }

        $this->assertContains('Нима бўлди отчётлар?', $evening->variants);
        /* Off: a work group gets other files all day; switched on per message in the panel. */
        $this->assertFalse($evening->skip_if_file_today);
        $this->assertFalse($morning->skip_if_file_today);
        $this->assertFalse($noon->skip_if_file_today);

        /* Something else, and what somebody wrote in the panel, stay as they are. */
        $this->assertSame(0, $other->refresh()->jitter_minutes);
        $this->assertSame([], (array) $other->variants);
        $this->assertSame(['Мой вариант'], $own->refresh()->variants);

        $evening->update(['variants' => ['Только мой']]);
        $migration->up();
        $this->assertSame(['Только мой'], $evening->refresh()->variants);
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
