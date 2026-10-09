<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A mail domain/mailbox form failed validation. Field-keyed messages, so
 * controllers can flash them onto the form — mirrors InvalidDnsRecord.
 */
class InvalidMailRecord extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }
}
