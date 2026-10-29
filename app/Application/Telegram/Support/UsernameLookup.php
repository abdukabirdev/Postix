<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use danog\MadelineProto\API;

/**
 * The person behind a public @username. MadelineProto does not let
 * contacts.resolveUsername be called directly ($api->contacts->... throws);
 * getInfo looks the name up through it, or in its own peer base, and keeps
 * the access hash in the session for the invite. A name nobody has throws
 * PeerNotInDbException.
 */
class UsernameLookup
{
    /**
     * @return array<string, mixed>|null the user, null when the name is a group's or a channel's
     */
    public function user(API $api, string $username): ?array
    {
        $info = $api->getInfo('@' . ltrim($username, '@'));

        return is_array($info) && is_array($info['User'] ?? null) ? $info['User'] : null;
    }
}
