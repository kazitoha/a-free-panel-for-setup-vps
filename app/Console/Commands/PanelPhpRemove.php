<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side: remove one PHP version from the host. The panel's own PHP is
 * refused by the caller and again here, so the panel can never remove itself.
 */
#[Signature('panel:php:remove {--php= : PHP version to remove (for example 8.2)}')]
#[Description('Remove a PHP version and its FPM service from the host')]
class PanelPhpRemove extends Command
{
    protected $hidden = true;

    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $version = (string) $this->option('php');

        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            $this->error("Invalid PHP version: {$version}");

            return self::FAILURE;
        }

        if ($version === (string) config('panel.php')) {
            $this->error("PHP {$version} runs the panel itself and cannot be removed.");

            return self::FAILURE;
        }

        if (! is_dir((string) config('panel.php_dir').'/'.$version)) {
            $this->line("PHP {$version} is not installed — nothing to remove.");

            return self::SUCCESS;
        }

        try {
            $this->line("Stopping php{$version}-fpm...");
            $this->command->run(['systemctl', 'disable', '--now', "php{$version}-fpm"], 120.0);

            $this->line("Removing PHP {$version}...");
            $this->mustRun(['apt-get', 'remove', '-y', ...$this->packages($version)]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("PHP {$version} removed.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function packages(string $version): array
    {
        return array_map(
            fn (string $suffix): string => "php{$version}-{$suffix}",
            ['fpm', 'cli', 'mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'intl', 'sqlite3'],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command): void
    {
        $result = $this->command->run($command, 600.0, ['DEBIAN_FRONTEND' => 'noninteractive']);

        if ($result['exit'] === 0) {
            return;
        }

        $message = 'Command failed: '.implode(' ', $command);
        $detail = $this->failureDetail($result['stdout'], $result['stderr']);

        if ($detail !== '') {
            $message .= ' — '.$detail;
        }

        throw new RuntimeException($message);
    }

    /**
     * The tail of apt's error channel is where the real reason lives. Its
     * start is often a wall of "No apport report written" notices from
     * crashed maintainer scripts, which pushes the actual error out of view.
     */
    private function failureDetail(string $stdout, string $stderr): string
    {
        $lines = $this->meaningfulLines($stderr);

        if ($lines === []) {
            $lines = $this->meaningfulLines($stdout);
        }

        return mb_substr(implode(' / ', array_slice($lines, -6)), 0, 400);
    }

    /**
     * @return list<string>
     */
    private function meaningfulLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];

        return array_values(array_filter(
            $lines,
            fn (string $line): bool => trim($line) !== ''
                && ! str_contains($line, 'apport report written'),
        ));
    }
}
