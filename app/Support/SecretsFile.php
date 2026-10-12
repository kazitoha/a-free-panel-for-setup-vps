<?php

namespace App\Support;

/**
 * /root/.panel-secrets — the mode-600 key/value file shared by the installer
 * and the root-side install commands (MySQL root password, PowerDNS
 * credentials, the administrator login).
 */
class SecretsFile
{
    public static function path(): string
    {
        $path = config('panel.secrets_file');

        return is_string($path) ? $path : '';
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $path = self::path();

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $secrets = [];

        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $secrets[trim($key)] = $value;
        }

        return $secrets;
    }

    public static function get(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Set one key, keeping every other entry (and the file's mode).
     */
    public static function set(string $key, string $value): bool
    {
        $path = self::path();

        if ($path === '') {
            return false;
        }

        $contents = is_file($path) ? (string) @file_get_contents($path) : '';
        $line = $key.'='.$value;

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

        if (@file_put_contents($path, ltrim($contents)) === false) {
            return false;
        }

        @chmod($path, 0600);

        return true;
    }
}
