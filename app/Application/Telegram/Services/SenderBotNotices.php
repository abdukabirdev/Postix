<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSenderBotUser;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The admin hears of every new request to the sender bot from a listener
 * account (a plain private message, no buttons: those are in the panel
 * and, once the admin has pressed Start there, in the bot itself). Run by
 * the listener chosen in the panel, on its tick.
 */
final class SenderBotNotices
{
    /** The test from the panel: asked at, sent at, its error. */
    public const TEST_KEY = 'sender_bot.notify_test';

    /** A request older than this is not announced any more, hours. */
    private const FRESH_HOURS = 24;

    public function __construct(private readonly SenderBotSettings $settings) {}

    public function flush(SimpleEventHandler $telegram, string $key): void
    {
        if ($this->settings->token() === null || $this->settings->notifyListener()?->key !== $key) {
            return;
        }

        $admin = $this->settings->adminId();

        if ($admin === null) {
            return;
        }

        $this->test($telegram, $admin);

        $waiting = TelegramSenderBotUser::query()
            ->where('status', TelegramSenderBotUser::STATUS_PENDING)
            ->whereNull('notified_at')
            ->whereNull('notify_error')
            ->where('requested_at', '>=', now()->subHours(self::FRESH_HOURS))
            ->orderBy('requested_at')
            ->limit(5)
            ->get();

        foreach ($waiting as $user) {
            $error = $this->send($telegram, $admin, SenderBotDialog::requestText($user, withLink: true));

            $user->forceFill($error === null
                ? ['notified_at' => now(), 'notify_error' => null]
                : ['notify_error' => mb_substr($error, 0, 500)])->save();
        }
    }

    /** The panel asked for a test message. */
    public static function askTest(): void
    {
        TelegramSetting::set(self::TEST_KEY, ['requested_at' => now()->toIso8601String()]);
    }

    /**
     * @return array{requested_at: string|null, sent_at: string|null, error: string|null}
     */
    public static function testState(): array
    {
        $state = TelegramSetting::get(self::TEST_KEY);
        $state = is_array($state) ? $state : [];

        return [
            'requested_at' => $state['requested_at'] ?? null,
            'sent_at' => $state['sent_at'] ?? null,
            'error' => $state['error'] ?? null,
        ];
    }

    private function test(SimpleEventHandler $telegram, int $admin): void
    {
        $state = self::testState();

        if ($state['requested_at'] === null || $state['sent_at'] !== null || $state['error'] !== null) {
            return;
        }

        $error = $this->send($telegram, $admin, __('sender_bot.admin_test', [], SenderBotDialog::ADMIN_LOCALE));

        TelegramSetting::set(self::TEST_KEY, [
            'requested_at' => $state['requested_at'],
            'sent_at' => $error === null ? now()->toIso8601String() : null,
            'error' => $error,
        ]);
    }

    private function send(SimpleEventHandler $telegram, int $admin, string $text): ?string
    {
        try {
            $telegram->messages->sendMessage([
                'peer' => $admin,
                'message' => $text,
                'no_webpage' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('Sender bot: the listener could not tell the admin', ['error' => $e->getMessage()]);

            return $e->getMessage();
        }

        return null;
    }
}
