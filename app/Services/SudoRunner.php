<?php

namespace App\Services;

use App\Support\HostCommand;

/**
 * Executes allowlisted panel:* artisan commands as root through the
 * root-owned sudo wrapper installed at /usr/local/panel/run.
 */
class SudoRunner
{
    public const string WRAPPER = '/usr/local/panel/run';

    public function __construct(private readonly HostCommand $command) {}

    /**
     * @param  list<string>  $panelArguments  e.g. ['panel:service:restart', 'caddy']
     * @param  string|null  $input  Secret data (e.g. a password) written to the command's stdin
     *                              so it never appears in the process table or command logs.
     * @return array{exit: int, stdout: string, stderr: string, denied: bool}
     */
    public function run(array $panelArguments, float $timeout = 120.0, ?string $input = null): array
    {
        $command = ['sudo', '-n', self::WRAPPER, ...$panelArguments];

        $result = $input === null
            ? $this->command->run($command, $timeout)
            : $this->command->run($command, $timeout, null, null, $input);

        $combined = $result['stderr'].$result['stdout'];
        $result['denied'] = $result['exit'] === 77
            || str_contains($combined, 'password is required')
            || str_contains($combined, 'not allowed to execute')
            || str_contains($combined, 'a terminal is required');

        return $result;
    }

    public static function denialMessage(): string
    {
        return 'Sudo access is not configured on this host. Run install-panel.sh to install the panel runner.';
    }
}
