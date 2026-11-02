<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use danog\MadelineProto\API;

/**
 * How many people a group says it has, its members list hidden or not.
 * MadelineProto does not let channels.getFullChannel be called directly
 * ($api->channels->... throws); getFullInfo calls it, or answers from its
 * own copy up to an hour old.
 */
class MemberCount
{
    public function of(API $api, int|string $peer): ?int
    {
        $full = $api->getFullInfo($peer);

        return isset($full['full']['participants_count']) ? (int) $full['full']['participants_count'] : null;
    }
}
