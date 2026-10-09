<?php

namespace App\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around Symfony Process so every part of the panel that needs to
 * execute a host command shares one seam that tests can fake.
 */
class HostCommand
{
    /**
     * Run a command and capture its result without throwing on failure.
     *
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env  Extra environment variables merged into the process.
     * @param  string|null  $cwd  Working directory for the process.
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function run(array $command, float $timeout = 60.0, ?array $env = null, ?string $cwd = null): array
    {
        try {
            $process = new Process($command, $cwd, $env);
            $process->setTimeout($timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return [
                    'exit' => 124,
                    'stdout' => $process->getOutput(),
                    'stderr' => trim($process->getErrorOutput()."\nProcess timed out after {$timeout}s."),
                ];
            }

            return [
                'exit' => $process->getExitCode() ?? -1,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ];
        } catch (\Throwable $exception) {
            // Missing binary, proc_open restrictions, etc.
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
            ];
        }
    }
}
