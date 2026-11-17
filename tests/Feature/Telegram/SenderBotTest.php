<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\SenderBotDelivery;
use App\Application\Telegram\Services\SenderBotDialog;
use App\Application\Telegram\Services\SenderBotService;
use App\Application\Telegram\Services\SenderBotSettings;
use App\Application\Telegram\Support\BotApi;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sender bot: Start, the language, the request to the admin, the
 * admin's word, the questions of a post, and the rounds into the groups.
 * Telegram is faked at the HTTP level.
 */
final class SenderBotTest extends TestCase
{
    private const TOKEN = '123456789:AAccccccccccccccccccccccccccccccccc';

    private const ADMIN = 8028326577;

    private const PERSON = 7001;

    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $sent = [];

    private BotApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        (include database_path('migrations/2026_10_05_100000_create_telegram_settings_table.php'))->up();
        (include database_path('migrations/2026_11_17_100000_create_telegram_sender_bot_tables.php'))->up();

        $this->app->make(SenderBotSettings::class)->saveToken(self::TOKEN, ['id' => 900, 'username' => 'our_sender_bot', 'name' => 'Sender']);
        $this->api = new BotApi(self::TOKEN);
        $this->fakeTelegram();
    }

    public function test_start_asks_the_language_and_the_language_is_the_request_to_the_admin(): void
    {
        $dialog = $this->app->make(SenderBotDialog::class);

        $dialog->message($this->api, $this->message('/start'));

        $this->assertSame(['lang:ru', 'lang:uz'], array_column($this->last('sendMessage')['reply_markup']['inline_keyboard'][0], 'callback_data'));
        $this->assertSame(TelegramSenderBotUser::STATUS_NEW, $this->person()->status);

        $this->sent = [];
        $dialog->callback($this->api, $this->press('lang:uz'));

        $person = $this->person();
        $this->assertSame(TelegramSenderBotUser::STATUS_PENDING, $person->status);
        $this->assertSame('uz', $person->language);
        $this->assertNotNull($person->requested_at);

        $messages = collect($this->sent)->where('method', 'sendMessage')->values();
        $this->assertSame(self::PERSON, $messages[0]['params']['chat_id']);
        $this->assertStringContainsString("so'rov administratorga yuborildi", $messages[0]['params']['text']);

        /* The admin gets it in the bot too, with the two buttons. */
        $this->assertSame(self::ADMIN, $messages[1]['params']['chat_id']);
        $this->assertSame(['adm:ok:' . $person->id, 'adm:no:' . $person->id], array_column($messages[1]['params']['reply_markup']['inline_keyboard'][0], 'callback_data'));

        /* While waiting, whatever they write gets the same answer. */
        $this->sent = [];
        $dialog->message($this->api, $this->message('salom'));
        $this->assertStringContainsString('hali administratorda', $this->last('sendMessage')['text']);
    }

    public function test_only_the_admin_may_press_approve_and_the_person_is_let_in(): void
    {
        $person = $this->pending();
        $dialog = $this->app->make(SenderBotDialog::class);

        $dialog->callback($this->api, $this->press('adm:ok:' . $person->id, from: 1234));
        $this->assertSame(TelegramSenderBotUser::STATUS_PENDING, $person->fresh()->status);
        $this->assertSame('Это может только администратор.', $this->last('answerCallbackQuery')['text']);

        $this->sent = [];
        $dialog->callback($this->api, $this->press('adm:ok:' . $person->id, from: self::ADMIN));

        $this->assertSame(TelegramSenderBotUser::STATUS_APPROVED, $person->fresh()->status);
        $this->assertStringContainsString('Администратор открыл вам доступ', collect($this->sent)->where('method', 'sendMessage')->first()['params']['text']);
        $this->assertStringContainsString('✅ Разрешено', $this->last('editMessageText')['text']);
    }

    public function test_blocking_stops_the_running_posts_and_tells_the_person(): void
    {
        $person = $this->approved();
        $post = $this->makePost($person);

        $error = $this->app->make(SenderBotDialog::class)->decide($person, 'block');

        $this->assertNull($error);
        $this->assertSame(TelegramSenderBotUser::STATUS_BLOCKED, $person->fresh()->status);
        $this->assertSame(TelegramSenderBotPost::STATUS_STOPPED, $post->fresh()->status);
        $this->assertSame(TelegramSenderBotPost::STOPPED_BY_ADMIN, $post->fresh()->stopped_by);
        $this->assertStringContainsString('закрыл вам доступ', $this->last('sendMessage')['text']);
    }

    public function test_a_post_is_asked_for_step_by_step_and_starts_on_the_button(): void
    {
        $this->group(-1001, true);
        $person = $this->approved();
        $dialog = $this->app->make(SenderBotDialog::class);

        $dialog->message($this->api, $this->message('✉️ Отправить сообщение'));
        $this->assertSame(SenderBotDialog::STEP_TEXT, $person->fresh()->step);

        $dialog->message($this->api, $this->message('Нужны водители на Ташкент', id: 55));
        $this->assertSame(SenderBotDialog::STEP_TIMES, $person->fresh()->step);

        $dialog->message($this->api, $this->message('999'));
        $this->assertStringContainsString('Нужно число от 1 до 20', $this->last('sendMessage')['text']);

        $dialog->message($this->api, $this->message('3'));
        $this->assertSame(SenderBotDialog::STEP_INTERVAL, $person->fresh()->step);

        $dialog->message($this->api, $this->message('15'));
        $this->assertSame(SenderBotDialog::STEP_CONFIRM, $person->fresh()->step);

        $confirm = $this->last('sendMessage');
        $this->assertStringContainsString('Групп: 1', $confirm['text']);
        $this->assertStringContainsString('Сколько раз: 3', $confirm['text']);
        $this->assertStringContainsString('15 мин', $confirm['text']);
        $this->assertSame(55, $confirm['reply_parameters']['message_id']);

        $dialog->callback($this->api, $this->press('post:go'));

        $post = TelegramSenderBotPost::query()->sole();
        $this->assertSame(55, $post->source_message_id);
        $this->assertSame(3, $post->times);
        $this->assertSame(15, $post->interval_minutes);
        $this->assertSame('Нужны водители на Ташкент', $post->text);
        $this->assertSame(TelegramSenderBotPost::STATUS_ACTIVE, $post->status);
        $this->assertNull($person->fresh()->step);
    }

    public function test_no_group_on_means_no_post(): void
    {
        $this->group(-1001, false);
        $person = $this->approved();

        $this->app->make(SenderBotDialog::class)->message($this->api, $this->message('✉️ Отправить сообщение'));

        $this->assertNull($person->fresh()->step);
        $this->assertStringContainsString('ещё не добавил группы', $this->last('sendMessage')['text']);
    }

    public function test_each_round_copies_into_every_group_that_is_on_then_the_post_is_done(): void
    {
        $this->group(-1001, true);
        $this->group(-1002, true);
        $this->group(-1003, false);
        $post = $this->makePost($this->approved(), times: 2, interval: 10);
        $delivery = $this->app->make(SenderBotDelivery::class);

        $this->assertSame(2, $delivery->dispatch($this->api, fn () => null));

        $copies = collect($this->sent)->where('method', 'copyMessage')->pluck('params.chat_id')->all();
        $this->assertSame([-1001, -1002], $copies);
        $this->assertSame(1, $post->fresh()->sent_times);
        $this->assertTrue($post->fresh()->next_at->between(now()->addMinutes(9), now()->addMinutes(11)));

        /* Not yet due: nothing goes. */
        $this->sent = [];
        $this->assertSame(0, $delivery->dispatch($this->api, fn () => null));

        $this->travel(11)->minutes();
        $delivery->dispatch($this->api, fn () => null);

        $this->assertSame(TelegramSenderBotPost::STATUS_DONE, $post->fresh()->status);
        $this->assertSame(2, $post->fresh()->sent_times);
        $this->assertSame(4, TelegramSenderBotSend::query()->whereNull('error')->count());
        $this->assertStringContainsString('завершена', $this->last('sendMessage')['text']);
    }

    public function test_a_first_round_that_got_nowhere_fails_the_post_and_tells_the_person(): void
    {
        $this->fakeTelegram(copy: fn () => ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to copy not found']);
        $this->group(-1001, true);
        $post = $this->makePost($this->approved(), times: 5, interval: 10);

        $this->app->make(SenderBotDelivery::class)->dispatch($this->api, fn () => null);

        $this->assertSame(TelegramSenderBotPost::STATUS_FAILED, $post->fresh()->status);
        $this->assertSame(0, $post->fresh()->sent_times);
        $this->assertStringContainsString('не дошла ни до одной группы', $this->last('sendMessage')['text']);
    }

    public function test_the_person_stops_their_own_post(): void
    {
        $person = $this->approved();
        $post = $this->makePost($person);

        $this->app->make(SenderBotDialog::class)->callback($this->api, $this->press('stop:' . $post->id));

        $this->assertSame(TelegramSenderBotPost::STATUS_STOPPED, $post->fresh()->status);
        $this->assertSame(TelegramSenderBotPost::STOPPED_BY_USER, $post->fresh()->stopped_by);
    }

    public function test_a_group_the_bot_is_added_to_shows_up_switched_off(): void
    {
        $this->fakeTelegram(updates: [[
            'update_id' => 10,
            'my_chat_member' => [
                'chat' => ['id' => -1009, 'type' => 'supergroup', 'title' => 'Yuk Toshkent'],
                'from' => ['id' => self::ADMIN],
                'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 900]],
            ],
        ]]);

        $this->app->make(SenderBotService::class)->poll(2, fn () => null);

        $group = TelegramSenderBotGroup::query()->sole();
        $this->assertSame(-1009, $group->chat_id);
        $this->assertSame('Yuk Toshkent', $group->title);
        $this->assertSame('administrator', $group->bot_status);
        $this->assertFalse($group->is_active);
        $this->assertSame(11, $this->app->make(SenderBotSettings::class)->state()['offset']);
    }

    public function test_a_group_ref_is_read_from_a_name_a_link_or_an_id(): void
    {
        $this->assertSame('@yuk_uz', SenderBotService::chatRef('@yuk_uz'));
        $this->assertSame('@yuk_uz', SenderBotService::chatRef('https://t.me/yuk_uz'));
        $this->assertSame(-1001234567890, SenderBotService::chatRef('-1001234567890'));
        $this->assertNull(SenderBotService::chatRef('https://t.me/+AbCdEf'));
    }

    /** What the fake answers: set by fakeTelegram(), read by the one fake of setUp(). */
    private array $updates = [];

    /** @var callable|null */
    private $copy = null;

    private function fakeTelegram(array $updates = [], ?callable $copy = null): void
    {
        $this->sent = [];
        $this->updates = $updates;
        $this->copy = $copy;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        $copies = 0;

        Http::fake(function (Request $request) use (&$copies) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $params = $request->data();
            $this->sent[] = ['method' => $method, 'params' => $params];

            return Http::response(match ($method) {
                'getUpdates' => ['ok' => true, 'result' => array_values(array_filter(
                    $this->updates,
                    fn (array $update) => $update['update_id'] >= (int) ($params['offset'] ?? 0),
                ))],
                'copyMessage' => $this->copy !== null ? ($this->copy)($params) : ['ok' => true, 'result' => ['message_id' => 100 + (++$copies)]],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                default => ['ok' => true, 'result' => true],
            });
        });
    }

    private bool $faked = false;

    /** The params of the last call of $method. */
    private function last(string $method): array
    {
        return collect($this->sent)->where('method', $method)->last()['params'] ?? [];
    }

    private function message(string $text, int $id = 1): array
    {
        return [
            'message_id' => $id,
            'from' => ['id' => self::PERSON, 'first_name' => 'Ali', 'username' => 'ali_uz'],
            'chat' => ['id' => self::PERSON, 'type' => 'private'],
            'text' => $text,
        ];
    }

    private function press(string $data, int $from = self::PERSON): array
    {
        return [
            'id' => 'q1',
            'from' => ['id' => $from, 'first_name' => 'X'],
            'data' => $data,
            'message' => ['message_id' => 9, 'chat' => ['id' => $from], 'text' => 'Запрос'],
        ];
    }

    private function person(): TelegramSenderBotUser
    {
        return TelegramSenderBotUser::query()->where('telegram_user_id', self::PERSON)->sole();
    }

    private function pending(): TelegramSenderBotUser
    {
        return TelegramSenderBotUser::query()->create([
            'telegram_user_id' => self::PERSON,
            'first_name' => 'Ali',
            'language' => 'ru',
            'status' => TelegramSenderBotUser::STATUS_PENDING,
            'requested_at' => now(),
        ]);
    }

    private function approved(): TelegramSenderBotUser
    {
        $person = $this->pending();
        $person->forceFill(['status' => TelegramSenderBotUser::STATUS_APPROVED])->save();

        return $person;
    }

    private function group(int $chatId, bool $on): TelegramSenderBotGroup
    {
        return TelegramSenderBotGroup::query()->create([
            'chat_id' => $chatId,
            'title' => 'Group ' . $chatId,
            'is_active' => $on,
            'bot_status' => 'administrator',
        ]);
    }

    private function makePost(TelegramSenderBotUser $person, int $times = 3, int $interval = 10): TelegramSenderBotPost
    {
        return TelegramSenderBotPost::query()->create([
            'sender_bot_user_id' => $person->id,
            'source_chat_id' => self::PERSON,
            'source_message_id' => 55,
            'text' => 'Нужны водители',
            'times' => $times,
            'interval_minutes' => $interval,
            'next_at' => now(),
            'status' => TelegramSenderBotPost::STATUS_ACTIVE,
        ]);
    }
}
