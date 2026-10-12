<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request to ECPOS failed. The two flags decide what happens next, because sending an order twice would create it twice:
 *
 *  - retryable: ECPOS definitely did NOT receive the order (couldn't connect, or it said "busy"), so trying again is safe.
 *  - uncertain: ECPOS may or may not have received it (it timed out, or answered with a server error). Never retried
 *               automatically; an admin checks ECPOS and resends by hand.
 *
 * Neither flag set = ECPOS refused the order (bad item, wrong key...): retrying won't help.
 */
class EcposRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
