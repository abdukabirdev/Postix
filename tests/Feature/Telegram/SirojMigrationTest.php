<?php

namespace Tests\Feature\Telegram;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sirojiddin is "Сирож", never "Сера" (2026-11-13): his card only, that
 * word only.
 */
class SirojMigrationTest extends TestCase
{
    public function test_sera_becomes_siroj_on_his_card_only(): void
    {
        Schema::dropIfExists('operation_users');
        Schema::create('operation_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('address')->nullable();
            $table->json('personal_answers')->nullable();
        });

        $answers = ['ping' => ['only' => false, 'items' => [['type' => 'text', 'text' => 'Сера?'], ['type' => 'text', 'text' => 'Серажон, нарх?']]]];

        DB::table('operation_users')->insert([
            ['name' => 'SHAMSHIYEV SIROJIDDIN SADRIDDINOVICH', 'address' => json_encode(['uz' => 'Сера', 'ru' => 'Sera aka']), 'personal_answers' => json_encode($answers, JSON_UNESCAPED_UNICODE)],
            ['name' => 'SOMEBODY ELSE', 'address' => json_encode(['uz' => 'Сера']), 'personal_answers' => null],
        ]);

        (require database_path('migrations/2026_11_13_140000_call_sirojiddin_siroj.php'))->up();

        $siroj = DB::table('operation_users')->where('id', 1)->first();
        $this->assertSame(['uz' => 'Сирож', 'ru' => 'Siroj aka'], json_decode($siroj->address, true));
        $this->assertSame(['Сирож?', 'Серажон, нарх?'], array_column(json_decode($siroj->personal_answers, true)['ping']['items'], 'text'));

        $other = DB::table('operation_users')->where('id', 2)->first();
        $this->assertSame(['uz' => 'Сера'], json_decode($other->address, true));
    }
}
