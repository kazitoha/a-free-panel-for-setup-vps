<?php

namespace App\Services;

use App\Support\HostCommand;
use Closure;

/**
 * Root-side apt/dpkg operations shared by the module uninstall commands:
 * detect which of a set of packages is actually installed, purge them
 * non-interactively, and delete the directories they leave behind.
 */
class PackageManager
{
    public function __construct(private readonly HostCommand $command) {}

    /**
     * The subset of the candidates that dpkg knows about, newest first left
     * as given.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public function installed(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn (string $package): bool => $this->command->run(['dpkg', '-s', $package], 20.0)['exit'] === 0,
        ));
    }

    /**
     * Purge the given installed packages, then clean up orphaned
     * dependencies. Returns false when apt itself fails.
     *
     * @param  list<string>  $packages
     * @param  Closure(string): void  $log
     */
    public function purge(array $packages, Closure $log): bool
    {
        if ($packages === []) {
            return true;
        }

        $log('Removing packages: '.implode(' ', $packages).'...');

        $result = $this->command->run(
            ['apt-get', 'purge', '-y', '-qq', ...$packages],
            900.0,
            ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        if ($result['exit'] !== 0) {
            return false;
        }

        $this->command->run(
            ['apt-get', 'autoremove', '--purge', '-y', '-qq'],
            600.0,
            ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        return true;
    }

    /**
     * Delete the given paths, skipping the ones that do not exist.
     *
     * @param  list<string>  $paths
     * @param  Closure(string): void  $log
     */
    public function remove(array $paths, Closure $log): void
    {
        $existing = array_values(array_filter($paths, fn (string $path): bool => file_exists($path)));

        if ($existing === []) {
            return;
        }

        $log('Deleting: '.implode(' ', $existing).'...');

        $this->command->run(['rm', '-rf', '--', ...$existing], 300.0);
    }
}
