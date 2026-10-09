<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The PowerDNS API could not be reached at all (connection refused, DNS
 * resolution failure, timeout) — i.e. PowerDNS is not installed or down.
 */
class PowerDnsUnavailable extends RuntimeException
{
    public static function from(\Throwable $previous): self
    {
        return new self('The PowerDNS API is not reachable on '.config('panel.pdns.url').'.', 0, $previous);
    }
}
