<?php

namespace App\Support;

use App\Exceptions\InvalidDatabase;

/**
 * Validates database specifications for the panel.
 *
 * Identifiers are restricted to a strict character set so they can be
 * interpolated into SQL as backtick-quoted identifiers without escaping,
 * and passwords to a metacharacter-free set so they are safe as SQL string
 * literals. The same validation runs in the web controller (friendly form
 * errors) and inside the root-side `panel:database:*` commands (the security
 * boundary).
 */
class DatabaseSpec
{
    /**
     * Validate and normalize a database name.
     *
     * @return string The normalized name.
     *
     * @throws InvalidDatabase
     */
    public static function validateName(string $name): string
    {
        $name = strtolower(trim($name));
        $reserved = (array) config('panel.reserved_databases', []);

        if ($name === '') {
            throw new InvalidDatabase(['name' => 'Database name is required.']);
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidDatabase(['name' => 'Use lowercase letters, digits and underscores, starting with a letter (max 64 characters).']);
        }

        if (in_array($name, $reserved, true)) {
            throw new InvalidDatabase(['name' => 'That database name is reserved by the panel.']);
        }

        return $name;
    }

    /**
     * Validate and normalize a MySQL username.
     *
     * @return string The normalized username.
     *
     * @throws InvalidDatabase
     */
    public static function validateUsername(string $username): string
    {
        $username = strtolower(trim($username));
        $reserved = (array) config('panel.reserved_database_users', []);

        if ($username === '') {
            throw new InvalidDatabase(['username' => 'Username is required.']);
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $username) !== 1) {
            throw new InvalidDatabase(['username' => 'Use lowercase letters, digits and underscores, starting with a letter (max 32 characters).']);
        }

        if (in_array($username, $reserved, true)) {
            throw new InvalidDatabase(['username' => 'That username is reserved.']);
        }

        return $username;
    }

    /**
     * Validate a password, throwing on the first problem.
     *
     * @throws InvalidDatabase
     */
    public static function assertPassword(string $password): void
    {
        $errors = self::passwordErrors($password);

        if ($errors !== []) {
            throw new InvalidDatabase($errors);
        }
    }

    /**
     * Non-throwing password validation for form flows.
     *
     * @return array<string, string> Errors, empty when the password is valid.
     */
    public static function passwordErrors(string $password): array
    {
        if ($password === '') {
            return ['password' => 'Password is required.'];
        }

        if (strlen($password) < 8) {
            return ['password' => 'Password must be at least 8 characters.'];
        }

        if (strlen($password) > 128) {
            return ['password' => 'Password must be at most 128 characters.'];
        }

        if (preg_match('/^[A-Za-z0-9._@%+=!?-]+$/', $password) !== 1) {
            return ['password' => 'Use letters, digits and . _ @ % + = ! ? - only.'];
        }

        return [];
    }

    /**
     * Validate and normalize a full database specification.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, username: string, password: string}
     *
     * @throws InvalidDatabase
     */
    public static function validate(array $input): array
    {
        $errors = [];

        try {
            $name = self::validateName((string) ($input['name'] ?? ''));
        } catch (InvalidDatabase $exception) {
            $errors += $exception->errors;
            $name = '';
        }

        try {
            $username = self::validateUsername((string) ($input['username'] ?? ''));
        } catch (InvalidDatabase $exception) {
            $errors += $exception->errors;
            $username = '';
        }

        $password = (string) ($input['password'] ?? '');
        $errors += self::passwordErrors($password);

        if ($errors !== []) {
            throw new InvalidDatabase($errors);
        }

        return [
            'name' => $name,
            'username' => $username,
            'password' => $password,
        ];
    }
}
