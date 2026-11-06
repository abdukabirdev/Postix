<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Application\Telegram\Support\BotApi;
use App\Application\Telegram\Support\BotApiError;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramSpamAdmin;
use App\Models\Telegram\TelegramSpamSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The owner forwards a spammer's message from a group to the join request
 * bot: its author goes onto the spam list, and from then on SpamCleaner
 * deletes whatever they write. The forwarded message itself is left as it
 * is, and the bot keeps nothing of the groups for it. The owner is answered
 * in the bot's chat, under the forward.
 *
 * Telegram names the author in the forward: a person, or the channel they
 * wrote as. It does not when they hide their profile in forwards, nor who
 * forwarded a channel's post into the group - then the owner is told to add
 * them by ID or @username on the page.
 *
 * Not a forward but the spammer named: their ID, @username or t.me link
 * in the text, their contact, a mention of them - they go onto the list
 * the same way.
 *
 * Only the admins of the anti-spam may do it (SpamAdmins: the owner and
 * the panel's list); anybody else writing to the bot gets no answer. An
 * anonymous admin of a group, our own accounts, the admins and the bot
 * itself are never listed.
 *
 * An add-on: a trouble of its own is logged and never stops the bot.
 */
final class ForwardedSpam
{
    /** @var array<string, true> albums answered already: one answer for all their parts */
    private array $albums = [];

    public function __construct(
        private readonly JoinBotSettings $settings,
        private readonly SpamAdmins $admins,
        private readonly SpamCleaner $spam,
    ) {}

    /**
     * A message to the bot in its own chat. An admin's forward: its
     * author onto the list; an admin naming somebody: they onto the list;
     * anything else from an admin gets a word on how it works. Others: no
     * answer.
     *
     * @param array<string, mixed>   $message
     * @param callable(string): void $say
     */
    public function take(BotApi $api, array $message, callable $say): void
    {
        $chat = (array) ($message['chat'] ?? []);
        $from = (array) ($message['from'] ?? []);

        if (($chat['type'] ?? null) !== 'private' || empty($from['id']) || ! empty($from['is_bot']) || empty($message['message_id'])) {
            return;
        }

        try {
            if (! $this->admins->isAdmin($from)) {
                $say('forward: not an admin, no answer: ' . JoinRequestService::who($from));

                return;
            }

            /* An album comes as one message a part: the first one answers for all. */
            $album = isset($message['media_group_id']) ? (string) $message['media_group_id'] : null;

            if ($album !== null && isset($this->albums[$album])) {
                return;
            }

            $origin = $message['forward_origin'] ?? null;

            if (! is_array($origin)) {
                $people = $this->named($message);

                $this->answer($api, $message, match (true) {
                    $people === null => $this->line('contact_unknown'),
                    $people === [] => $this->line('hint'),
                    default => $this->enlistNamed($people, $say),
                });

                return;
            }

            if ($album !== null) {
                $this->albums[$album] = true;
            }

            $this->answer($api, $message, $this->handle($origin, $say));
        } catch (Throwable $e) {
            Log::warning('Join bot: a forwarded message could not be handled', ['error' => $e->getMessage()]);
            $say('forward: ' . $e->getMessage());
        }
    }

    /**
     * The author the forward names onto the list.
     *
     * @param array<string, mixed>   $origin Bot API forward_origin
     * @param callable(string): void $say
     *
     * @return string the answer
     */
    private function handle(array $origin, callable $say): string
    {
        $type = (string) ($origin['type'] ?? '');

        if ($type === 'hidden_user') {
            return $this->line('hidden', ['name' => (string) ($origin['sender_user_name'] ?? '')]);
        }

        $actor = match ($type) {
            'user' => $origin['sender_user'] ?? null,
            'chat', 'channel' => $origin['sender_chat'] ?? $origin['chat'] ?? null,
            default => null,
        };

        if (! is_array($actor) || empty($actor['id'])) {
            return $this->line('unknown');
        }

        $who = JoinRequestService::who([
            'first_name' => SpamCleaner::nameOf($actor),
            'username' => $actor['username'] ?? null,
            'id' => $actor['id'],
        ]);

        /* A channel's post forwarded into the group: who forwarded it Telegram does not say. */
        if ($type === 'channel') {
            return $this->line('channel', ['who' => $who]);
        }

        /* Written as the group itself: its anonymous admin. */
        if ($type === 'chat' && in_array($actor['type'] ?? null, ['group', 'supergroup'], true)) {
            return $this->line('anonymous_admin');
        }

        if ($this->isOurs($actor)) {
            $say("forward: {$who} is ours, not listed");

            return $this->line('ours', ['who' => $who]);
        }

        $isNew = $this->enlist($actor, $this->line('note'));
        $say("forward: {$who} " . ($isNew ? 'put on the spam list' : 'is on the spam list already'));

        return $this->line($isNew ? 'listed' : 'listed_already', ['who' => $who]);
    }

    /**
     * Whom an admin's own message names: the contact sent, the people
     * mentioned without a @username, the ids, @usernames and t.me links of
     * the text. Words that are none of these are passed over.
     *
     * @param array<string, mixed> $message
     *
     * @return list<array<string, mixed>>|null null: a contact with no Telegram account
     */
    private function named(array $message): ?array
    {
        if (isset($message['contact'])) {
            $contact = (array) $message['contact'];

            if (empty($contact['user_id'])) {
                return null;
            }

            return [['id' => (int) $contact['user_id'], 'first_name' => $contact['first_name'] ?? null, 'last_name' => $contact['last_name'] ?? null]];
        }

        $people = [];

        foreach ((array) ($message['entities'] ?? []) as $entity) {
            $user = (array) ($entity['user'] ?? []);

            if (($entity['type'] ?? null) === 'text_mention' && ! empty($user['id'])) {
                $people['id' . (int) $user['id']] = $user;
            }
        }

        foreach (SpamCleaner::parse((string) ($message['text'] ?? ''))['entries'] as $entry) {
            $key = $entry['telegram_user_id'] !== null ? 'id' . $entry['telegram_user_id'] : '@' . $entry['username'];
            $people[$key] ??= ['id' => $entry['telegram_user_id'], 'username' => $entry['username']];
        }

        return array_values($people);
    }

    /**
     * The people an admin named onto the list, a line of the answer each.
     *
     * @param list<array<string, mixed>> $people
     * @param callable(string): void     $say
     */
    private function enlistNamed(array $people, callable $say): string
    {
        $lines = [];

        foreach ($people as $person) {
            $id = isset($person['id']) ? (int) $person['id'] : null;
            $username = SpamCleaner::username($person['username'] ?? null);
            $name = SpamCleaner::nameOf($person);
            $who = $id !== null
                ? JoinRequestService::who(['first_name' => $name, 'username' => $person['username'] ?? null, 'id' => $id])
                : '@' . $username;

            if ($id !== null ? $this->isOurs($person) : $this->isOursByUsername((string) $username)) {
                $say("named: {$who} is ours, not listed");
                $lines[] = $this->line('ours', ['who' => $who]);

                continue;
            }

            /* Only an id or a @username: the page's way, the name from the base of people. */
            $isNew = $id !== null && ($name !== null || $username !== null)
                ? $this->enlist($person, $this->line('note_named'))
                : $this->spam->add([['telegram_user_id' => $id, 'username' => $username]], $this->line('note_named'))['added'] === 1;

            $say("named: {$who} " . ($isNew ? 'put on the spam list' : 'is on the spam list already'));
            $lines[] = $this->line($isNew ? 'listed' : 'listed_already', ['who' => $who]);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Our bot, an admin of the anti-spam, one of our accounts.
     *
     * @param array<string, mixed> $actor
     */
    private function isOurs(array $actor): bool
    {
        $id = (int) $actor['id'];
        $username = SpamCleaner::username($actor['username'] ?? null);

        return $id === (int) ($this->settings->current()['bot']['id'] ?? 0)
            || $this->admins->isAdmin($actor)
            || TelegramAccount::query()->where('telegram_user_id', $id)->exists()
            || ($username !== null && $this->isOursByUsername($username));
    }

    /**
     * The same by a @username alone: the bot, an admin put on by it, one
     * of our accounts.
     */
    private function isOursByUsername(string $username): bool
    {
        return $username === SpamCleaner::username($this->settings->current()['bot']['username'] ?? null)
            || TelegramSpamAdmin::query()->where('username', $username)->exists()
            || TelegramAccount::query()->whereRaw('LOWER(username) = ?', [$username])->exists();
    }

    /**
     * Onto the list, or their line brought up to date (one typed by
     * @username learns the id).
     *
     * @param array<string, mixed> $actor
     *
     * @return bool whether they were not on the list yet
     */
    private function enlist(array $actor, string $note): bool
    {
        $id = (int) $actor['id'];
        $username = SpamCleaner::username($actor['username'] ?? null);
        $name = SpamCleaner::cut(SpamCleaner::nameOf($actor), 255);

        $sender = TelegramSpamSender::query()->where('telegram_user_id', $id)->first();

        if ($sender === null && $username !== null) {
            $sender = TelegramSpamSender::query()->whereNull('telegram_user_id')->where('username', $username)->first();
        }

        $isNew = $sender === null;
        $sender ??= new TelegramSpamSender(['note' => $note]);
        $sender->telegram_user_id ??= $id;
        $sender->username = $username ?? $sender->username;
        $sender->name = $name ?? $sender->name;
        $sender->save();

        return $isNew;
    }

    /**
     * The answer, under the forwarded message.
     *
     * @param array<string, mixed> $message
     */
    private function answer(BotApi $api, array $message, string $text): void
    {
        try {
            $api->call('sendMessage', [
                'chat_id' => (int) $message['chat']['id'],
                'text' => mb_substr($text, 0, 4000),
                'reply_parameters' => ['message_id' => (int) $message['message_id'], 'allow_sending_without_reply' => true],
                'link_preview_options' => ['is_disabled' => true],
            ], 10);
        } catch (BotApiError $e) {
            Log::warning('Join bot: the answer to a forward did not get through', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return (string) __("driver_check.invites.spam.forward.{$key}", $replace, $this->settings->reports()['locale']);
    }
}
