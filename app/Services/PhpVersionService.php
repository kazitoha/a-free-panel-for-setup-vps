<?php

namespace App\Services;

use App\Support\HostCommand;

/**
 * Which PHP versions the host can offer: the ones already installed (the
 * same directories the Sites and Projects pickers read) plus the ones the
 * configured apt repositories would let us install from the PHP module page.
 */
class PhpVersionService
{
    /**
     * Offered when apt cannot answer — the versions the Ondrej PPA ships for
     * current Ubuntu releases.
     *
     * @var list<string>
     */
    public const array CANDIDATES = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    public function __construct(
        private readonly SystemService $system,
        private readonly HostCommand $command,
    ) {}

    /**
     * Versions installed on this host, newest last.
     *
     * @return list<string>
     */
    public function installed(): array
    {
        return $this->system->phpVersions();
    }

    /**
     * Versions apt can install right now that are not installed yet.
     *
     * @return list<string>
     */
    public function installable(): array
    {
        $available = $this->availableFromApt();

        if ($available === []) {
            $available = self::CANDIDATES;
        }

        $missing = array_values(array_diff($available, $this->installed()));
        sort($missing, SORT_NATURAL);

        return $missing;
    }

    /**
     * A version may be removed only when it is installed and is not the
     * PHP the panel itself runs on.
     */
    public function removable(string $version): bool
    {
        return in_array($version, $this->installed(), true)
            && $version !== (string) config('panel.php');
    }

    /**
     * phpX.Y-fpm package names the configured repositories actually have.
     *
     * @return list<string>
     */
    private function availableFromApt(): array
    {
        $result = $this->command->run(['apt-cache', 'pkgnames', 'php'], 20.0);

        if ($result['exit'] !== 0) {
            return [];
        }

        preg_match_all('/^php(\d+\.\d+)-fpm$/m', $result['stdout'], $matches);

        $versions = array_values(array_unique($matches[1]));
        sort($versions, SORT_NATURAL);

        return $versions;
    }
}
