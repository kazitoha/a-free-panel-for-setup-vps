<?php

namespace App\Support;

/**
 * Reads and rewrites single keys of the panel's .env.
 *
 * The file is edited in place — never replaced — so its root:www-data
 * ownership and mode survive. Only ever called from panel:* commands running
 * as root: the web user has no business writing .env.
 */
class EnvFile
{
    public static function path(): string
    {
        $path = config('panel.setup.env_file');

        return is_string($path) ? $path : '';
    }

    /**
     * Current value of a key, with the quoting phpdotenv would apply removed.
     * Returns null when the key is absent.
     */
    public static function value(string $key): ?string
    {
        $path = self::path();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $match) !== 1) {
            return null;
        }

        return self::unquote(trim($match[1]));
    }

    /**
     * Set (or append) one key, leaving every other line untouched.
     */
    public static function set(string $key, string $value): bool
    {
        $path = self::path();

        if ($path === '') {
            return false;
        }

        if (! is_file($path)) {
            return @file_put_contents($path, $key.'='.self::quote($value)."\n") !== false;
        }

        $contents = (string) @file_get_contents($path);
        $line = $key.'='.self::quote($value);

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace_callback(
                '/^'.preg_quote($key, '/').'=.*$/m',
                fn (): string => $line,
                $contents,
                1,
            );
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        $temporary = $path.'.setup';

        if (@file_put_contents($temporary, $contents) === false) {
            return false;
        }

        $copied = @copy($temporary, $path);
        @unlink($temporary);

        return $copied;
    }

    /**
     * Quote a value for .env: single quotes pass through the parser untouched,
     * values holding one fall back to escaped double quotes.
     */
    private static function quote(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return (string) preg_replace('/\\\\(["\\\\$])/', '$1', substr($value, 1, -1));
        }

        return $value;
    }
}
