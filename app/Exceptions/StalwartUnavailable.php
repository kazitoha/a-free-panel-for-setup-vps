<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Stalwart HTTP API could not be reached at all (connection refused,
 * timeout) — i.e. the service is not installed, not running, or still
 * starting up.
 */
class StalwartUnavailable extends RuntimeException
{
    public static function from(\Throwable $previous): self
    {
        return new self('Stalwart is not reachable on '.config('panel.stalwart.url').'.', 0, $previous);
    }
}
