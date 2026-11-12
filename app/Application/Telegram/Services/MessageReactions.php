<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A reaction under a message in a private chat (updateMessageReactions),
 * read as an answer of the person: some answer with an emoji instead of a
 * word (👍, 🤝), and that is an answer - ProcessAutoReply keeps it on the
 * card and the penalty like a message, so no "why are you silent" follows.
 *
 * Only the other side's reaction counts, one that is new: the same emoji
 * under the same message is taken once, and a reaction long past (the
 * listener starting up on old updates) is not taken at all.
 */
final class MessageReactions
{
    /** A reaction older than this is not an answer of the moment. Seconds. */
    private const FRESH_SECONDS = 600;

    /** The text of a reaction that is not an emoji we can name. */
    private const CUSTOM = '[эмодзи]';

    /**
     * @param array<string, mixed> $update updateMessageReactions
     * @return array{sender: int, message: int, text: string, agrees: bool}|null
     *         the emoji (or a stand-in) as a message's text, and whether it
     *         means "ok"
     */
    public function incoming(array $update): ?array
    {
        $peer = $update['peer'] ?? [];

        if (($peer['_'] ?? null) !== 'peerUser') {
            return null;
        }

        $sender = (int) ($peer['user_id'] ?? 0);
        $message = (int) ($update['msg_id'] ?? 0);

        if ($sender <= 0 || $message <= 0) {
            return null;
        }

        foreach ($this->theirs($update['reactions'] ?? [], $sender) as $reaction) {
            $found = $this->describe($reaction);

            if ($found === null) {
                continue;
            }

            if (! Cache::add("reactions:seen:{$sender}:{$message}:{$found['text']}", true, now()->addDay())) {
                continue;
            }

            return ['sender' => $sender, 'message' => $message, ...$found];
        }

        return null;
    }

    /**
     * What the other person put under the message: from the list of who
     * reacted when Telegram gives it, else the counts - in a private chat
     * the ones we did not choose ourselves are theirs.
     *
     * @param array<string, mixed> $reactions
     * @return list<array<string, mixed>>
     */
    private function theirs(array $reactions, int $sender): array
    {
        $theirs = [];

        if (isset($reactions['recent_reactions'])) {
            foreach ($reactions['recent_reactions'] as $recent) {
                if (! empty($recent['my'])) {
                    continue;
                }

                $who = $recent['peer_id'] ?? [];

                if (($who['_'] ?? null) === 'peerUser' && (int) ($who['user_id'] ?? 0) !== $sender) {
                    continue;
                }

                if (isset($recent['date']) && time() - (int) $recent['date'] > self::FRESH_SECONDS) {
                    continue;
                }

                $theirs[] = $recent['reaction'] ?? [];
            }

            return $theirs;
        }

        foreach ($reactions['results'] ?? [] as $count) {
            if (! isset($count['chosen_order'])) {
                $theirs[] = $count['reaction'] ?? [];
            }
        }

        return $theirs;
    }

    /**
     * @param array<string, mixed> $reaction
     * @return array{text: string, agrees: bool}|null
     */
    private function describe(array $reaction): ?array
    {
        switch ($reaction['_'] ?? null) {
            case 'reactionEmoji':
                $emoji = (string) ($reaction['emoticon'] ?? '');

                return $emoji === '' ? null : ['text' => $emoji, 'agrees' => self::agrees($emoji)];

            case 'reactionCustomEmoji':
                return ['text' => self::CUSTOM, 'agrees' => (bool) config('auto_replies.reactions.custom_agrees', true)];

            case 'reactionPaid':
                return ['text' => '⭐', 'agrees' => false];

            default:
                return null;
        }
    }

    public static function agrees(string $emoji): bool
    {
        $plain = static fn (string $e): string => str_replace("\u{FE0F}", '', $e);

        foreach ((array) config('auto_replies.reactions.agree', []) as $agreed) {
            if ($plain((string) $agreed) === $plain($emoji)) {
                return true;
            }
        }

        return false;
    }
}
