<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Amp\delay;

/**
 * We greet first: before the day's first penalty to a person, "Assalomu
 * alaykum, Ali aka" (auto replies file, opening), then the penalty.
 *
 * On 2026-10-13 the penalty came first and the person greeted us - the
 * older one first, which the account's owner put right by hand.
 *
 * Once a day per person, the same mark ProcessAutoReply keeps for their
 * greeting: someone who greeted us first today is not greeted again, and
 * their "Va alaykum assalom" to ours is not greeted back.
 *
 * Not every day: auto_replies.send_chance.opening - a day without it starts
 * with the penalty, and stays so.
 */
final class PenaltyOpening
{
    /**
     * How long a second penalty of the same burst waits for the greeting
     * another one is sending, seconds.
     */
    private const WAIT_LIMIT = 20;

    public function __construct(
        private readonly AutoReplyStore $store,
        private readonly PersonalAnswers $personal,
        private readonly AutoReplyDelivery $delivery,
    ) {
    }

    public static function greetedKey(OperationUser $person): string
    {
        return 'auto-replies:greeted:' . $person->id;
    }

    /**
     * Greets $peer when nobody has today; returns once the penalty may
     * follow. A greeting Telegram refuses holds nothing up: the penalty
     * goes anyway, and the next one tries again.
     */
    public function before(
        SimpleEventHandler $telegram,
        int|string $peer,
        TelegramClientCheck $check,
        OperationUser $person,
    ): void {
        $rules = $this->store->current();

        if (
            ! $rules->opening['enabled']
            || ! $person->allows('greet_first_enabled')
            || Cache::has(self::greetedKey($person))
            || Cache::has($this->quietKey($person))
        ) {
            return;
        }

        /*
         * The mark above lives in the cache, which a deploy, a cache:clear or
         * a restart of the cache server empties in the middle of the day
         * (2026-10-15: greeted at 11:14 and again at 14:14). The penalty
         * that carried the greeting says it too, and that row stays.
         */
        if ($this->greetedToday($check, $person)) {
            Cache::put(self::greetedKey($person), true, now()->endOfDay());

            return;
        }

        /*
         * The quiet day (no greeting, by chance) lives only in the cache too:
         * on 2026-11-13 the deploys emptied it and people got "Здравствуйте"
         * at 12:10 or 13:05, after four penalties of that morning. Only
         * before the day's first penalty, then: one already sent today
         * means the day started without it.
         */
        if ($this->penaltySentToday($check, $person)) {
            Cache::put($this->quietKey($person), true, now()->endOfDay());

            return;
        }

        /*
         * The bot posts a person's penalties in bursts: one greets, the
         * others wait for it rather than overtake it.
         */
        if (! Cache::add($this->pendingKey($person), true, now()->addMinute())) {
            $this->waitFor($person);

            return;
        }

        try {
            /*
             * Decided once for the day: the rest of the burst, and the
             * penalties after it, find the mark. Their own greeting is
             * still greeted back (greetedKey is left alone).
             */
            if (! AutoReplyRules::speaks('opening')) {
                Cache::put($this->quietKey($person), true, now()->endOfDay());

                return;
            }

            $tone = $person->respectful ? ClientCheckRules::TONE_RESPECTFUL : ClientCheckRules::TONE_PLAIN;

            $choices = $this->personal->merge(
                self::friday($rules->choices('opening', $person->messageLanguage(), $tone), $person->messageLanguage(), $tone),
                $person,
                PersonalAnswers::OPENING,
            );

            if ($choices['items'] === []) {
                return;
            }

            $item = $choices['items'][array_rand($choices['items'])];

            $this->pause($telegram, $peer, $item['type']);

            try {
                $text = $this->delivery->send($telegram, $peer, [...$choices, 'items' => [$item]], $check, $person);
            } catch (Throwable $e) {
                Log::warning(
                    'Client check: greeting was not sent',
                    [
                        'check_id' => $check->id,
                        'operation_user_id' => $person->id,
                        'peer' => (string) $peer,
                        'error' => $e->getMessage(),
                    ],
                );

                return;
            }

            Cache::put(self::greetedKey($person), true, now()->endOfDay());

            $check->update(['opening_sent_at' => now()]);

            Log::info(
                'Client check: greeted first',
                [
                    'check_id' => $check->id,
                    'operation_user_id' => $person->id,
                    'text' => $text,
                ],
            );

            /*
             * The penalty a moment later, as its own message.
             */
            $this->pause($telegram, $peer, null);
        } finally {
            Cache::forget($this->pendingKey($person));
        }
    }

    /**
     * On Fridays the opening is a Friday one ("Жума муборак"):
     * auto_replies.friday_opening in the person's language and tone (the
     * other tone, then the other language, when one is missing); the usual
     * opening any other day or when none is written.
     *
     * @param array{language: string, items: list<array<string, mixed>>} $choices
     * @return array{language: string, items: list<array<string, mixed>>}
     */
    public static function friday(array $choices, string $language, string $tone): array
    {
        if (! now()->isFriday()) {
            return $choices;
        }

        $sets = (array) config('auto_replies.friday_opening', []);

        foreach ([$language, ...array_diff(['uz', 'ru'], [$language])] as $lang) {
            $tones = (array) ($sets[$lang] ?? []);
            $texts = array_values(array_filter(
                array_map('strval', (array) ($tones[$tone] ?? $tones[ClientCheckRules::TONE_PLAIN] ?? [])),
                static fn (string $text): bool => trim($text) !== '',
            ));

            if ($texts !== []) {
                return [
                    'language' => $lang,
                    'items' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $texts),
                ];
            }
        }

        return $choices;
    }

    /**
     * A greeting of ours already went to this person today (the penalty
     * that carried it is marked), whatever the cache says.
     */
    private function greetedToday(TelegramClientCheck $check, OperationUser $person): bool
    {
        return TelegramClientCheck::query()
            ->where('operation_user_id', $person->id)
            ->where('id', '!=', $check->id)
            ->where('opening_sent_at', '>=', now()->startOfDay())
            ->exists();
    }

    /**
     * Another penalty already went to this person today.
     */
    private function penaltySentToday(TelegramClientCheck $check, OperationUser $person): bool
    {
        return TelegramClientCheck::query()
            ->where('operation_user_id', $person->id)
            ->where('id', '!=', $check->id)
            ->where('forwarded_at', '>=', now()->startOfDay())
            ->exists();
    }

    private function pendingKey(OperationUser $person): string
    {
        return 'client-checks:opening:' . $person->id;
    }

    private function quietKey(OperationUser $person): string
    {
        return 'client-checks:opening-quiet:' . $person->id;
    }

    private function waitFor(OperationUser $person): void
    {
        $until = microtime(true) + self::WAIT_LIMIT;

        while (Cache::has($this->pendingKey($person)) && microtime(true) < $until) {
            delay(0.5);
        }
    }

    /**
     * auto_replies.greeting_pause, with "typing…" (or "recording…") on for
     * what comes next - nothing for the forward, $type null.
     */
    private function pause(SimpleEventHandler $telegram, int|string $peer, ?string $type): void
    {
        $min = max(0.0, (float) config('auto_replies.greeting_pause.min', 0));
        $max = max($min, (float) config('auto_replies.greeting_pause.max', $min));

        if ($max <= 0) {
            return;
        }

        if ($type !== null) {
            try {
                $telegram->messages->setTyping([
                    'peer' => $peer,
                    'action' => ['_' => match ($type) {
                        AutoReplyRules::MEDIA_VOICE => 'sendMessageRecordAudioAction',
                        AutoReplyRules::MEDIA_GIF => 'sendMessageChooseStickerAction',
                        default => 'sendMessageTypingAction',
                    }],
                ]);
            } catch (Throwable) {
                /*
                 * Only a nicety.
                 */
            }
        }

        delay($min + (mt_rand() / mt_getrandmax()) * ($max - $min));
    }
}
