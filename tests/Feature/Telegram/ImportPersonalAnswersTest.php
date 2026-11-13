<?php

namespace Tests\Feature\Telegram;

use App\Models\Telegram\OperationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Personal answers from a file: added to what each card has, never in its
 * place, and only to the card that has that name.
 */
class ImportPersonalAnswersTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['operation_users', 'telegram_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('operation_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('role', 16)->default('operation');
            $table->json('personal_answers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dm_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('telegram_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        config()->set('auto_replies.defaults', (require config_path('auto_replies.php'))['defaults']);
        config()->set('auto_replies.path', storage_path('framework/testing/no-auto-replies-' . uniqid() . '.json'));
        config()->set('client_checks', require config_path('client_checks.php'));

        $this->file = 'storage/framework/testing/personal-answers-' . uniqid() . '.json';
        @mkdir(dirname(base_path($this->file)), 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->file));

        parent::tearDown();
    }

    private function person(string $name, string $role = 'operation', ?array $answers = null): OperationUser
    {
        return OperationUser::query()->create([
            'name' => $name,
            'name_normalized' => mb_strtolower($name),
            'role' => $role,
            'personal_answers' => $answers,
        ]);
    }

    private function file(array $people): void
    {
        file_put_contents(base_path($this->file), json_encode(['people' => $people], JSON_UNESCAPED_UNICODE));
    }

    public function test_texts_are_added_to_the_card_without_taking_anything_away(): void
    {
        $zubayr = $this->person('HUSANOV ZUBAYR', answers: [
            'silence' => ['only' => true, 'items' => [['type' => 'text', 'text' => 'Мой текст'], ['type' => 'text', 'text' => 'Зуба']]],
        ]);
        $rushana = $this->person('SHADIYEVA RUSHANA', 'sales');

        $this->file([
            ['person_id' => $zubayr->id, 'name' => 'husanov  zubayr', 'silence' => ['Зуба', 'Нега жимсан?'], 'penalty' => ['Бер нарх'], 'thanks' => ['Маладес'], 'ping' => ['Зуба', 'Укажоним?']],
            ['person_id' => $rushana->id, 'name' => 'SHADIYEVA RUSHANA', 'penalty' => ['Обновили?'], 'thanks' => ['Вы самая лучшая!']],
            ['person_id' => $rushana->id, 'name' => 'SOMEBODY ELSE', 'silence' => ['Не туда']],
        ]);

        $this->artisan('telegram:personal-answers-import', ['file' => $this->file])
            ->expectsOutputToContain('Skipped, no such card')
            ->assertSuccessful();

        $slots = $zubayr->refresh()->personal_answers;

        /* What was there stays, with its switch; "Зуба" is not added twice. */
        $this->assertTrue($slots['silence']['only']);
        $this->assertSame(['Мой текст', 'Зуба', 'Нега жимсан?'], array_column($slots['silence']['items'], 'text'));

        /* An operator: every level but the last (the threat). */
        $levels = count(config('client_checks.roles.operation.levels'));
        for ($level = 0; $level < $levels - 1; $level++) {
            $this->assertSame(['Бер нарх'], array_column($slots["penalty:{$level}"]['items'], 'text'));
            $this->assertFalse($slots["penalty:{$level}"]['only']);
        }
        $this->assertArrayNotHasKey('penalty:' . ($levels - 1), $slots);

        /* The call by name has a slot of its own. */
        $this->assertSame(['Зуба', 'Укажоним?'], array_column($slots['ping']['items'], 'text'));

        /* Thanks go to the "Благодарность" kind. */
        $thanks = collect(array_keys($slots))->first(fn (string $slot): bool => str_starts_with($slot, 'reply:'));
        $this->assertSame(['Маладес'], array_column($slots[$thanks]['items'], 'text'));

        /* A sales manager has one level, and it gets the text. */
        $sales = $rushana->refresh()->personal_answers;
        $this->assertSame(['Обновили?'], array_column($sales['penalty:0']['items'], 'text'));
        $this->assertArrayNotHasKey('silence', $sales);

        /* Run again: nothing twice. */
        $this->artisan('telegram:personal-answers-import', ['file' => $this->file])
            ->expectsOutputToContain('Added: 0 texts')
            ->assertSuccessful();
    }

    public function test_dry_writes_nothing(): void
    {
        $person = $this->person('PULATOV AFZAL');

        $this->file([['person_id' => $person->id, 'name' => 'PULATOV AFZAL', 'silence' => ['Пулатов']]]);

        $this->artisan('telegram:personal-answers-import', ['file' => $this->file, '--dry' => true])
            ->expectsOutputToContain('Would add: 1 texts for 1 people')
            ->assertSuccessful();

        $this->assertNull($person->refresh()->personal_answers);
    }

    public function test_the_shipped_file_is_valid_and_has_no_swearing(): void
    {
        $data = json_decode(file_get_contents(base_path('database/data/personal_answers_2026_11_13.json')), true, 32, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($data['people']);

        foreach ($data['people'] as $row) {
            $this->assertIsInt($row['person_id']);
            $this->assertNotSame('', trim($row['name']));

            foreach (['thanks', 'silence', 'penalty', 'opening'] as $slot) {
                foreach ($row[$slot] ?? [] as $text) {
                    $this->assertDoesNotMatchRegularExpression('/бля|жаллаб|пошел|жоп|\.\.\.|сука|далбаёб/iu', $text, $row['name']);
                }
            }
        }
    }
}
