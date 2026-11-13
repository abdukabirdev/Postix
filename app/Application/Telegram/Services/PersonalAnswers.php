<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\OperationUser;

/**
 * A person's own answers, kept on their card (operation_users.
 * personal_answers) and edited on the "Личные ответы" page: a voice message
 * saying their name for the first penalty, a "Доброе утро" of their own.
 *
 * By situation (a slot):
 *
 *   penalty:<level>   - the penalty comment at that level of their role's
 *                       ladder (0 - the first level)
 *   reply:<kind id>   - an auto reply kind (AutoReplyRules ids)
 *   greeting:<id>     - an auto reply greeting
 *   silence           - the nudge after ignored penalties
 *   ping              - the call by name: the person's name alone ("Зуба",
 *                       "Рушана апа") when a penalty stays unanswered
 *   opening           - our greeting before the day's first penalty
 *
 * Each holds texts (placeholders as in the shared ones), voice messages and
 * GIFs - files of the auto replies media folder - and `only`: false adds
 * them to what everyone gets, one of them comes up as often as any shared
 * phrase; true sends only them.
 */
final class PersonalAnswers
{
    public const MAX_SLOTS = 60;

    public const MAX_ITEMS = 20;

    public const TYPE_TEXT = 'text';

    public const SILENCE = 'silence';

    public const OPENING = 'opening';

    public const PING = 'ping';

    public static function penaltySlot(int $level): string
    {
        return 'penalty:' . $level;
    }

    public static function replySlot(string $id): string
    {
        return 'reply:' . $id;
    }

    public static function greetingSlot(string $id): string
    {
        return 'greeting:' . $id;
    }

    public static function validSlot(string $slot): bool
    {
        return in_array($slot, [self::SILENCE, self::OPENING, self::PING], true)
            || preg_match('/^(penalty:\d{1,2}|reply:[a-z0-9]{1,24}|greeting:[a-z0-9]{1,24})$/', $slot) === 1;
    }

    /**
     * What may be stored: unknown slots, empty ones and files AutoReplyMedia
     * never gave are dropped, so a hand edit cannot point the listener at
     * any other file.
     *
     * @return array<string, array{only: bool, items: list<array<string, mixed>>}>
     */
    public static function sanitize(mixed $slots): array
    {
        $clean = [];

        foreach ((array) $slots as $slot => $data) {
            if (count($clean) >= self::MAX_SLOTS) {
                break;
            }

            if (! is_string($slot) || ! self::validSlot($slot)) {
                continue;
            }

            $data = (array) $data;
            $items = [];

            foreach ((array) ($data['items'] ?? []) as $item) {
                $item = self::item((array) $item);

                if ($item !== null) {
                    $items[] = $item;
                }

                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }

            if ($items !== []) {
                $clean[$slot] = ['only' => (bool) ($data['only'] ?? false), 'items' => $items];
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private static function item(array $item): ?array
    {
        $type = $item['type'] ?? null;

        if ($type === self::TYPE_TEXT) {
            $text = is_string($item['text'] ?? null) ? trim($item['text']) : '';

            return $text !== '' ? ['type' => self::TYPE_TEXT, 'text' => mb_substr($text, 0, 1000)] : null;
        }

        if (! in_array($type, [AutoReplyRules::MEDIA_GIF, AutoReplyRules::MEDIA_VOICE], true)) {
            return null;
        }

        $file = is_string($item['file'] ?? null) ? $item['file'] : '';

        if (! AutoReplyMedia::validName($file, AutoReplyMedia::extensions($type))) {
            return null;
        }

        $name = is_string($item['name'] ?? null) && trim($item['name']) !== '' ? trim($item['name']) : $file;

        $telegram = $type === AutoReplyRules::MEDIA_GIF ? AutoReplyRules::telegram($item['telegram'] ?? null) : null;

        return [
            'type' => $type,
            'file' => $file,
            'name' => mb_substr($name, 0, 120),
            ...($telegram !== null ? ['telegram' => $telegram] : []),
        ];
    }

    /**
     * @return array<string, array{only: bool, items: list<array<string, mixed>>}>
     */
    public function all(OperationUser $person): array
    {
        return self::sanitize($person->personal_answers ?? []);
    }

    /**
     * The person's answers for one situation, as the delivery takes them:
     * a text says in which language it is written - the person's.
     *
     * @return array{only: bool, items: list<array<string, mixed>>}
     */
    public function slot(OperationUser $person, string $slot): array
    {
        $own = $this->all($person)[$slot] ?? ['only' => false, 'items' => []];

        $language = $person->messageLanguage();

        $own['items'] = array_map(
            static fn (array $item): array => $item['type'] === self::TYPE_TEXT ? [...$item, 'language' => $language] : $item,
            $own['items'],
        );

        return $own;
    }

    /**
     * AutoReplyRules::choices() with the person's own answers: added to
     * the shared ones, or in their place.
     *
     * @param array{language: string, items: list<array<string, mixed>>} $choices
     * @return array{language: string, items: list<array<string, mixed>>}
     */
    public function merge(array $choices, OperationUser $person, string $slot): array
    {
        $own = $this->slot($person, $slot);

        if ($own['items'] === []) {
            return $choices;
        }

        return [...$choices, 'items' => $own['only'] ? $own['items'] : [...$choices['items'], ...$own['items']]];
    }

    /**
     * Every media file anyone's answers use, so AutoReplyMedia::prune()
     * does not take them for leftovers.
     *
     * @return list<string>
     */
    public function mediaFiles(): array
    {
        $files = [];

        OperationUser::query()
            ->whereNotNull('personal_answers')
            ->select(['id', 'personal_answers'])
            ->each(function (OperationUser $person) use (&$files): void {
                foreach ($this->all($person) as $slot) {
                    foreach ($slot['items'] as $item) {
                        if (isset($item['file'])) {
                            $files[] = $item['file'];
                        }
                    }
                }
            });

        return array_values(array_unique($files));
    }

    /**
     * How many of each kind a person has, for the people list.
     *
     * @return array{text: int, voice: int, gif: int, slots: int}
     */
    public function counts(OperationUser $person): array
    {
        $counts = ['text' => 0, 'voice' => 0, 'gif' => 0, 'slots' => 0];

        foreach ($this->all($person) as $slot) {
            $counts['slots']++;

            foreach ($slot['items'] as $item) {
                $counts[$item['type']]++;
            }
        }

        return $counts;
    }
}
