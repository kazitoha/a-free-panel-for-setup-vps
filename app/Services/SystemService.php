<?php

namespace App\Services;

use App\Support\HostCommand;
use App\Support\ServiceUnits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Collects a read-only snapshot of the host for the dashboard.
 *
 * Everything here runs without root privileges: systemd unit states,
 * /proc files, disk functions and a cached public-IP lookup.
 */
class SystemService
{
    public function __construct(private readonly HostCommand $command) {}

    /**
     * @return array{
     *     hostname: string,
     *     os: string,
     *     services: list<array{name: string, unit: string, state: string}>,
     *     load: array{load1: float, load5: float, load15: float, cores: int, percent: int},
     *     memory: array{total_mb: int, used_mb: int, percent: int},
     *     disk: array{total_gb: float, used_gb: float, percent: int},
     *     uptime_seconds: int,
     *     public_ip: ?string,
     *     php_versions: list<string>,
     *     listening_ports: list<string>,
     * }
     */
    public function snapshot(): array
    {
        return [
            'hostname' => gethostname() ?: 'unknown',
            'os' => self::parseOsRelease($this->readFile('/etc/os-release')),
            'services' => $this->serviceStates(),
            'load' => $this->load(),
            'memory' => self::parseMeminfo($this->readFile('/proc/meminfo')),
            'disk' => $this->disk(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'public_ip' => $this->publicIp(),
            'php_versions' => $this->phpVersions(),
            'listening_ports' => self::parseListeningPorts($this->command->run(['ss', '-lntH'])['stdout']),
        ];
    }

    /**
     * @return list<array{name: string, unit: string, state: string}>
     */
    public function serviceStates(): array
    {
        $candidates = array_merge(ServiceUnits::candidates(), $this->phpFpmCandidates());
        $installed = $this->installedUnits();

        $visible = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $unit = $candidate['unit'];

            if (isset($seen[$unit])) {
                continue;
            }

            // The installer always deploys Caddy, so it is always reported;
            // everything else only appears when actually installed.
            if ($installed !== null && ! in_array($unit, $installed, true) && $unit !== 'caddy') {
                continue;
            }

            $seen[$unit] = true;
            $visible[] = $candidate;
        }

        $states = $this->unitStates(array_keys($seen));

        $reported = [];
        foreach ($visible as $candidate) {
            $reported[] = [
                'name' => $candidate['name'],
                'unit' => $candidate['unit'],
                'state' => $states[$candidate['unit']] ?? 'unknown',
            ];
        }

        return $reported;
    }

    /**
     * Unit names installed on this host, or null when systemd metadata
     * cannot be read (then nothing is filtered out).
     *
     * @return list<string>|null
     */
    public function installedUnits(): ?array
    {
        $result = $this->command->run([
            'systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager',
        ]);

        if ($result['exit'] !== 0 || trim($result['stdout']) === '') {
            return null;
        }

        $units = [];
        foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            $name = $columns[0] ?? '';

            if (str_ends_with($name, '.service')) {
                $units[] = substr($name, 0, -strlen('.service'));
            }
        }

        return $units;
    }

    /**
     * Ask systemd about several units in a single call.
     *
     * @param  list<string>  $units
     * @return array<string, string>
     */
    public function unitStates(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $result = $this->command->run(['systemctl', 'is-active', ...$units]);

        // `systemctl is-active a b c` prints one state per line, in order.
        $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];
        $states = [];

        foreach ($lines as $index => $line) {
            if (isset($units[$index])) {
                $states[$units[$index]] = trim($line);
            }
        }

        // Older systemctl builds ignore multi-unit queries; fall back per unit.
        if (count($states) !== count($units)) {
            foreach ($units as $unit) {
                $single = $this->command->run(['systemctl', 'is-active', $unit]);
                $states[$unit] = trim($single['stdout']) !== '' ? trim($single['stdout']) : 'unknown';
            }
        }

        return $states;
    }

    /**
     * @return array{load1: float, load5: float, load15: float, cores: int, percent: int}
     */
    public function load(): array
    {
        $load = self::parseLoadavg($this->readFile('/proc/loadavg'));
        $cores = $this->coreCount();

        return $load + [
            'cores' => $cores,
            'percent' => $cores > 0 ? (int) min(100, round($load['load1'] / $cores * 100)) : 0,
        ];
    }

    /**
     * @return array{total_gb: float, used_gb: float, percent: int}
     */
    public function disk(): array
    {
        $total = @disk_total_space('/') ?: 0;
        $free = @disk_free_space('/') ?: 0;
        $used = max(0, $total - $free);

        return [
            'total_gb' => $total > 0 ? round($total / 1073741824, 1) : 0.0,
            'used_gb' => $used > 0 ? round($used / 1073741824, 1) : 0.0,
            'percent' => $total > 0 ? (int) round($used / $total * 100) : 0,
        ];
    }

    public function uptimeSeconds(): int
    {
        $uptime = self::parseUptime($this->readFile('/proc/uptime'));

        return $uptime['seconds'];
    }

    public function publicIp(): ?string
    {
        return Cache::remember('panel.public_ip', now()->addMinutes(5), function () {
            try {
                $body = Http::timeout(5)
                    ->retry(1, 200, throw: false)
                    ->get('https://api.ipify.org')
                    ->throw()
                    ->body();

                $ip = trim($body);

                return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * @return list<string>
     */
    public function phpVersions(): array
    {
        $versions = [];
        $base = '/etc/php';

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $entry) {
                if (preg_match('/^\d+\.\d+$/', $entry) === 1 && is_dir($base.'/'.$entry)) {
                    $versions[] = $entry;
                }
            }
        }

        sort($versions, SORT_NATURAL);

        return $versions;
    }

    /**
     * @return array{seconds: int, days: int, hours: int, minutes: int}
     */
    public static function parseUptime(string $procUptime): array
    {
        $seconds = (int) (float) (explode(' ', trim($procUptime))[0] ?? 0);

        return [
            'seconds' => $seconds,
            'days' => intdiv($seconds, 86400),
            'hours' => intdiv($seconds % 86400, 3600),
            'minutes' => intdiv($seconds % 3600, 60),
        ];
    }

    /**
     * @return array{load1: float, load5: float, load15: float}
     */
    public static function parseLoadavg(string $procLoadavg): array
    {
        $parts = preg_split('/\s+/', trim($procLoadavg)) ?: [];

        return [
            'load1' => (float) ($parts[0] ?? 0),
            'load5' => (float) ($parts[1] ?? 0),
            'load15' => (float) ($parts[2] ?? 0),
        ];
    }

    /**
     * @return array{total_mb: int, used_mb: int, percent: int}
     */
    public static function parseMeminfo(string $procMeminfo): array
    {
        $values = [];

        foreach (preg_split('/\R/', $procMeminfo) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        $totalKb = $values['MemTotal'] ?? 0;
        $availableKb = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $usedKb = max(0, $totalKb - $availableKb);

        return [
            'total_mb' => (int) round($totalKb / 1024),
            'used_mb' => (int) round($usedKb / 1024),
            'percent' => $totalKb > 0 ? (int) round($usedKb / $totalKb * 100) : 0,
        ];
    }

    /**
     * @return list<string> unique listening TCP ports, numerically sorted
     */
    public static function parseListeningPorts(string $ssOutput): array
    {
        $ports = [];

        foreach (preg_split('/\R/', trim($ssOutput)) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            $local = $columns[3] ?? '';

            if ($local === '' || ! str_contains($local, ':')) {
                continue;
            }

            $port = substr($local, (int) strrpos($local, ':') + 1);

            if (ctype_digit($port)) {
                $ports[(int) $port] = true;
            }
        }

        $ports = array_keys($ports);
        sort($ports);

        return array_map(strval(...), $ports);
    }

    public static function parseOsRelease(string $osRelease): string
    {
        foreach (preg_split('/\R/', $osRelease) ?: [] as $line) {
            if (str_starts_with($line, 'PRETTY_NAME=')) {
                return trim(trim(substr($line, strlen('PRETTY_NAME='))), '"');
            }
        }

        return 'Linux';
    }

    private function coreCount(): int
    {
        $cpuinfo = $this->readFile('/proc/cpuinfo');
        $count = preg_match_all('/^processor/m', $cpuinfo);

        if ($count > 0) {
            return $count;
        }

        $nproc = trim($this->command->run(['nproc'])['stdout']);

        return ctype_digit($nproc) ? max(1, (int) $nproc) : 1;
    }

    /**
     * @return list<array{unit: string, name: string}>
     */
    private function phpFpmCandidates(): array
    {
        $candidates = [];
        $base = '/etc/php';

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $version) {
                if (preg_match('/^\d+\.\d+$/', $version) === 1) {
                    $candidates[] = [
                        'unit' => "php{$version}-fpm",
                        'name' => "PHP {$version}-fpm",
                    ];
                }
            }
        }

        return $candidates;
    }

    private function readFile(string $path): string
    {
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }
}
