<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramAccountSpamCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Asks Telegram's own @SpamBot about an account: whether Telegram limits it
 * (no messages to strangers, nobody added into groups) and until when. The
 * account writes /start to the bot and reads the answer, in a process of
 * its own (telegram:spambot-ask) with a time limit - a session that hangs
 * must not hold the asking. Every answer is kept (telegram_account_spam_checks).
 *
 * The bot writes in the account's language; English, Russian and Uzbek
 * answers are read, anything else is kept as "unknown" with its text.
 */
final class SpamBotCheck
{
    /** How long the account's process gets to start the session, ask and read. Seconds. */
    public const TIME_LIMIT = 150;

    /** The child's last line: its answer, as JSON. */
    public const MARK = '@@spambot ';

    /** The bot's words kept, characters. */
    private const TEXT_LIMIT = 2000;

    /** @var callable(TelegramAccount): string|null a stand-in for the child process (tests) */
    private $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner;
    }

    /**
     * Asks about one account and keeps the answer.
     */
    public function ask(TelegramAccount $account): TelegramAccountSpamCheck
    {
        try {
            $output = $this->runner !== null ? ($this->runner)($account) : $this->run($account);
        } catch (ProcessTimedOutException) {
            return $this->keep($account, TelegramAccountSpamCheck::FAILED, error: 'No answer from the session for ' . self::TIME_LIMIT . ' s');
        } catch (Throwable $e) {
            Log::warning('SpamBot check could not run', ['account_id' => $account->id, 'message' => $e->getMessage()]);

            return $this->keep($account, TelegramAccountSpamCheck::FAILED, error: mb_substr($e->getMessage(), 0, 500));
        }

        $said = self::said($output);

        if ($said === null) {
            return $this->keep($account, TelegramAccountSpamCheck::FAILED, error: 'The process ended without an answer');
        }

        if (! $said['ok']) {
            return $this->keep($account, TelegramAccountSpamCheck::FAILED, error: $said['error'] !== '' ? $said['error'] : 'Failed to ask');
        }

        $verdict = self::verdict($said['text']);

        return $this->keep($account, $verdict['status'], $verdict['until'], $said['text']);
    }

    /**
     * The latest answer about each account, by account id.
     *
     * @return Collection<int, TelegramAccountSpamCheck>
     */
    public static function latest(): Collection
    {
        return TelegramAccountSpamCheck::query()
            ->whereIn('id', TelegramAccountSpamCheck::query()->selectRaw('MAX(id)')->groupBy('telegram_account_id'))
            ->get()
            ->keyBy('telegram_account_id');
    }

    /**
     * What the bot's words mean: free, limited (until when, if it said),
     * or not understood.
     *
     * @return array{status: string, until: Carbon|null}
     */
    public static function verdict(string $text): array
    {
        $flat = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (preg_match('/no limits|free as a bird|не наложено|никаких ограничений|нет ограничений|cheklov(?:lar)? (?:yo\'q|qo\'yilmagan)|cheklanmagan/iu', $flat)) {
            return ['status' => TelegramAccountSpamCheck::FREE, 'until' => null];
        }

        if (preg_match('/limited|limitations|restrict|ограничен|cheklan|cheklov/iu', $flat)) {
            return ['status' => TelegramAccountSpamCheck::LIMITED, 'until' => self::until($flat)];
        }

        return ['status' => TelegramAccountSpamCheck::UNKNOWN, 'until' => null];
    }

    /**
     * The line the child ends with.
     */
    public static function line(bool $ok, ?string $text = null, ?string $error = null): string
    {
        return self::MARK . json_encode(['ok' => $ok, 'text' => $text, 'error' => $error], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The child's answer: its last MARK line.
     *
     * @return array{ok: bool, text: string, error: string}|null
     */
    public static function said(string $output): ?array
    {
        foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = rtrim($line, "\r");

            if (! str_starts_with($line, self::MARK)) {
                continue;
            }

            $said = json_decode(substr($line, strlen(self::MARK)), true);

            return is_array($said)
                ? ['ok' => (bool) ($said['ok'] ?? false), 'text' => (string) ($said['text'] ?? ''), 'error' => (string) ($said['error'] ?? '')]
                : null;
        }

        return null;
    }

    /**
     * "until 11 Nov 2026, 06:10 UTC", "до 11 ноя. 2026 г., 06:10 UTC": the
     * moment Telegram named, when it can be read.
     */
    private static function until(string $text): ?Carbon
    {
        if (! preg_match('/(?:until|до|gacha)\s*[:\-]?\s*([0-9]{1,2}\s+[^\d\s,.]+\.?\s+[0-9]{4}(?:\s*г\.)?(?:,?\s*[0-9]{1,2}:[0-9]{2})?(?:\s*UTC)?)/iu', $text, $m)
            && ! preg_match('/(?:until|до)\s*[:\-]?\s*([A-Za-z]{3,9}\.?\s+[0-9]{1,2},?\s+[0-9]{4}(?:,?\s*[0-9]{1,2}:[0-9]{2})?(?:\s*UTC)?)/iu', $text, $m)) {
            return null;
        }

        $when = str_replace(['г.', ' г'], '', $m[1]);
        $when = preg_replace_callback('/[А-Яа-яЁё]+\.?/u', fn (array $w) => self::MONTHS[mb_strtolower(mb_substr(rtrim($w[0], '.'), 0, 3))] ?? $w[0], $when) ?? $when;

        try {
            return Carbon::parse(trim($when), 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /** Russian month names, as the bot cuts them, to English. */
    private const MONTHS = [
        'янв' => 'Jan', 'фев' => 'Feb', 'мар' => 'Mar', 'апр' => 'Apr', 'мая' => 'May', 'май' => 'May', 'июн' => 'Jun',
        'июл' => 'Jul', 'авг' => 'Aug', 'сен' => 'Sep', 'окт' => 'Oct', 'ноя' => 'Nov', 'дек' => 'Dec',
    ];

    private function run(TelegramAccount $account): string
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'telegram:spambot-ask', (string) $account->id], base_path());
        $process->setTimeout(self::TIME_LIMIT);
        $process->run();

        return $process->getOutput();
    }

    private function keep(TelegramAccount $account, string $status, ?Carbon $until = null, ?string $text = null, ?string $error = null): TelegramAccountSpamCheck
    {
        return TelegramAccountSpamCheck::query()->create([
            'telegram_account_id' => $account->id,
            'status' => $status,
            'limited_until' => $until,
            'text' => $text !== null ? mb_substr($text, 0, self::TEXT_LIMIT) : null,
            'error' => $error,
        ]);
    }
}
