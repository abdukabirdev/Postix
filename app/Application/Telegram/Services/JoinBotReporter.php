<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use Throwable;

/**
 * Our join request bot tells the owner about everything it does: every
 * request it approved or could not, every one of our accounts it let in, a
 * link it made, being made an admin somewhere or removed, a token Telegram
 * stopped taking. One plain message per action, to the chat set in the
 * panel.
 *
 * A bot may write to a person only after they have opened it and pressed
 * Start: until then the page says the reports do not get through. A report
 * never stops the work it tells about.
 */
final class JoinBotReporter
{
    /** A 429 this short is waited out once, seconds. */
    private const WAIT_IN_PLACE = 5;

    public function __construct(private readonly JoinBotSettings $settings) {}

    /**
     * @param string               $key     driver_check.invites.bot.report.<key>
     * @param array<string, mixed> $replace
     * @param bool                 $silent  arrives without a sound (the spam list's deletions)
     *
     * @return bool whether it got through
     */
    public function send(string $key, array $replace = [], bool $silent = false): bool
    {
        try {
            $reports = $this->settings->reports();
            $token = $this->settings->token();

            if (! $reports['enabled'] || $reports['chat_id'] === null || $token === null) {
                return false;
            }

            $replace = array_filter($replace, static fn ($value) => $value !== null);

            if (isset($replace['reason'])) {
                $replace['reason'] = self::reason((string) $replace['reason'], $reports['locale']);
            }

            $text = (string) __("driver_check.invites.bot.report.{$key}", $replace, $reports['locale']);

            return $this->deliver($token, $reports['chat_id'], $text, $silent) === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * From the panel: a test message to the chat set there.
     *
     * @return string|null null: it got through; else Telegram's words
     */
    public function test(string $locale): ?string
    {
        $reports = $this->settings->reports();
        $token = $this->settings->token();

        if ($token === null || $reports['chat_id'] === null) {
            return 'no_chat';
        }

        return $this->deliver($token, $reports['chat_id'], (string) __('driver_check.invites.bot.report.test', [
            'bot' => '@' . ($this->settings->current()['bot']['username'] ?? ''),
        ], $locale));
    }

    /**
     * Telegram's code in words, as the page says it; an unknown one as it is.
     */
    private static function reason(string $code, string $locale): string
    {
        foreach (["driver_check.invites.bot.errors_tg.{$code}", "driver_check.invites.errors.{$code}"] as $key) {
            $text = __($key, [], $locale);

            if (is_string($text) && $text !== $key) {
                return "{$text} ({$code})";
            }
        }

        return $code;
    }

    /**
     * @return string|null null: it got through; else why not
     */
    private function deliver(string $token, string $chatId, string $text, bool $silent = false): ?string
    {
        $api = new BotApi($token);

        for ($try = 1; ; $try++) {
            try {
                $api->call('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 4000),
                    'link_preview_options' => ['is_disabled' => true],
                    ...($silent ? ['disable_notification' => true] : []),
                ], 10);

                $this->remember(['sent_at' => now()->toIso8601String(), 'error' => null, 'error_at' => null]);

                return null;
            } catch (BotApiError $e) {
                if ($try === 1 && $e->getCode() === 429 && $e->retryAfter > 0 && $e->retryAfter <= self::WAIT_IN_PLACE) {
                    sleep($e->retryAfter);

                    continue;
                }

                $error = $e->reason();
            }

            $this->remember(['error' => mb_substr($error, 0, 300), 'error_at' => now()->toIso8601String()]);

            return $error;
        }
    }

    /**
     * @param array<string, string|null> $state
     */
    private function remember(array $state): void
    {
        try {
            $this->settings->setReportsState($state);
        } catch (Throwable) {
            // The page shows an older state; the report went or did not.
        }
    }
}
