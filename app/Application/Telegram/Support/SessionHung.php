<?php

declare(strict_types=1);

namespace App\Application\Telegram\Support;

use RuntimeException;

/**
 * The account's session did not answer in time (SessionProbe): the account,
 * not the run, is in trouble - the run goes on with the others.
 */
final class SessionHung extends RuntimeException {}
