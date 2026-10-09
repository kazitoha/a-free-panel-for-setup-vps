<?php

namespace App\Support;

/**
 * Executes SQL over the local MySQL socket as root, authenticating with the
 * MYSQL_ROOT_PASSWORD from .env via the MYSQL_PWD environment variable so the
 * secret never appears in argv. Only ever invoked from panel:* commands
 * running as root — the web user has no MySQL access of its own.
 */
class MySql
{
    /**
     * Run a SQL statement non-interactively.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function query(HostCommand $command, string $sql, float $timeout = 30.0): array
    {
        return $command->run(
            ['mysql', '--protocol=socket', '-uroot', '-N', '-B', '-e', $sql],
            $timeout,
            ['MYSQL_PWD' => (string) config('panel.mysql.root_password', '')],
        );
    }

    /**
     * Quote an identifier that has already passed DatabaseSpec validation.
     */
    public static function identifier(string $name): string
    {
        return '`'.str_replace('`', '', $name).'`';
    }

    /**
     * Quote a string literal. Only valid for metacharacter-free values
     * (DatabaseSpec-constrained passwords).
     */
    public static function literal(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * True when the given error output looks like "server not running /
     * socket missing" rather than a SQL problem.
     */
    public static function unavailable(array $result): bool
    {
        $combined = strtolower($result['stderr'].$result['stdout']);

        return str_contains($combined, "can't connect")
            || str_contains($combined, 'cannot connect')
            || str_contains($combined, 'connection refused')
            || str_contains($combined, 'access denied')
            || str_contains($combined, 'unable to connect');
    }
}
