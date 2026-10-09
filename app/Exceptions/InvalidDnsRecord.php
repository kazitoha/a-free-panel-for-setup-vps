<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A DNS zone/record form failed validation. Field-keyed messages, mirroring
 * InvalidSiteSpec so controllers can flash them onto the form.
 */
class InvalidDnsRecord extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }
}
