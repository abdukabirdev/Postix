<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use danog\MadelineProto\EventHandler\Message\Entities\TextUrl;

/**
 * A message's text with its hidden links written out.
 *
 * The CRM bot's "Открыть запрос" is a word with a link behind it: the text
 * itself holds no address, so a copy made from the text alone loses it.
 * Here the address is put after the word, in brackets -
 * "Открыть запрос (https://crm…)" - the way PenaltyMessageText reads it.
 */
final class LinkedText
{
    /**
     * @param iterable<mixed> $entities the message's entities (MadelineProto objects)
     */
    public static function expand(string $text, iterable $entities): string
    {
        $links = [];

        foreach ($entities as $entity) {
            if ($entity instanceof TextUrl && $entity->url !== '' && ! str_contains($text, $entity->url)) {
                $links[] = $entity;
            }
        }

        if ($links === []) {
            return $text;
        }

        /* Telegram counts offsets in UTF-16 code units. */
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        usort($links, static fn (TextUrl $a, TextUrl $b): int => $b->offset <=> $a->offset);

        foreach ($links as $link) {
            $end = ($link->offset + $link->length) * 2;

            if ($end > strlen($utf16)) {
                continue;
            }

            $insert = mb_convert_encoding(' (' . $link->url . ')', 'UTF-16LE', 'UTF-8');
            $utf16 = substr($utf16, 0, $end) . $insert . substr($utf16, $end);
        }

        return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
    }
}
