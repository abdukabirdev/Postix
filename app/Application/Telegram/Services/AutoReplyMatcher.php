<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

/**
 * Reads a person's private message as one of the kinds of AutoReplyRules -
 * "+", "ok", "хоп", "bo'ldi" as "agreed" - by its keywords, the first kind
 * that has one winning.
 *
 * Both sides are compared loosely: case, Uzbek apostrophes (o' o‘ oʻ),
 * stretched letters ("okkk", "++"), ё/е and the Uzbek Cyrillic letters
 * typed without their marks (ў/у, қ/к, ғ/г, ҳ/х) do not count, and an
 * emoji stuck to a word ("ok👍") is a word of its own.
 *
 * A keyword ending in "*" matches the start of a word: "клиент*" takes
 * "клиента", "berdim*" takes "berdimku" - Uzbek and Russian add endings.
 */
final class AutoReplyMatcher
{
    /**
     * The index of the kind in $rules->replies, or null. A message longer
     * than the kind's max_words is a conversation, not an "ok": "ok, lekin
     * mashina hali topilmadi" is not something to thank for. Kinds that
     * expect an explanation ("клиент трубку не берёт") allow more.
     */
    public function match(AutoReplyRules $rules, string $text): ?int
    {
        $words = self::words($text);

        if ($words === []) {
            return null;
        }

        foreach ($rules->replies as $index => $kind) {
            if (count($words) > ($kind['max_words'] ?? $rules->maxWords)) {
                continue;
            }

            foreach ($kind['keywords'] as $keyword) {
                $prefix = str_ends_with(trim($keyword), '*');
                $needle = self::words(rtrim(trim($keyword), '*'));

                if ($needle !== [] && self::contains($words, $needle, $prefix)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Whether any of $keywords is in the message, read as match() reads a
     * kind's keywords ("*" at the end takes any ending).
     *
     * @param list<string> $keywords
     */
    public function mentions(array $keywords, string $text): bool
    {
        $words = self::words($text);

        foreach ($keywords as $keyword) {
            $prefix = str_ends_with(trim((string) $keyword), '*');
            $needle = self::words(rtrim(trim((string) $keyword), '*'));

            if ($needle !== [] && self::contains($words, $needle, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A greeting in the message - "Доброе утро. Готово" - read apart from
     * the rest (config auto_replies.greetings): the first greeting found,
     * the words left once every greeting is taken out, for match(), and
     * whether only fillers ("aka", "всем", "🙂") are left - a greeting
     * alone, not a conversation.
     *
     * @param list<array{keywords?: list<string>}> $greetings
     * @param list<string> $fillers
     * @return array{index: int, rest: string, alone: bool}|null
     */
    public function greeting(array $greetings, array $fillers, string $text): ?array
    {
        $words = self::words($text);
        $found = null;

        foreach ($greetings as $index => $greeting) {
            foreach ((array) ($greeting['keywords'] ?? []) as $keyword) {
                $prefix = str_ends_with(trim((string) $keyword), '*');
                $needle = self::words(rtrim(trim((string) $keyword), '*'));

                while ($needle !== [] && ($at = self::position($words, $needle, $prefix)) !== null) {
                    array_splice($words, $at, count($needle));
                    $found ??= $index;
                }
            }
        }

        if ($found === null) {
            return null;
        }

        $fillers = array_merge([], ...array_map(
            static fn (string $filler): array => self::words($filler),
            $fillers,
        ));

        return [
            'index' => $found,
            'rest' => implode(' ', $words),
            'alone' => array_diff($words, $fillers) === [],
        ];
    }

    /**
     * Letters and digits make a word; any other symbol ("+", "👍") is a
     * word on its own; punctuation is dropped.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');

        /*
         * Skin tones, the emoji variation selector and the joiner; letters
         * people type either way.
         */
        $text = (string) preg_replace('/[\x{1F3FB}-\x{1F3FF}\x{FE0F}\x{200D}]/u', '', $text);
        $text = strtr($text, ['ё' => 'е', 'ў' => 'у', 'қ' => 'к', 'ғ' => 'г', 'ҳ' => 'х']);

        preg_match_all(
            "/[\\p{L}\\p{N}\\p{M}'‘’ʻʼ`]+|[^\\s\\p{L}\\p{N}\\p{M}\\p{P}]/u",
            $text,
            $matches,
        );

        $words = [];

        foreach ($matches[0] as $word) {
            /*
             * bo'ldi, bo‘ldi, boldi - one word.
             */
            $word = str_replace(["'", '‘', '’', 'ʻ', 'ʼ', '`'], '', $word);

            /*
             * okkk -> ok, хоппп -> хоп, ++ -> +; done to the keyword as
             * well, so a real double letter still matches itself. Digits
             * stay as they are: 66 is not 6.
             */
            $word = (string) preg_replace('/([^\p{N}])\1+/u', '$1', $word);

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Whether $needle occurs in $words, in order and side by side; with
     * $prefix, its last word only has to start a word.
     *
     * @param list<string> $words
     * @param list<string> $needle
     */
    private static function contains(array $words, array $needle, bool $prefix): bool
    {
        return self::position($words, $needle, $prefix) !== null;
    }

    /**
     * Where $needle starts in $words, as contains() reads it; null when it
     * is not there.
     *
     * @param list<string> $words
     * @param list<string> $needle
     */
    private static function position(array $words, array $needle, bool $prefix): ?int
    {
        $length = count($needle);
        $last = $length - 1;

        for ($i = 0, $end = count($words) - $length; $i <= $end; $i++) {
            for ($j = 0; $j < $length; $j++) {
                $word = $words[$i + $j];

                $same = $prefix && $j === $last
                    ? str_starts_with($word, $needle[$j])
                    : $word === $needle[$j];

                if (! $same) {
                    continue 2;
                }
            }

            return $i;
        }

        return null;
    }
}
