<?php

namespace App\Services;

use App\Models\Install;
use App\Support\HostCommand;
use RuntimeException;

/**
 * Starts panel:* commands as root in the background and streams their output
 * into a per-install log file the web UI polls.
 *
 * The actual process is detached (`bash -c 'sudo -n … >> log 2>&1 &'`) so the
 * web request returns immediately and no queue worker is required.
 */
class InstallRunner
{
    public function __construct(private readonly HostCommand $command) {}

    /**
     * Spawn the root-side command for the given install and redirect its
     * output to storage/logs/installs/{id}.log.
     *
     * @throws RuntimeException when the process cannot be started
     */
    public function start(Install $install): void
    {
        $log = $this->logPath($install);
        $dir = dirname($log);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create the install log directory.');
        }

        @touch($log);

        $wrapped = sprintf(
            '%s %s %d',
            SudoRunner::WRAPPER,
            $install->command,
            $install->id,
        );

        $bash = sprintf('sudo -n %s >> %s 2>&1 &', $wrapped, escapeshellarg($log));

        $result = $this->command->run(['bash', '-c', $bash], 15.0);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Could not start the installer.'
                .($detail !== '' ? ' '.$detail : '')
            );
        }
    }

    public function logPath(Install $install): string
    {
        return storage_path('logs/installs/'.$install->id.'.log');
    }

    public function readLog(Install $install, int $maxBytes = 512_000): string
    {
        $path = $this->logPath($install);

        if (! is_file($path)) {
            return '';
        }

        $size = (int) filesize($path);

        if ($size <= $maxBytes) {
            return (string) file_get_contents($path);
        }

        // Keep the tail — the interesting part of an install log is its end.
        $handle = fopen($path, 'rb');
        fseek($handle, $size - $maxBytes);

        return (string) fread($handle, $maxBytes);
    }
}
