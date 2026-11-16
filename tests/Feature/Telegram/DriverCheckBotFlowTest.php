<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Actions\HandleDriverCheckBotCallback;
use App\Application\Telegram\Actions\ProcessUpdatedDriverMessage;
use App\Application\Telegram\Services\DriverCheckBot;
use App\Application\Telegram\Services\TelegramDriverCheckReporter;
use App\Application\Telegram\Services\TelegramDriverUpdateParser;
use App\Application\Telegram\Services\TelegramMessageTypeDetector;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Enums\Drivers\TelegramDriverMessageType;
use App\Jobs\Telegram\ResolveTelegramPhoneJob;
use App\Models\Driver\TelegramDriver;
use App\Models\Driver\TelegramDriverCheck;
use App\Models\Telegram\TelegramResolvedPhone;
use App\Telegram\TelegramDriverCheckHandler;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Support\DriverCheckColumns;
use Tests\TestCase;

/**
 * The group can overrule a check (bot buttons), a failed check can be
 * run again, and a phone fixed after the check re-runs it - always on
 * the same record, so it ends up holding the latest verdict.
 */
final class DriverCheckBotFlowTest extends TestCase
{
    private const CHAT = -1001234567890;

    private const UPDATE_TEXT = <<<'TEXT'
        #UPDATED  RAJABOV  JONIBEK SHODIEVICH

        👤 Изменены данные водителя

        • Номер телефона 1
        +998944491141 ⟶ +998952760019

        Пользователь:
        MUSAYEV ISMOIL DILSHOD O'G'LI
        TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['telegram_driver_checks', 'telegram_resolved_phones', 'telegram_drivers', 'operation_users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('operation_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('role', 16)->default('operation');
            $table->string('telegram_username')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dm_enabled')->default(true);
            $table->timestamp('dm_last_sent_at')->nullable();
            $table->text('dm_last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_drivers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_normalized');
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('telegram_resolved_phones', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_normalized');
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->text('telegram_raw')->nullable();
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_driver_checks', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->string('type');
            $table->text('message_text')->nullable();
            $table->string('phone_raw')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('driver_name')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->unsignedBigInteger('telegram_resolved_phone_id')->nullable();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->text('telegram_raw')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            DriverCheckColumns::lifecycle($table);
            $table->timestamps();
        });
    }

    /* =================================================================
     | Reading the update message
     |================================================================ */

    public function test_the_update_message_is_a_driver_update(): void
    {
        $this->assertSame(
            TelegramDriverMessageType::UPDATED_DRIVER,
            app(TelegramMessageTypeDetector::class)->detect(self::UPDATE_TEXT),
        );
    }

    public function test_the_phone_change_is_read_from_the_update_message(): void
    {
        $change = app(TelegramDriverUpdateParser::class)->parsePhoneChange(self::UPDATE_TEXT);

        $this->assertSame('RAJABOV JONIBEK SHODIEVICH', $change['driver_name']);
        $this->assertSame('+998944491141', $change['old_phone_normalized']);
        $this->assertSame('+998952760019', $change['new_phone_normalized']);
    }

    public function test_an_update_that_does_not_touch_the_phone_is_no_phone_change(): void
    {
        $text = "#UPDATED RAJABOV JONIBEK\n\n👤 Изменены данные водителя\n\n• Имя\nJONIBEK ⟶ JONIBEKJON\n\nПользователь: X";

        $this->assertNull(app(TelegramDriverUpdateParser::class)->parsePhoneChange($text));
    }

    public function test_a_change_of_the_second_phone_is_ignored(): void
    {
        $text = "#UPDATED RAJABOV JONIBEK\n\n👤 Изменены данные водителя\n\n• Номер телефона 2\n+998944491141 ⟶ +998952760019";

        $this->assertNull(app(TelegramDriverUpdateParser::class)->parsePhoneChange($text));
    }

    /* =================================================================
     | Phone changed: the original check runs again
     |================================================================ */

    public function test_a_phone_change_reruns_the_check_that_flagged_the_driver(): void
    {
        Queue::fake();

        $original = $this->reportedCheck([
            'phone_normalized' => '+998944491141',
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'telegram_raw' => ['resolver_result' => 'phone_not_registered'],
        ]);

        $update = $this->updateRow();

        app(ProcessUpdatedDriverMessage::class)->execute($this->deadTelegram(), $update, self::UPDATE_TEXT);

        $original->refresh();

        $this->assertSame('+998952760019', $original->phone_normalized);
        $this->assertSame(TelegramDriverCheckStatus::Pending, $original->status);

        // The next report is a new message, under the change.
        $this->assertNull($original->reported_at);
        $this->assertNull($original->report_message_id);
        $this->assertNull($original->bot_message_id);
        $this->assertSame(900, (int) $original->report_reply_to_message_id);

        // What the first run said is kept.
        $this->assertCount(1, $original->history());
        $this->assertSame('not_confirmed', $original->history()[0]['status']);
        $this->assertSame('+998944491141', $original->history()[0]['phone']);
        $this->assertSame('phone_changed', $original->history()[0]['trigger']);
        $this->assertArrayNotHasKey('resolver_result', $original->telegram_raw);

        $this->assertSame($original->id, $update->fresh()->telegram_raw['rerun_check_id']);
        $this->assertSame(TelegramDriverCheckStatus::Skipped, $update->fresh()->status);

        Queue::assertPushed(
            ResolveTelegramPhoneJob::class,
            fn (ResolveTelegramPhoneJob $job): bool => $job->checkId === $original->id,
        );
    }

    public function test_error_error_success_leaves_success_on_the_record(): void
    {
        Queue::fake();

        $original = $this->reportedCheck([
            'phone_normalized' => '+998944491141',
            'status' => TelegramDriverCheckStatus::NotConfirmed,
        ]);

        // The new phone is already known, so the re-run finishes at once.
        TelegramResolvedPhone::query()->create([
            'phone_normalized' => '+998952760019',
            'telegram_user_id' => 42,
            'telegram_first_name' => 'Jonibek',
        ]);

        app(ProcessUpdatedDriverMessage::class)->execute($this->deadTelegram(), $this->updateRow(), self::UPDATE_TEXT);

        $original->refresh();

        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $original->status);
        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $original->system_status);
        $this->assertSame('confirmed', $original->driver->status);
        $this->assertSame(1, TelegramDriverCheck::query()->where('driver_id', $original->driver_id)->count());
    }

    public function test_an_update_without_a_phone_change_leaves_the_check_alone(): void
    {
        Queue::fake();

        $original = $this->reportedCheck(['phone_normalized' => '+998944491141']);
        $text = "#UPDATED RAJABOV JONIBEK SHODIEVICH\n\n👤 Изменены данные водителя\n\n• Имя\nA ⟶ B";

        app(ProcessUpdatedDriverMessage::class)->execute($this->deadTelegram(), $this->updateRow(), $text);

        $this->assertSame('+998944491141', $original->fresh()->phone_normalized);
        $this->assertSame([], $original->fresh()->history());
        Queue::assertNothingPushed();
    }

    public function test_a_change_for_an_unknown_driver_is_checked_on_its_own(): void
    {
        Queue::fake();

        $update = $this->updateRow();

        app(ProcessUpdatedDriverMessage::class)->execute($this->deadTelegram(), $update, self::UPDATE_TEXT);

        $update->refresh();

        $this->assertSame(TelegramDriverCheckStatus::Pending, $update->status);
        $this->assertSame('+998952760019', $update->phone_normalized);
        $this->assertNotNull($update->driver_id);
        $this->assertNotNull($update->operation_user_id);
        Queue::assertPushed(ResolveTelegramPhoneJob::class);
    }

    /* =================================================================
     | Buttons
     |================================================================ */

    public function test_confirm_overrides_the_system_verdict_and_marks_the_report(): void
    {
        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);

        $this->press($check, DriverCheckBot::ACTION_CONFIRM);

        $check->refresh();

        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $check->status);
        $this->assertSame(TelegramDriverCheckStatus::NotConfirmed, $check->system_status);
        $this->assertSame('Ali Valiyev (@ali)', $check->manual_by_name);
        $this->assertSame(777, (int) $check->manual_by_telegram_id);
        $this->assertNotNull($check->report_dirty_at);
        $this->assertSame('confirmed', $check->driver->status);
    }

    public function test_the_report_strikes_the_verdict_a_person_overrode(): void
    {
        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);

        $this->press($check, DriverCheckBot::ACTION_CONFIRM);

        $report = $this->reportText($check->fresh());

        $this->assertStringContainsString(
            '<b>Статус:</b> <s>❌ НЕ ПОДТВЕРЖДЕНО</s> ✅ ПОДТВЕРЖДЕНО',
            $report,
        );
        $this->assertStringContainsString('Подтвердил вручную:</b> Ali Valiyev (@ali)', $report);
    }

    public function test_a_press_on_another_message_changes_nothing(): void
    {
        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);

        $this->press($check, DriverCheckBot::ACTION_CONFIRM, messageId: 1);
        $this->press($check, DriverCheckBot::ACTION_CONFIRM, chatId: -100999);

        $this->assertSame(TelegramDriverCheckStatus::NotConfirmed, $check->fresh()->status);
    }

    public function test_recheck_reruns_a_check_that_failed_for_a_technical_reason(): void
    {
        Queue::fake();

        $check = $this->reportedCheck([
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'error_message' => 'The operation was cancelled',
            'telegram_raw' => ['resolver_result' => 'resolver_failed_without_match'],
        ]);

        $this->assertTrue($check->hasTechnicalFailure());
        $this->assertContains(
            '🔄 Перепроверить',
            array_column(app(DriverCheckBot::class)->keyboard($check)[0], 'text'),
        );

        $this->press($check, DriverCheckBot::ACTION_RECHECK);

        $check->refresh();

        $this->assertSame(TelegramDriverCheckStatus::Pending, $check->status);
        $this->assertNull($check->error_message);
        $this->assertSame('recheck', $check->history()[0]['trigger']);
        $this->assertSame('The operation was cancelled', $check->history()[0]['error_message']);

        // Same report, edited in place.
        $this->assertNotNull($check->reported_at);
        $this->assertNotNull($check->report_dirty_at);
        Queue::assertPushed(ResolveTelegramPhoneJob::class);
    }

    public function test_recheck_is_refused_when_the_check_really_found_no_match(): void
    {
        Queue::fake();

        $check = $this->reportedCheck([
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'telegram_raw' => ['name_match' => ['score' => 0]],
        ]);

        $this->assertNotContains(
            '🔄 Перепроверить',
            array_column(app(DriverCheckBot::class)->keyboard($check)[0], 'text'),
        );

        $this->press($check, DriverCheckBot::ACTION_RECHECK);

        $this->assertSame(TelegramDriverCheckStatus::NotConfirmed, $check->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_new_system_verdict_ends_a_manual_override(): void
    {
        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);

        $this->press($check, DriverCheckBot::ACTION_CONFIRM);

        $check->refresh();
        $check->update(['status' => TelegramDriverCheckStatus::Pending]);

        $this->assertNull($check->fresh()->manual_at);
        $this->assertNull($check->fresh()->manual_by_name);
    }

    /* =================================================================
     | Webhook
     |================================================================ */

    public function test_the_webhook_rejects_a_wrong_secret(): void
    {
        /* The owner switched the secret check off on purpose (2026-09-29); kept for when it comes back. */
        $this->markTestSkipped('The webhook secret check is switched off on purpose.');

        config(['services.telegram.bot_webhook_secret' => 'right']);

        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);

        $update = ['update_id' => 1, 'callback_query' => $this->callbackQuery($check, DriverCheckBot::ACTION_CONFIRM)];

        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();

        $this->assertSame(TelegramDriverCheckStatus::NotConfirmed, $check->fresh()->status);

        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'right',
        ])->assertOk();

        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $check->fresh()->status);
    }

    public function test_the_gateway_may_send_the_secret_as_a_bearer_token(): void
    {
        /* The owner switched the secret check off on purpose (2026-09-29); kept for when it comes back. */
        $this->markTestSkipped('The webhook secret check is switched off on purpose.');

        config(['services.telegram.bot_webhook_secret' => 'right']);

        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);
        $update = ['update_id' => 1, 'callback_query' => $this->callbackQuery($check, DriverCheckBot::ACTION_CONFIRM)];

        $this->postJson('/api/telegram/webhook', $update, ['Authorization' => 'Bearer wrong'])
            ->assertForbidden();

        $this->postJson('/api/telegram/webhook', $update, ['Authorization' => 'Bearer right'])
            ->assertOk();

        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $check->fresh()->status);
    }

    public function test_the_bot_greets_whoever_writes_to_it_privately(): void
    {
        $api = $this->fakeBotApi();

        $api->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params): bool => $params['chat_id'] === 555
                && str_starts_with($params['text'], 'Salom, Ali!'));

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 2,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Ali'],
                'text' => 'salom',
            ],
        ])->assertOk();
    }

    public function test_the_bot_stays_quiet_in_groups(): void
    {
        $api = $this->fakeBotApi();

        $api->shouldNotReceive('sendMessage');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => self::CHAT, 'type' => 'supergroup'],
                'from' => ['id' => 555, 'first_name' => 'Ali'],
                'text' => 'salom',
            ],
        ])->assertOk();
    }

    public function test_the_buttons_go_out_right_after_the_report_not_through_the_queue(): void
    {
        Queue::fake();

        $api = $this->fakeBotApi();

        // Just reported: no bot message under it yet.
        $check = $this->reportedCheck([
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'bot_message_id' => null,
        ]);

        $api->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params): bool => $params['chat_id'] === self::CHAT)
            ->andReturn(new \Telegram\Bot\Objects\Message(['message_id' => 77]));

        app(DriverCheckBot::class)->syncNow($check);

        $this->assertSame(77, (int) $check->bot_message_id);
        $this->assertSame(77, (int) $check->fresh()->bot_message_id);
        Queue::assertNotPushed(\App\Jobs\Telegram\SyncDriverCheckBotMessage::class);
    }

    public function test_buttons_the_bot_api_refused_are_left_to_the_queue(): void
    {
        Queue::fake();

        $api = $this->fakeBotApi();

        // Just reported: no bot message under it yet.
        $check = $this->reportedCheck([
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'bot_message_id' => null,
        ]);

        $api->shouldReceive('sendMessage')->once()->andThrow(new \RuntimeException('Bad Gateway'));

        app(DriverCheckBot::class)->syncNow($check);

        $this->assertNull($check->fresh()->bot_message_id);
        Queue::assertPushedOn('telegram', \App\Jobs\Telegram\SyncDriverCheckBotMessage::class);
    }

    public function test_reports_still_work_when_the_bot_sdk_is_missing(): void
    {
        // A server where composer install has not pulled the SDK in yet.
        $this->app->bind(\Telegram\Bot\BotsManager::class, function (): never {
            throw new \Illuminate\Contracts\Container\BindingResolutionException(
                'Target class [Telegram\Bot\BotsManager] does not exist.',
            );
        });

        $this->assertFalse(app(DriverCheckBot::class)->enabled());
        $this->assertInstanceOf(TelegramDriverCheckReporter::class, app(TelegramDriverCheckReporter::class));

        // Buttons still work on the database side; only the bot is silent.
        $check = $this->reportedCheck(['status' => TelegramDriverCheckStatus::NotConfirmed]);
        $this->press($check, DriverCheckBot::ACTION_CONFIRM);
        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $check->fresh()->status);
    }

    /* =================================================================
     | MadelineProto plumbing
     |================================================================ */

    public function test_the_sent_report_id_is_read_from_every_shape_of_updates(): void
    {
        $this->assertSame(5, TelegramDriverCheckReporter::sentMessageId([
            '_' => 'updateShortSentMessage', 'id' => 5,
        ]));

        $this->assertSame(6, TelegramDriverCheckReporter::sentMessageId([
            '_' => 'updates',
            'updates' => [
                ['_' => 'updateMessageID', 'id' => 6, 'random_id' => 1],
                ['_' => 'updateNewChannelMessage', 'message' => ['id' => 6]],
            ],
        ]));

        $this->assertSame(7, TelegramDriverCheckReporter::sentMessageId([
            '_' => 'updates',
            'updates' => [['_' => 'updateNewMessage', 'message' => ['id' => 7]]],
        ]));

        $this->assertNull(TelegramDriverCheckReporter::sentMessageId(null));
    }

    /* ================================================================= */

    private function reportedCheck(array $attributes = []): TelegramDriverCheck
    {
        $driver = TelegramDriver::query()->create([
            'name' => 'RAJABOV JONIBEK SHODIEVICH',
            'name_normalized' => 'RAJABOV JONIBEK SHODIEVICH',
            'status' => 'not_confirmed',
        ]);

        return TelegramDriverCheck::query()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_message_id' => 100,
            'type' => TelegramDriverMessageType::CREATED_DRIVER,
            'driver_name' => 'RAJABOV JONIBEK SHODIEVICH',
            'driver_id' => $driver->id,
            'phone_normalized' => '+998944491141',
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'checked_at' => now(),
            'reported_at' => now(),
            'report_message_id' => 101,
            'bot_message_id' => 102,
            'telegram_raw' => [],
            ...$attributes,
        ]);
    }

    private function updateRow(): TelegramDriverCheck
    {
        return TelegramDriverCheck::query()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_message_id' => 900,
            'type' => TelegramDriverMessageType::UPDATED_DRIVER,
            'message_text' => self::UPDATE_TEXT,
            'status' => TelegramDriverCheckStatus::Skipped,
            'telegram_raw' => [],
        ]);
    }

    private function press(
        TelegramDriverCheck $check,
        string $action,
        ?int $messageId = null,
        ?int $chatId = null,
    ): void {
        app(HandleDriverCheckBotCallback::class)->execute(
            $this->callbackQuery($check, $action, $messageId, $chatId),
        );
    }

    /**
     * A Bot API CallbackQuery for a press on the check's bot message.
     */
    private function callbackQuery(
        TelegramDriverCheck $check,
        string $action,
        ?int $messageId = null,
        ?int $chatId = null,
    ): array {
        return [
            'id' => 'cb-1',
            'data' => DriverCheckBot::callbackData($action, $check->id),
            'from' => ['id' => 777, 'first_name' => 'Ali', 'last_name' => 'Valiyev', 'username' => 'ali'],
            'message' => [
                'message_id' => $messageId ?? $check->bot_message_id,
                'chat' => ['id' => $chatId ?? self::CHAT],
            ],
        ];
    }

    private function reportText(TelegramDriverCheck $check): string
    {
        $method = (new ReflectionClass(TelegramDriverCheckReporter::class))->getMethod('buildMessage');

        return $method->invoke(app(TelegramDriverCheckReporter::class), $check, null);
    }

    /**
     * A bot with a token, whose Bot API calls land on a mock.
     */
    private function fakeBotApi(): \Mockery\MockInterface
    {
        config(['telegram.bots.' . config('telegram.default') . '.token' => 'test-token']);

        $api = \Mockery::mock(\Telegram\Bot\Api::class);
        $bot = new DriverCheckBot;

        (new \ReflectionProperty(DriverCheckBot::class, 'api'))->setValue($bot, $api);

        $this->app->instance(DriverCheckBot::class, $bot);

        return $api;
    }

    /**
     * A listener whose every call fails; see DriverCheckReportQueueTest.
     */
    private function deadTelegram(): SimpleEventHandler
    {
        return (new ReflectionClass(TelegramDriverCheckHandler::class))
            ->newInstanceWithoutConstructor();
    }
}
