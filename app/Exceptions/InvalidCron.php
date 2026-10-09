<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a cron command or schedule fails validation. Carries
 * field-keyed messages so web controllers can flash them onto a form.
 */
class InvalidCron extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }
}
