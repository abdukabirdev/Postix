<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\SilentPeople;
use App\Models\Telegram\OperationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The people the penalties never get an answer from: listed, and switched
 * off for private messages only when asked.
 */
class SilentPeopleTest extends TestCase
{
    private const TOKEN = 'silent-people-report-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['operation_users', 'telegram_client_checks'] as $table) {
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
            $table->timestamp('last_private_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_client_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operation_user_id')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });

        config()->set('services.telegram.report_token', self::TOKEN);
        config()->set('client_checks.silent_people', ['days' => 14, 'min_penalties' => 30]);
    }

    private function person(string $name, array $attributes = []): OperationUser
    {
        return OperationUser::query()->create($attributes + [
            'name' => $name,
            'name_normalized' => mb_strtolower($name),
            'role' => 'operation',
        ]);
    }

    private function penalties(OperationUser $person, int $count, bool $replied = false, int $daysAgo = 3): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('telegram_client_checks')->insert([
                'operation_user_id' => $person->id,
                'forwarded_at' => now()->subDays($daysAgo)->subMinutes($i),
                'replied_at' => $replied && $i === 0 ? now()->subDays($daysAgo) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_only_people_with_many_penalties_and_no_answer_at_all_are_silent(): void
    {
        $silent = $this->person('SILENT ONE');
        $this->penalties($silent, 35);

        /* One reaction or word back in the journal: not silent. */
        $this->penalties($this->person('REACTS'), 35, replied: true);

        /* Too few penalties to say. */
        $this->penalties($this->person('FEW'), 10);

        /* Wrote to us in the chat itself lately. */
        $writes = $this->person('WRITES', ['last_private_message_at' => now()->subDay()]);
        $this->penalties($writes, 40);

        /* The penalties are old: not in the window. */
        $this->penalties($this->person('OLD'), 40, daysAgo: 30);

        $found = app(SilentPeople::class)->find();

        $this->assertSame(['SILENT ONE'], $found->map(fn (array $row): string => $row['person']->name)->all());
        $this->assertSame(35, $found->first()['penalties']);

        /* A writer who has gone quiet since the window is silent again. */
        $writes->update(['last_private_message_at' => now()->subDays(20)]);

        $this->assertSame(['SILENT ONE', 'WRITES'], app(SilentPeople::class)->find()->map(fn (array $row): string => $row['person']->name)->all());
    }

    public function test_the_report_lists_them_without_phones_and_only_for_the_token(): void
    {
        $silent = $this->person('SILENT ONE', ['telegram_username' => 'silent']);
        $this->penalties($silent, 31);

        $this->getJson('/api/telegram/report/silent-people')->assertNotFound();
        $this->getJson('/api/telegram/report/silent-people', ['Authorization' => 'Bearer wrong'])->assertNotFound();

        $this->getJson('/api/telegram/report/silent-people', ['Authorization' => 'Bearer ' . self::TOKEN])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('people.0.name', 'SILENT ONE')
            ->assertJsonPath('people.0.penalties', 31)
            ->assertJsonPath('people.0.dm_enabled', true)
            ->assertJsonMissingPath('people.0.phone');

        $this->getJson('/api/telegram/report/silent-people?min=40', ['Authorization' => 'Bearer ' . self::TOKEN])
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_the_command_lists_and_only_with_mute_switches_private_messages_off(): void
    {
        $silent = $this->person('SILENT ONE');
        $this->penalties($silent, 35);

        $this->artisan('telegram:silent-people')->assertSuccessful();
        $this->assertTrue($silent->refresh()->dm_enabled);

        $this->artisan('telegram:silent-people', ['--mute' => true])->expectsOutput('Switched off: 1')->assertSuccessful();
        $this->assertFalse($silent->refresh()->dm_enabled);

        $this->artisan('telegram:silent-people', ['--mute' => true])->expectsOutput('Switched off: 0')->assertSuccessful();
    }
}
