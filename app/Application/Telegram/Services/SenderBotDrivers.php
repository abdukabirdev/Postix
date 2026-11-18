<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotLead;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotSend;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drivers: somebody in a group read a post of the bot and wants its author
 * (the operator). They forward the bot's message to the bot (or press the
 * button under it); the bot finds whose post it is and gives the operator's
 * own chat. When it cannot (no @name, and Telegram will not link the
 * person), it asks the driver for a contact and passes it to the operator.
 *
 * Drivers are not people of the bot: no request, no language question;
 * they are spoken to in the language of their Telegram.
 */
final class SenderBotDrivers
{
    /** A forwarded copy is looked for among the copies sent this close to its date, seconds. */
    private const DATE_SLACK = 180;

    /** How long a driver's contact is waited for, hours. */
    private const ASK_HOURS = 24;

    public function __construct(private readonly SenderBotSettings $settings) {}

    /**
     * This private message is a driver's: a forward (driver contacts on),
     * the post's button (/start p<id>), or the contact / Cancel the bot
     * asked a driver for.
     */
    public function wants(array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));

        if (preg_match('/^\/start\s+p(\d+)$/', $text) === 1) {
            return true;
        }

        $from = (int) ($message['from']['id'] ?? 0);

        if (isset($message['contact']) || $this->isCancel($text)) {
            return $this->waiting($from) !== null;
        }

        return self::isForward($message) && $this->settings->options()['driver_contacts'];
    }

    public function handle(BotApi $api, array $message): void
    {
        $from = (array) ($message['from'] ?? []);
        $driverId = (int) ($from['id'] ?? 0);
        $locale = self::locale($from);
        $text = trim((string) ($message['text'] ?? ''));

        if (isset($message['contact'])) {
            $this->contact($api, $message, $locale);

            return;
        }

        if ($this->isCancel($text)) {
            $this->waiting($driverId)?->forceFill(['error' => 'cancelled'])->save();
            $this->send($api, $driverId, __('sender_bot.driver_cancelled', [], $locale), ['remove_keyboard' => true]);

            return;
        }

        $source = TelegramSenderBotLead::SOURCE_FORWARD;

        if (preg_match('/^\/start\s+p(\d+)$/', $text, $m) === 1) {
            $source = TelegramSenderBotLead::SOURCE_BUTTON;
            $post = TelegramSenderBotPost::query()->with('user')->find((int) $m[1]);
        } else {
            $post = $this->findForwarded($message);
        }

        $lead = new TelegramSenderBotLead([
            'driver_telegram_id' => $driverId,
            'driver_username' => isset($from['username']) ? (string) $from['username'] : null,
            'driver_name' => mb_substr(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), 0, 255) ?: null,
            'source' => $source,
            'sender_bot_post_id' => $post?->id,
            'sender_bot_user_id' => $post?->sender_bot_user_id,
        ]);

        $operator = $post?->user;

        if ($post === null || $operator === null || $operator->status === TelegramSenderBotUser::STATUS_BLOCKED) {
            $lead->forceFill(['status' => TelegramSenderBotLead::STATUS_NOT_FOUND])->save();
            SenderBotHistory::add('lead_not_found', TelegramSenderBotEvent::ACTOR_USER, $operator, $post, data: $this->who($lead));
            $this->send($api, $driverId, __('sender_bot.driver_not_found', [], $locale));

            return;
        }

        if ($operator->telegram_user_id === $driverId) {
            $this->send($api, $driverId, __('sender_bot.driver_own_post', ['id' => $post->id], $locale));

            return;
        }

        $this->give($api, $lead, $post, $operator, $locale);
    }

    /**
     * The operator's chat for the driver: by the @name; without one, a link
     * by the id (Telegram refuses it when the operator's privacy does not
     * let them be linked) - else the driver is asked for a contact.
     */
    private function give(BotApi $api, TelegramSenderBotLead $lead, TelegramSenderBotPost $post, TelegramSenderBotUser $operator, string $locale): void
    {
        $name = SenderBotCards::e($operator->name());
        $url = $operator->username ? 'https://t.me/' . $operator->username : 'tg://user?id=' . $operator->telegram_user_id;

        try {
            $api->call('sendMessage', [
                'chat_id' => $lead->driver_telegram_id,
                'text' => __('sender_bot.driver_link', [
                    'name' => $name,
                    'username' => $operator->username ? ' — @' . SenderBotCards::e($operator->username) : '',
                    'preview' => SenderBotCards::e(SenderBotDialog::preview($post, $locale, 80)),
                ], $locale),
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => [[['text' => __('sender_bot.btn_write_author', [], $locale), 'url' => $url]]]],
            ]);
        } catch (BotApiError $e) {
            if ($operator->username) {
                throw $e;
            }

            $lead->error = mb_substr($e->reason(), 0, 255);
            $this->askContact($api, $lead, $post, $locale);

            return;
        }

        $lead->forceFill(['status' => TelegramSenderBotLead::STATUS_LINK])->save();
        SenderBotHistory::add('lead_link', TelegramSenderBotEvent::ACTOR_USER, $operator, $post, data: $this->who($lead));

        $this->tellOperator($api, $operator, __('sender_bot.operator_lead_link', [
            'id' => $post->id,
            'preview' => SenderBotCards::e(SenderBotDialog::preview($post, $operator->locale(), 60)),
            'driver' => $this->driverLine($lead),
        ], $operator->locale()));
    }

    private function askContact(BotApi $api, TelegramSenderBotLead $lead, TelegramSenderBotPost $post, string $locale): void
    {
        $lead->forceFill(['status' => TelegramSenderBotLead::STATUS_ASKED])->save();
        SenderBotHistory::add('lead_asked', TelegramSenderBotEvent::ACTOR_USER, $post->sender_bot_user_id, $post, data: $this->who($lead));

        $this->send($api, $lead->driver_telegram_id, __('sender_bot.driver_ask_contact', [], $locale), [
            'keyboard' => [
                [['text' => __('sender_bot.btn_share_contact', [], $locale), 'request_contact' => true]],
                [['text' => __('sender_bot.btn_cancel', [], $locale)]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    /** The contact the bot asked a driver for: to the operator, with who it is. */
    private function contact(BotApi $api, array $message, string $locale): void
    {
        $from = (array) ($message['from'] ?? []);
        $contact = (array) $message['contact'];
        $lead = $this->waiting((int) ($from['id'] ?? 0));

        if ($lead === null) {
            return;
        }

        $phone = mb_substr((string) ($contact['phone_number'] ?? ''), 0, 32);
        $lead->forceFill([
            'driver_phone' => $phone !== '' ? $phone : null,
            'status' => TelegramSenderBotLead::STATUS_SENT,
        ])->save();

        $post = $lead->post;
        $operator = $lead->user;

        SenderBotHistory::add('lead_contact', TelegramSenderBotEvent::ACTOR_USER, $operator, $post, data: $this->who($lead));

        if ($operator !== null) {
            $olocale = $operator->locale();

            $this->tellOperator($api, $operator, __('sender_bot.operator_lead_contact', [
                'id' => $post?->id ?? '—',
                'preview' => $post !== null ? SenderBotCards::e(SenderBotDialog::preview($post, $olocale, 60)) : '',
                'driver' => $this->driverLine($lead),
                'phone' => SenderBotCards::e($phone),
            ], $olocale));

            if ($phone !== '') {
                $this->quietly(fn () => $api->call('sendContact', array_filter([
                    'chat_id' => $operator->telegram_user_id,
                    'phone_number' => $phone,
                    'first_name' => (string) ($contact['first_name'] ?? $from['first_name'] ?? 'Driver'),
                    'last_name' => $contact['last_name'] ?? null,
                ], static fn ($v) => $v !== null && $v !== '')));
            }
        }

        $this->send($api, $lead->driver_telegram_id, __('sender_bot.driver_contact_sent', [], $locale), ['remove_keyboard' => true]);
    }

    /**
     * Which post of ours a forwarded message is: by the group message it
     * came from (when Telegram says), by the copies sent at its date, or by
     * its text.
     */
    public function findForwarded(array $message): ?TelegramSenderBotPost
    {
        $origin = (array) ($message['forward_origin'] ?? []);
        $text = self::normal((string) ($message['text'] ?? $message['caption'] ?? ''));

        /* From a channel (or a group by an anonymous admin): the very message. */
        $chatId = $origin['chat']['id'] ?? $message['forward_from_chat']['id'] ?? null;
        $messageId = $origin['message_id'] ?? $message['forward_from_message_id'] ?? null;

        if ($chatId !== null && $messageId !== null) {
            $send = TelegramSenderBotSend::query()
                ->where('message_id', (int) $messageId)
                ->whereHas('group', fn ($q) => $q->where('chat_id', (int) $chatId))
                ->latest('id')
                ->first();

            if ($send?->post !== null) {
                return $send->post->load('user');
            }
        }

        $date = (int) ($origin['date'] ?? $message['forward_date'] ?? 0);

        if ($date > 0) {
            $at = Carbon::createFromTimestamp($date);
            $posts = TelegramSenderBotPost::query()
                ->with('user')
                ->whereIn('id', TelegramSenderBotSend::query()
                    ->select('sender_bot_post_id')
                    ->whereNotNull('message_id')
                    ->whereBetween('created_at', [$at->copy()->subSeconds(self::DATE_SLACK), $at->copy()->addSeconds(self::DATE_SLACK)]))
                ->orderByDesc('id')
                ->get();

            $byText = $text !== '' ? $posts->first(fn (TelegramSenderBotPost $p) => self::normal((string) $p->text) === $text) : null;

            if ($byText !== null) {
                return $byText;
            }

            if ($text === '' && $posts->count() === 1) {
                return $posts->first();
            }
        }

        if ($text === '') {
            return null;
        }

        return TelegramSenderBotPost::query()
            ->with('user')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->latest('id')
            ->limit(500)
            ->get()
            ->first(fn (TelegramSenderBotPost $p) => self::normal((string) $p->text) === $text);
    }

    public static function isForward(array $message): bool
    {
        return isset($message['forward_origin'])
            || isset($message['forward_from'])
            || isset($message['forward_from_chat'])
            || isset($message['forward_sender_name'])
            || isset($message['forward_date']);
    }

    /** The driver's request still waiting for a contact. */
    private function waiting(int $driverId): ?TelegramSenderBotLead
    {
        if ($driverId === 0) {
            return null;
        }

        return TelegramSenderBotLead::query()
            ->with(['post', 'user'])
            ->where('driver_telegram_id', $driverId)
            ->where('status', TelegramSenderBotLead::STATUS_ASKED)
            ->where(fn ($q) => $q->whereNull('error')->orWhere('error', '!=', 'cancelled'))
            ->where('updated_at', '>=', now()->subHours(self::ASK_HOURS))
            ->latest('id')
            ->first();
    }

    private function isCancel(string $text): bool
    {
        return $text !== '' && in_array($text, [__('sender_bot.btn_cancel', [], 'ru'), __('sender_bot.btn_cancel', [], 'uz')], true);
    }

    /** "Ali (@ali_uz)" as a link to the driver where one can be made. */
    private function driverLine(TelegramSenderBotLead $lead): string
    {
        $name = SenderBotCards::e($lead->driverLabel());
        $link = $lead->driver_username ? 'https://t.me/' . $lead->driver_username : 'tg://user?id=' . $lead->driver_telegram_id;

        return '<a href="' . SenderBotCards::e($link) . '">' . $name . '</a>'
            . ($lead->driver_username ? ' @' . SenderBotCards::e($lead->driver_username) : '');
    }

    private function tellOperator(BotApi $api, TelegramSenderBotUser $operator, string $html): void
    {
        $this->quietly(fn () => $api->call('sendMessage', [
            'chat_id' => $operator->telegram_user_id,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]));
    }

    /** @return array<string, string|int> */
    private function who(TelegramSenderBotLead $lead): array
    {
        return array_filter([
            'driver' => $lead->driverLabel(),
            'driver_id' => $lead->driver_telegram_id,
            'username' => $lead->driver_username,
            'phone' => $lead->driver_phone,
            'source' => $lead->source,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private function send(BotApi $api, int $chatId, string $text, ?array $keyboard = null): void
    {
        $api->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $keyboard,
        ], static fn ($v) => $v !== null));
    }

    /** The driver's own Telegram language: Russian, or else Uzbek. */
    public static function locale(array $from): string
    {
        return str_starts_with(mb_strtolower((string) ($from['language_code'] ?? '')), 'ru') ? 'ru' : 'uz';
    }

    private static function normal(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, 4000))));
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            Log::info('Sender bot: a call that may fail did', ['error' => $e->getMessage()]);
        }
    }
}
