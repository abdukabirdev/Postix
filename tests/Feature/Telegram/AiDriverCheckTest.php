<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use Anthropic\Core\Exceptions\PaymentRequiredException;
use App\Application\Telegram\Actions\ApplyResolvedTelegramPhone;
use App\Application\Telegram\Ai\AiModel;
use App\Application\Telegram\Ai\AiPrompts;
use App\Application\Telegram\Ai\AiReply;
use App\Application\Telegram\Ai\AiSettings;
use App\Application\Telegram\Ai\DriverNameJudge;
use App\Application\Telegram\Services\TelegramDriverCheckReporter;
use App\Application\Telegram\Services\TelegramNameMatcher;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Enums\Drivers\TelegramDriverMessageType;
use App\Jobs\Telegram\JudgeDriverNameJob;
use App\Models\Driver\TelegramDriver;
use App\Models\Driver\TelegramDriverCheck;
use App\Models\Telegram\TelegramAiCall;
use App\Models\Telegram\TelegramResolvedPhone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\Support\DriverCheckColumns;
use Tests\TestCase;
use Throwable;

/**
 * The driver check asks Claude only when the code found the names
 * different and Telegram shows a name; live mode takes its verdict, shadow
 * only writes it into the report, and money trouble pauses the AI.
 */
final class AiDriverCheckTest extends TestCase
{
    private FakeAiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['telegram_settings', 'telegram_alerts', 'telegram_ai_calls', 'telegram_driver_checks', 'telegram_resolved_phones', 'telegram_drivers', 'operation_users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('telegram_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind');
            $table->string('fingerprint');
            $table->string('title');
            $table->text('details')->nullable();
            $table->json('context')->nullable();
            $table->string('listener_key')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->text('notify_error')->nullable();
            $table->timestamps();
        });

        Schema::create('operation_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->timestamps();
        });

        Schema::create('telegram_ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('area', 32);
            $table->string('kind', 32)->nullable();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->boolean('used')->default(false);
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('request')->nullable();
            $table->json('answer')->nullable();
            $table->text('free_text')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
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

        config(['ai.api_key' => 'test-key', 'ai.monthly_cap' => 10, 'services.telegram.alerts' => []]);

        $this->model = new FakeAiModel;
        $this->app->instance(AiModel::class, $this->model);
    }

    public function test_nothing_is_asked_while_the_area_is_off(): void
    {
        $match = $this->codeMismatch();

        $this->assertFalse(app(DriverNameJudge::class)->wanted($match));
    }

    public function test_a_code_match_is_never_sent_to_the_ai(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);

        $match = app(TelegramNameMatcher::class)->match('SAYFULLAYEV IKRAMZHON', 'ikramjon', null, null);

        $this->assertTrue($match['matched']);
        $this->assertFalse(app(DriverNameJudge::class)->wanted($match));
    }

    public function test_no_telegram_name_means_nothing_to_ask(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);

        $match = app(TelegramNameMatcher::class)->match('RAJABOV JONIBEK', null, null, null);

        $this->assertFalse(app(DriverNameJudge::class)->wanted($match));
    }

    public function test_live_mode_takes_the_ai_verdict(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);
        $this->model->data = ['verdict' => 'match', 'reason' => 'Али — короткая форма имени Курбонали'];

        $match = app(DriverNameJudge::class)->judge(1, $this->codeMismatch(), 'Али', null, null);

        $this->assertTrue($match['matched']);
        $this->assertSame('ai', $match['matched_by']);
        $this->assertSame('match', $match['ai']['verdict']);
        $this->assertFalse($match['ai']['code_matched']);

        $call = TelegramAiCall::query()->sole();
        $this->assertSame('driver_check', $call->area);
        $this->assertTrue($call->used);
        $this->assertStringContainsString('GIYASOV KURBONALI', (string) $call->request);
        $this->assertStringContainsString('Telegram, имя: Али', (string) $call->request);
        $this->assertSame(app(AiPrompts::class)->get('driver_check'), $this->model->system[0]);
    }

    public function test_shadow_mode_only_writes_the_ai_opinion(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'shadow']);
        $this->model->data = ['verdict' => 'match', 'reason' => 'похоже'];

        $match = app(DriverNameJudge::class)->judge(1, $this->codeMismatch(), 'Али', null, null);

        $this->assertFalse($match['matched']);
        $this->assertSame('match', $match['ai']['verdict']);
        $this->assertFalse(TelegramAiCall::query()->sole()->used);
    }

    public function test_a_failed_call_leaves_the_code_verdict(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);
        $this->model->throw = new RuntimeException('timeout');

        $match = app(DriverNameJudge::class)->judge(1, $this->codeMismatch(), 'Али', null, null);

        $this->assertFalse($match['matched']);
        $this->assertNull($match['ai']['verdict']);
        $this->assertStringContainsString('timeout', $match['ai']['error']);
        $this->assertNull(app(AiSettings::class)->blocker());
    }

    public function test_no_money_pauses_the_ai_and_the_free_rules_answer(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);
        $this->model->throw = (new ReflectionClass(PaymentRequiredException::class))->newInstanceWithoutConstructor();

        app(DriverNameJudge::class)->judge(1, $this->codeMismatch(), 'Али', null, null);

        $this->assertSame('paused', app(AiSettings::class)->blocker());
        $this->assertNull(app(AiSettings::class)->effective('driver_check'));
        $this->assertFalse(app(DriverNameJudge::class)->wanted($this->codeMismatch()));

        app(AiSettings::class)->resume();
        $this->assertSame('live', app(AiSettings::class)->effective('driver_check'));
    }

    public function test_the_monthly_cap_stops_the_ai(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);
        app(AiSettings::class)->setCap(0.5);

        TelegramAiCall::query()->create(['area' => 'driver_check', 'mode' => 'live', 'status' => 'ok', 'cost_usd' => 0.6]);

        $this->assertSame('cap', app(AiSettings::class)->blocker());
    }

    public function test_the_cached_path_waits_for_the_queue(): void
    {
        Queue::fake();
        app(AiSettings::class)->setModes(['driver_check' => 'live']);

        [$check, $phone] = $this->checkWithPhone('Али');

        app(ApplyResolvedTelegramPhone::class)->execute($check, $phone);

        $this->assertSame(TelegramDriverCheckStatus::Processing, $check->fresh()->status);
        Queue::assertPushed(JudgeDriverNameJob::class, fn (JudgeDriverNameJob $job): bool => $job->checkId === $check->id);
        $this->assertSame(0, $this->model->calls);
    }

    public function test_the_job_writes_the_ai_verdict_and_the_report_says_why(): void
    {
        app(AiSettings::class)->setModes(['driver_check' => 'live']);
        $this->model->data = ['verdict' => 'match', 'reason' => 'Али — короткая форма имени Курбонали'];

        [$check, $phone] = $this->checkWithPhone('Али');

        // QUEUE_CONNECTION=sync: the job runs right away.
        app(ApplyResolvedTelegramPhone::class)->execute($check, $phone);

        $check->refresh();
        $this->assertSame(TelegramDriverCheckStatus::Confirmed, $check->status);
        $this->assertSame('confirmed', $check->driver->fresh()->status);

        $report = $this->report($check);

        $this->assertStringContainsString('<b>Причина:</b>', $report);
        $this->assertStringContainsString('• Проверка кодом: не совпадает', $report);
        $this->assertStringContainsString('• Вердикт ИИ: совпадает — Али — короткая форма имени Курбонали', $report);
        $this->assertStringNotContainsString('Short partial-name match', $report);
    }

    public function test_without_the_ai_the_code_decides_as_before(): void
    {
        Queue::fake();

        [$check, $phone] = $this->checkWithPhone('Али');

        app(ApplyResolvedTelegramPhone::class)->execute($check, $phone);

        $this->assertSame(TelegramDriverCheckStatus::NotConfirmed, $check->fresh()->status);
        Queue::assertNothingPushed();

        $report = $this->report($check->fresh());
        $this->assertStringContainsString('• Проверка кодом: не совпадает', $report);
        $this->assertStringNotContainsString('ИИ', $report);
    }

    public function test_a_number_not_in_telegram_says_so(): void
    {
        $check = TelegramDriverCheck::query()->create([
            'telegram_chat_id' => -100,
            'telegram_message_id' => 5,
            'type' => TelegramDriverMessageType::CREATED_DRIVER,
            'driver_name' => 'RAJABOV JONIBEK',
            'phone_normalized' => '+998900000000',
            'status' => TelegramDriverCheckStatus::NotConfirmed,
            'telegram_raw' => ['resolver_result' => 'phone_not_registered'],
        ]);

        $this->assertStringContainsString("<b>Причина:</b>\n• Номер не зарегистрирован в Telegram", $this->report($check));
    }

    public function test_an_edited_prompt_wins_and_reset_brings_the_default_back(): void
    {
        $prompts = app(AiPrompts::class);
        $default = $prompts->default('driver_check');

        $prompts->save('driver_check', 'Мой промпт');
        $this->assertSame('Мой промпт', $prompts->get('driver_check'));
        $this->assertTrue($prompts->all()['driver_check']['edited']);

        $prompts->reset('driver_check');
        $this->assertSame($default, $prompts->get('driver_check'));
    }

    /**
     * @return array<string, mixed>
     */
    private function codeMismatch(): array
    {
        return app(TelegramNameMatcher::class)->match('GIYASOV KURBONALI ABDURAHIMOVICH', 'Али', null, null);
    }

    /**
     * @return array{0: TelegramDriverCheck, 1: TelegramResolvedPhone}
     */
    private function checkWithPhone(string $firstName): array
    {
        $driver = TelegramDriver::query()->create([
            'name' => 'GIYASOV KURBONALI ABDURAHIMOVICH',
            'name_normalized' => 'GIYASOV KURBONALI ABDURAHIMOVICH',
            'status' => 'pending',
        ]);

        $phone = TelegramResolvedPhone::query()->create([
            'phone_normalized' => '+998901234567',
            'telegram_user_id' => 555,
            'telegram_first_name' => $firstName,
        ]);

        $check = TelegramDriverCheck::query()->create([
            'telegram_chat_id' => -100,
            'telegram_message_id' => 4567,
            'type' => TelegramDriverMessageType::CREATED_DRIVER,
            'driver_name' => 'GIYASOV KURBONALI ABDURAHIMOVICH',
            'driver_id' => $driver->id,
            'phone_normalized' => '+998901234567',
            'status' => TelegramDriverCheckStatus::Pending,
        ]);

        return [$check, $phone];
    }

    private function report(TelegramDriverCheck $check): string
    {
        $method = (new ReflectionClass(TelegramDriverCheckReporter::class))->getMethod('buildMessage');

        return $method->invoke(app(TelegramDriverCheckReporter::class), $check, data_get($check->telegram_raw, 'name_match'));
    }
}

final class FakeAiModel implements AiModel
{
    /** @var array<string, mixed>|null */
    public ?array $data = ['verdict' => 'mismatch', 'reason' => 'другой человек'];

    public ?Throwable $throw = null;

    public int $calls = 0;

    /** @var list<string> */
    public array $system = [];

    public function complete(array $system, string $user, array $schema): AiReply
    {
        $this->calls++;
        $this->system = $system;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new AiReply($this->data, 'end_turn', 300, 40, 0, 0);
    }
}
