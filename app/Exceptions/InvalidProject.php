<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a project specification fails validation. Carries field-keyed
 * messages so web controllers can flash them onto a form and artisan
 * commands can print them to an install log.
 */
class InvalidProject extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }
}
