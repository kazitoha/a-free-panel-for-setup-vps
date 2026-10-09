<?php

namespace App\Services;

use App\Support\SiteSpec;
use Illuminate\Support\Facades\File;

/**
 * Reads Caddy site files and their saved specifications from locations the
 * web user can access without root (sites are world-readable; specs live in
 * panel storage).
 */
class CaddySites
{
    public function sitesDir(): string
    {
        return (string) config('panel.caddy_sites_dir');
    }

    /**
     * @return list<array{name: string, path: string, addresses: list<string>, reserved: bool, content: string}>
     */
    public function list(): array
    {
        $dir = $this->sitesDir();

        if (! is_dir($dir) || ! is_readable($dir)) {
            return [];
        }

        $reserved = config('panel.reserved_sites', []);
        $sites = [];

        foreach (glob($dir.'/*.caddy') ?: [] as $path) {
            $name = basename($path, '.caddy');
            $content = (string) @file_get_contents($path);
            $addresses = self::addressesOf($content);

            $sites[] = [
                'name' => $name,
                'path' => $path,
                'addresses' => $addresses,
                'reserved' => in_array($name, $reserved, true),
                'content' => $content,
            ];
        }

        usort($sites, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $sites;
    }

    public function read(string $name): ?string
    {
        if (! SiteSpec::isValidName($name)) {
            return null;
        }

        $path = $this->sitesDir().'/'.$name.'.caddy';

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    public function exists(string $name): bool
    {
        return SiteSpec::isValidName($name) && is_file($this->sitesDir().'/'.$name.'.caddy');
    }

    public function isReserved(string $name): bool
    {
        return in_array($name, config('panel.reserved_sites', []), true);
    }

    /**
     * Save the form specification used to re-edit a site later.
     *
     * @param  array{domain: string, wildcard: bool, type: string, php_version: ?string, docroot: ?string, target: ?string}  $spec
     */
    public function writeSpec(string $name, array $spec): string
    {
        $dir = storage_path('app/site-specs');
        File::ensureDirectoryExists($dir, 0755, true);

        $path = $dir.'/'.$name.'.json';
        file_put_contents($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readSpec(string $name): ?array
    {
        if (! SiteSpec::isValidName($name)) {
            return null;
        }

        $path = storage_path('app/site-specs/'.$name.'.json');

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function deleteSpec(string $name): void
    {
        if (SiteSpec::isValidName($name)) {
            @unlink(storage_path('app/site-specs/'.$name.'.json'));
        }
    }

    /**
     * @return list<string>
     */
    public static function addressesOf(string $content): array
    {
        if (preg_match('/^([^{]+)\{\s*$/m', trim($content), $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
    }
}
