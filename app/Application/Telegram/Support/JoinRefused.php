<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use RuntimeException;

/**
 * The account asked to join by our bot's link and is not in: the bot could
 * not let it in (the message is the reason). This account cannot add
 * anybody to the group in this run; the group and the account are fine.
 */
final class JoinRefused extends RuntimeException {}
