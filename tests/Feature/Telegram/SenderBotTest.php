<?php

namespace Tests\Feature\Telegram;

use App\Application\Telegram\Services\SenderBotDelivery;
use App\Application\Telegram\Services\SenderBotDialog;
use App\Application\Telegram\Services\SenderBotService;
use App\Application\Telegram\Services\SenderBotSettings;
use App\Application\Telegram\Support\BotApi;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotLead;
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
        (include database_path('migrations/2026_11_17_200000_extend_telegram_sender_bot_tables.php'))->up();

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
        $this->assertStringContainsString('Доступ открыт', collect($this->sent)->where('method', 'sendMessage')->first()['params']['text']);
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
        $this->assertStringContainsString('Все группы (1)', $confirm['text']);
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
        $this->postJson('/api/telegram/sender-bot/webhook', [
            'update_id' => 10,
            'my_chat_member' => [
                'chat' => ['id' => -1009, 'type' => 'supergroup', 'title' => 'Yuk Toshkent'],
                'from' => ['id' => self::ADMIN],
                'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 900]],
            ],
        ], ['X-Postix-Secret' => 'c2283uJ2AdZxSQO-oD9XjDNesZ8CLkLnNUhg-K9xuws'])->assertOk();

        $group = TelegramSenderBotGroup::query()->sole();
        $this->assertSame(-1009, $group->chat_id);
        $this->assertSame('Yuk Toshkent', $group->title);
        $this->assertSame('administrator', $group->bot_status);
        $this->assertFalse($group->is_active);
    }

    public function test_the_runner_only_posts_and_never_touches_the_webhook_or_the_updates(): void
    {
        $this->group(-1001, true);
        $this->makePost($this->approved(), times: 1);

        $this->app->make(SenderBotService::class)->run(2, fn () => null);

        $methods = collect($this->sent)->pluck('method')->unique()->values()->all();
        $this->assertContains('copyMessage', $methods);
        $this->assertEmpty(array_intersect($methods, ['getUpdates', 'setWebhook', 'deleteWebhook']));
    }

    public function test_a_bot_with_a_webhook_is_connected_and_its_webhook_left_alone(): void
    {
        $this->app->make(SenderBotSettings::class)->forget();

        $bot = $this->app->make(SenderBotService::class)->connect(self::TOKEN);

        $this->assertSame(self::TOKEN, $this->app->make(SenderBotSettings::class)->token());
        $this->assertSame(['getMe', 'setMyCommands'], collect($this->sent)->pluck('method')->all());
        $this->assertSame(555, $bot['id']);
    }

    public function test_a_forwarded_update_needs_the_secret_and_is_answered_once(): void
    {
        $update = ['update_id' => 50, 'message' => $this->message('/start')];

        $this->postJson('/api/telegram/sender-bot/webhook', $update)->assertForbidden();
        $this->assertSame(0, TelegramSenderBotUser::query()->count());

        $headers = ['X-Postix-Secret' => 'c2283uJ2AdZxSQO-oD9XjDNesZ8CLkLnNUhg-K9xuws'];

        $this->postJson('/api/telegram/sender-bot/webhook', $update, $headers)->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(TelegramSenderBotUser::STATUS_NEW, $this->person()->status);
        $this->assertSame(['lang:ru', 'lang:uz'], array_column($this->last('sendMessage')['reply_markup']['inline_keyboard'][0], 'callback_data'));

        /* The same update again (Telegram or izicrm retried): not answered twice. */
        $this->sent = [];
        $this->postJson('/api/telegram/sender-bot/webhook', $update, $headers)->assertOk()->assertJson(['skipped' => 'duplicate']);
        $this->assertSame([], $this->sent);
    }

    public function test_the_report_shows_the_bot_the_runner_and_the_overdue_posts_to_the_token_only(): void
    {
        config(['services.telegram.report_token' => str_repeat('r', 40)]);
        $this->group(-1001, true);
        $post = $this->makePost($this->approved());
        $post->forceFill(['next_at' => now()->subMinutes(5)])->save();

        $this->getJson('/api/telegram/report/sender-bot')->assertNotFound();

        $report = $this->getJson('/api/telegram/report/sender-bot', ['Authorization' => 'Bearer ' . str_repeat('r', 40)])
            ->assertOk()
            ->json();

        $this->assertTrue($report['bot']['connected']);
        $this->assertSame('our_sender_bot', $report['bot']['username']);
        $this->assertNull($report['runner']['last_run_at']);
        $this->assertSame($post->id, $report['posts'][0]['id']);
        $this->assertGreaterThanOrEqual(299, $report['posts'][0]['overdue_seconds']);
        $this->assertTrue($report['groups'][0]['usable']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($report));
    }

    public function test_a_group_ref_is_read_from_a_name_a_link_or_an_id(): void
    {
        $this->assertSame('@yuk_uz', SenderBotService::chatRef('@yuk_uz'));
        $this->assertSame('@yuk_uz', SenderBotService::chatRef('https://t.me/yuk_uz'));
        $this->assertSame(-1001234567890, SenderBotService::chatRef('-1001234567890'));
        $this->assertNull(SenderBotService::chatRef('https://t.me/+AbCdEf'));
    }

    public function test_a_driver_who_forwards_a_post_gets_the_operators_chat_and_the_operator_hears_of_it(): void
    {
        $group = $this->group(-1001, true);
        $operator = $this->approved();
        $operator->forceFill(['username' => 'ali_uz'])->save();
        $post = $this->makePost($operator, times: 1);
        $this->app->make(SenderBotDelivery::class)->dispatch($this->api, fn () => null);
        $this->sent = [];

        $this->webhook(['message' => $this->forward('Нужны   водители', date: now()->timestamp)]);

        $toDriver = collect($this->sent)->where('method', 'sendMessage')->firstWhere('params.chat_id', self::DRIVER)['params'];
        $this->assertStringContainsString('@ali_uz', $toDriver['text']);
        $this->assertSame('https://t.me/ali_uz', $toDriver['reply_markup']['inline_keyboard'][0][0]['url']);

        $toOperator = collect($this->sent)->where('method', 'sendMessage')->firstWhere('params.chat_id', self::PERSON)['params'];
        $this->assertStringContainsString('#' . $post->id, $toOperator['text']);
        $this->assertStringContainsString('Vali', $toOperator['text']);

        $lead = TelegramSenderBotLead::query()->sole();
        $this->assertSame(TelegramSenderBotLead::STATUS_LINK, $lead->status);
        $this->assertSame($post->id, $lead->sender_bot_post_id);
        $this->assertSame(1, TelegramSenderBotEvent::query()->where('type', 'lead_link')->count());

        /* The driver is not made a person of the bot (no request, no language question). */
        $this->assertSame(0, TelegramSenderBotUser::query()->where('telegram_user_id', self::DRIVER)->count());
        $this->assertNotNull($group);
    }

    public function test_when_the_operators_chat_cannot_be_given_the_driver_is_asked_for_a_contact_and_it_goes_to_the_operator(): void
    {
        $this->group(-1001, true);
        $operator = $this->approved();
        $post = $this->makePost($operator, times: 1);
        $this->answers['sendMessage'] = fn (array $p) => str_starts_with((string) ($p['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), 'tg://')
            ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUTTON_USER_PRIVACY_RESTRICTED']
            : null;

        $this->webhook(['message' => [...$this->forward('нужны водители'), 'text' => '/start p' . $post->id, 'forward_origin' => null]]);

        $ask = $this->last('sendMessage');
        $this->assertSame(self::DRIVER, $ask['chat_id']);
        $this->assertTrue($ask['reply_markup']['keyboard'][0][0]['request_contact']);
        $this->assertSame(TelegramSenderBotLead::STATUS_ASKED, TelegramSenderBotLead::query()->sole()->status);
        $this->assertSame(TelegramSenderBotLead::SOURCE_BUTTON, TelegramSenderBotLead::query()->sole()->source);

        $this->sent = [];
        $this->webhook(['message' => [
            'message_id' => 70,
            'from' => ['id' => self::DRIVER, 'first_name' => 'Vali', 'language_code' => 'uz'],
            'chat' => ['id' => self::DRIVER, 'type' => 'private'],
            'contact' => ['phone_number' => '+998901234567', 'first_name' => 'Vali', 'user_id' => self::DRIVER],
        ]]);

        $lead = TelegramSenderBotLead::query()->sole();
        $this->assertSame(TelegramSenderBotLead::STATUS_SENT, $lead->status);
        $this->assertSame('+998901234567', $lead->driver_phone);
        $this->assertSame(self::PERSON, $this->last('sendContact')['chat_id']);
        $this->assertStringContainsString('+998901234567', collect($this->sent)->where('method', 'sendMessage')->firstWhere('params.chat_id', self::PERSON)['params']['text']);
        $this->assertTrue($this->last('sendMessage')['reply_markup']['remove_keyboard']);
    }

    public function test_a_forward_of_nothing_of_ours_is_told_so(): void
    {
        $this->webhook(['message' => $this->forward('чужое объявление')]);

        $this->assertStringContainsString('topmadim', $this->last('sendMessage')['text']);
        $this->assertSame(TelegramSenderBotLead::STATUS_NOT_FOUND, TelegramSenderBotLead::query()->sole()->status);
    }

    public function test_the_person_chooses_the_groups_and_only_those_get_the_post(): void
    {
        $a = $this->group(-1001, true);
        $this->group(-1002, true);
        $person = $this->approved();
        $dialog = $this->app->make(SenderBotDialog::class);

        $dialog->message($this->api, $this->message('Нужны водители на Ташкент', id: 55));
        $this->assertSame(SenderBotDialog::STEP_GROUPS, $person->fresh()->step);
        $this->assertSame(['g:' . $a->id, 'g:' . ($a->id + 1)], array_column(array_merge(...array_slice($this->last('sendMessage')['reply_markup']['inline_keyboard'], 0, 2)), 'callback_data'));

        $dialog->callback($this->api, $this->press('g:' . ($a->id + 1)));
        $dialog->callback($this->api, $this->press('g:next'));
        $this->assertSame([$a->id], $person->fresh()->draft['groups']);
        $this->assertSame(SenderBotDialog::STEP_TIMES, $person->fresh()->step);

        $dialog->message($this->api, $this->message('1'));
        $dialog->callback($this->api, $this->press('post:go'));

        $post = TelegramSenderBotPost::query()->sole();
        $this->assertSame([$a->id], $post->group_ids);
        $this->assertSame('text', $post->payload['kind']);

        $this->sent = [];
        $this->app->make(SenderBotDelivery::class)->dispatch($this->api, fn () => null);
        $this->assertSame([-1001], collect($this->sent)->where('method', 'copyMessage')->pluck('params.chat_id')->all());
    }

    public function test_a_message_that_cannot_be_copied_is_sent_anew_from_what_was_kept(): void
    {
        $this->fakeTelegram(copy: fn () => ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: the message can't be copied"]);
        $this->group(-1001, true);
        $post = $this->makePost($this->approved(), times: 1);
        $post->forceFill(['payload' => ['kind' => 'text', 'text' => 'Нужны водители', 'entities' => [['type' => 'bold', 'offset' => 0, 'length' => 5]]]])->save();

        $this->app->make(SenderBotDelivery::class)->dispatch($this->api, fn () => null);

        $sent = collect($this->sent)->where('method', 'sendMessage')->firstWhere('params.chat_id', -1001)['params'];
        $this->assertSame('Нужны водители', $sent['text']);
        $this->assertSame('bold', $sent['entities'][0]['type']);
        $this->assertSame(TelegramSenderBotPost::STATUS_DONE, $post->fresh()->status);
        $this->assertSame(1, TelegramSenderBotSend::query()->whereNull('error')->count());
    }

    public function test_a_paused_post_waits_and_send_now_sends_it(): void
    {
        $this->group(-1001, true);
        $person = $this->approved();
        $post = $this->makePost($person, times: 3, interval: 60);
        $post->forceFill(['next_at' => now()->addHour()])->save();
        $dialog = $this->app->make(SenderBotDialog::class);
        $delivery = $this->app->make(SenderBotDelivery::class);

        $dialog->callback($this->api, $this->press('p:pause:' . $post->id));
        $this->assertSame(TelegramSenderBotPost::STATUS_PAUSED, $post->fresh()->status);
        $this->assertStringContainsString('на паузе', $this->last('editMessageText')['text']);

        $this->travel(2)->hours();
        $this->sent = [];
        $delivery->dispatch($this->api, fn () => null);
        $this->assertSame([], collect($this->sent)->where('method', 'copyMessage')->all());

        $dialog->callback($this->api, $this->press('p:now:' . $post->id));
        $this->assertSame(TelegramSenderBotPost::STATUS_ACTIVE, $post->fresh()->status);
        $delivery->dispatch($this->api, fn () => null);
        $this->assertSame(1, $post->fresh()->sent_times);
        $this->assertSame(['post_paused', 'post_now', 'round'], TelegramSenderBotEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_the_copies_are_deleted_from_the_groups_on_request(): void
    {
        $this->group(-1001, true);
        $this->group(-1002, true);
        $person = $this->approved();
        $post = $this->makePost($person, times: 1);
        $delivery = $this->app->make(SenderBotDelivery::class);
        $delivery->dispatch($this->api, fn () => null);

        $dialog = $this->app->make(SenderBotDialog::class);
        $dialog->callback($this->api, $this->press('p:del:' . $post->id));
        $this->assertSame('p:delok:' . $post->id, $this->last('editMessageReplyMarkup')['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $dialog->callback($this->api, $this->press('p:delok:' . $post->id));
        $this->assertNotNull($post->fresh()->delete_asked_at);

        $this->sent = [];
        $delivery->dispatch($this->api, fn () => null);

        $this->assertSame([-1001, -1002], collect($this->sent)->where('method', 'deleteMessage')->pluck('params.chat_id')->all());
        $this->assertSame(2, TelegramSenderBotSend::query()->whereNotNull('deleted_at')->count());
        $this->assertNotNull($post->fresh()->copies_deleted_at);
        $this->assertStringContainsString('удалено из групп 2', $this->last('sendMessage')['text']);
    }

    public function test_a_person_over_the_limit_may_not_start_one_more(): void
    {
        $this->group(-1001, true);
        $this->app->make(SenderBotSettings::class)->saveLimits(20, 1, 1440, 1, 10);
        $person = $this->approved();
        $this->makePost($person);

        $this->app->make(SenderBotDialog::class)->message($this->api, $this->message('✉️ Отправить сообщение'));

        $this->assertNull($person->fresh()->step);
        $this->assertStringContainsString('это предел', $this->last('sendMessage')['text']);
    }

    public function test_in_the_quiet_hours_the_rounds_wait_till_their_end(): void
    {
        $this->travelTo(now()->timezone(config('app.timezone'))->setTime(23, 30));
        $this->app->make(SenderBotSettings::class)->saveLimits(20, 1, 1440, 3, 10, '23:00', '07:00');
        $this->group(-1001, true);
        $post = $this->makePost($this->approved());

        $this->app->make(SenderBotDelivery::class)->dispatch($this->api, fn () => null);

        $this->assertSame([], collect($this->sent)->where('method', 'copyMessage')->all());
        $this->assertSame('07:00', $post->fresh()->next_at->timezone(config('app.timezone'))->format('H:i'));
        $this->assertTrue($post->fresh()->next_at->isTomorrow() || $post->fresh()->next_at->timezone(config('app.timezone'))->isTomorrow());
    }

    public function test_the_groups_list_carries_the_links_to_open_them(): void
    {
        $this->group(-1001, true)->forceFill(['username' => 'yuk_uz'])->save();
        $this->group(-1002, true)->forceFill(['invite_link' => 'https://t.me/+AbCd'])->save();
        $this->approved();

        $this->app->make(SenderBotDialog::class)->message($this->api, $this->message('👥 Группы'));

        $list = $this->last('sendMessage');
        $this->assertSame('HTML', $list['parse_mode']);
        $this->assertStringContainsString('<a href="https://t.me/yuk_uz">', $list['text']);
        $this->assertStringContainsString('<a href="https://t.me/+AbCd">', $list['text']);
        $this->assertSame(['https://t.me/yuk_uz', 'https://t.me/+AbCd'], array_column(array_merge(...$list['reply_markup']['inline_keyboard']), 'url'));
    }

    public function test_the_admin_has_a_menu_in_the_bot_and_stops_a_post_of_somebody_else(): void
    {
        $this->group(-1001, true);
        $post = $this->makePost($this->approved());
        TelegramSenderBotUser::query()->create(['telegram_user_id' => self::ADMIN, 'language' => 'ru', 'status' => TelegramSenderBotUser::STATUS_APPROVED]);

        $this->webhook(['message' => [...$this->message('🛠 Админ'), 'from' => ['id' => self::ADMIN, 'first_name' => 'A'], 'chat' => ['id' => self::ADMIN, 'type' => 'private']]]);
        $this->assertStringContainsString('Идут рассылки: 1', $this->last('sendMessage')['text']);

        $this->webhook(['callback_query' => $this->press('a:stop:' . $post->id, from: self::ADMIN)]);

        $this->assertSame(TelegramSenderBotPost::STATUS_STOPPED, $post->fresh()->status);
        $this->assertSame(TelegramSenderBotPost::STOPPED_BY_ADMIN, $post->fresh()->stopped_by);
        $this->assertTrue(collect($this->sent)->where('method', 'sendMessage')->contains(fn ($c) => $c['params']['chat_id'] === self::PERSON && str_contains($c['params']['text'], 'Администратор остановил')));

        /* Somebody else may not press the admin's buttons. */
        $this->webhook(['callback_query' => [...$this->press('a:now:' . $post->id), 'id' => 'q2']]);
        $this->assertSame('Это может только администратор.', $this->last('answerCallbackQuery')['text']);
    }

    public function test_a_new_post_of_somebody_else_is_brought_to_the_admin(): void
    {
        $this->group(-1001, true);
        $person = $this->approved();
        $person->forceFill(['step' => SenderBotDialog::STEP_CONFIRM, 'draft' => ['chat_id' => self::PERSON, 'message_id' => 55, 'kind' => 'text', 'text' => 'Нужны', 'times' => 1, 'interval' => 0]])->save();

        $this->app->make(SenderBotDialog::class)->callback($this->api, $this->press('post:go'));

        $this->assertSame(self::ADMIN, $this->last('copyMessage')['chat_id']);
        $card = collect($this->sent)->where('method', 'sendMessage')->last(fn ($c) => $c['params']['chat_id'] === self::ADMIN)['params'];
        $this->assertStringContainsString('Новая рассылка', $card['text']);
        $this->assertContains('a:block:' . TelegramSenderBotPost::query()->sole()->id, array_column(array_merge(...$card['reply_markup']['inline_keyboard']), 'callback_data'));
    }

    private const DRIVER = 9100;

    /** @var array<string, callable> answers of the fake by method; null from one falls through */
    private array $answers = [];

    private function webhook(array $update): void
    {
        static $id = 1000;

        $this->postJson('/api/telegram/sender-bot/webhook', ['update_id' => ++$id, ...$update], ['X-Postix-Secret' => 'c2283uJ2AdZxSQO-oD9XjDNesZ8CLkLnNUhg-K9xuws'])
            ->assertOk()
            ->assertJsonMissingPath('error');
    }

    /** A driver forwards the bot's message from a group. */
    private function forward(string $text, ?int $date = null): array
    {
        return [
            'message_id' => 60,
            'from' => ['id' => self::DRIVER, 'first_name' => 'Vali', 'language_code' => 'uz'],
            'chat' => ['id' => self::DRIVER, 'type' => 'private'],
            'forward_origin' => ['type' => 'user', 'sender_user' => ['id' => 555, 'is_bot' => true], 'date' => $date ?? now()->subDay()->timestamp],
            'text' => $text,
        ];
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

            if (isset($this->answers[$method]) && ($answer = ($this->answers[$method])($params)) !== null) {
                return Http::response($answer);
            }

            return Http::response(match ($method) {
                'getUpdates' => ['ok' => true, 'result' => array_values(array_filter(
                    $this->updates,
                    fn (array $update) => $update['update_id'] >= (int) ($params['offset'] ?? 0),
                ))],
                'copyMessage' => $this->copy !== null ? ($this->copy)($params) : ['ok' => true, 'result' => ['message_id' => 100 + (++$copies)]],
                'sendMessage' => ['ok' => true, 'result' => ['message_id' => 1]],
                'getMe' => ['ok' => true, 'result' => ['id' => 555, 'is_bot' => true, 'first_name' => 'EGS', 'username' => 'egs_yuk_bot']],
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
