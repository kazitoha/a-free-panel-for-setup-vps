<?php

namespace App\Support;

/**
 * Reads a secret (such as a database password) from standard input.
 *
 * Privileged panel commands receive secrets this way so they never appear in
 * the process table or sudo audit logs. Tests fake this class to supply the
 * value without touching the real stdin.
 */
class SecretInput
{
    /**
     * Read the whole of standard input and strip one trailing newline.
     */
    public function read(): string
    {
        $contents = @file_get_contents('php://stdin');

        if ($contents === false || $contents === '') {
            return '';
        }

        return rtrim($contents, "\r\n");
    }
}
