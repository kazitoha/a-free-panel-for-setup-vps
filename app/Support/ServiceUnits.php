<?php

namespace App\Support;

/**
 * Allowlist of systemd units the panel is allowed to manage.
 *
 * Every unit that reaches systemctl must match one of these patterns;
 * user input is never interpolated into a shell command.
 */
class ServiceUnits
{
    /**
     * @var list<string>
     */
    public const array PATTERNS = [
        '/^caddy$/',
        '/^mysql$/',
        '/^mariadb$/',
        '/^pdns$/',
        '/^stalwart(-mail)?$/',
        '/^php\d+\.\d+-fpm$/',
        '/^redis-server$/',
        '/^ufw$/',
    ];

    public static function isValid(string $unit): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $unit) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The units the dashboard always reports on, in display order.
     * Units that are not installed on this host are filtered out later.
     *
     * @return list<array{unit: string, name: string}>
     */
    public static function candidates(): array
    {
        return [
            ['unit' => 'caddy', 'name' => 'Caddy'],
            ['unit' => 'mysql', 'name' => 'MySQL'],
            ['unit' => 'mariadb', 'name' => 'MariaDB'],
            ['unit' => 'pdns', 'name' => 'PowerDNS'],
            ['unit' => 'stalwart', 'name' => 'Stalwart Mail'],
            ['unit' => 'stalwart-mail', 'name' => 'Stalwart Mail'],
        ];
    }
}
