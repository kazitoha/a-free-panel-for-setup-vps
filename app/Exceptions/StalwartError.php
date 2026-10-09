<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Stalwart answered but rejected the request (bad credentials, validation
 * error, missing object…). The message is safe to show to the panel user.
 */
class StalwartError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $unauthorized = false)
    {
        parent::__construct($message);
    }
}
