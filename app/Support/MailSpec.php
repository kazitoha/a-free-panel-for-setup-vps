<?php

namespace App\Support;

use App\Exceptions\InvalidMailRecord;

/**
 * Validates and normalizes mail input for the Stalwart JMAP API.
 *
 * Domain names reuse DnsSpec so the mail module and the DNS module agree on
 * what a valid zone looks like; local parts and passwords are checked here so
 * the form can reject garbage before any HTTP call. Final validation still
 * belongs to Stalwart — server-side policy errors surface on the form.
 */
class MailSpec
{
    /**
     * Validate a mail domain. Returns the display form (lowercase, no
     * trailing dot), identical to the DNS zone name it will live in.
     *
     * @throws InvalidMailRecord
     */
    public static function domain(string $input): string
    {
        $name = strtolower(trim($input));
        $name = rtrim($name, '.');

        $error = DnsSpec::zoneNameError($name);

        if ($error !== null) {
            throw new InvalidMailRecord(['domain' => $error]);
        }

        return $name;
    }

    /**
     * Validate and normalize a mailbox local part (the part before @).
     *
     * @throws InvalidMailRecord
     */
    public static function localPart(string $input): string
    {
        $name = strtolower(trim($input));

        if ($name === '') {
            throw new InvalidMailRecord(['name' => 'Mailbox name is required.']);
        }

        if (strlen($name) > 64) {
            throw new InvalidMailRecord(['name' => 'Mailbox name is too long (max 64 characters).']);
        }

        if (preg_match('/^[a-z0-9]([a-z0-9._+-]*[a-z0-9])?$/', $name) !== 1) {
            throw new InvalidMailRecord(['name' => 'Use letters, digits, dots, underscores, plus or hyphens — starting and ending with a letter or digit.']);
        }

        if (str_contains($name, '..')) {
            throw new InvalidMailRecord(['name' => 'Mailbox name cannot contain consecutive dots.']);
        }

        return $name;
    }

    /**
     * Validate a mailbox password. Stalwart applies its own policy on top —
     * stricter server-side rules surface as StalwartError on the form.
     *
     * @throws InvalidMailRecord
     */
    public static function password(string $input): string
    {
        if (strlen($input) < 8) {
            throw new InvalidMailRecord(['password' => 'Password must be at least 8 characters.']);
        }

        if (strlen($input) > 256) {
            throw new InvalidMailRecord(['password' => 'Password is too long (max 256 characters).']);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $input) === 1) {
            throw new InvalidMailRecord(['password' => 'Password cannot contain control characters.']);
        }

        return $input;
    }

    /**
     * Validate the optional mailbox description.
     */
    public static function description(string $input): string
    {
        $description = trim($input);

        if (mb_strlen($description) > 200) {
            throw new InvalidMailRecord(['description' => 'Description is too long (max 200 characters).']);
        }

        return $description;
    }
}
