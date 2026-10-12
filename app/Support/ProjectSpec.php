<?php

namespace App\Support;

use App\Exceptions\InvalidProject;
use App\Exceptions\InvalidSiteSpec;

/**
 * Validates project specifications for the panel.
 *
 * Names and branches are restricted to strict character sets (they become
 * file-system paths and git arguments), repository URLs to a scheme-prefixed
 * metacharacter-free set. Domain, PHP version and document root reuse
 * SiteSpec — the same security boundary that guards every Caddy site.
 */
class ProjectSpec
{
    /**
     * Validate and normalize a project name.
     *
     * @return string The normalized name.
     *
     * @throws InvalidProject
     */
    public static function validateName(string $name): string
    {
        $name = strtolower(trim($name));

        if ($name === '') {
            throw new InvalidProject(['name' => 'Project name is required.']);
        }

        if (preg_match('/^[a-z][a-z0-9-]{0,49}$/', $name) !== 1) {
            throw new InvalidProject(['name' => 'Use lowercase letters, digits and hyphens, starting with a letter (max 50 characters).']);
        }

        $reserved = array_merge((array) config('panel.reserved_sites'), ['phpmyadmin', 'stalwart']);

        if (in_array($name, $reserved, true)) {
            throw new InvalidProject(['name' => 'That project name is reserved by the panel.']);
        }

        return $name;
    }

    /**
     * Absolute path for a validated project name.
     */
    public static function pathFor(string $name): string
    {
        return rtrim((string) config('panel.www_dir', '/var/www'), '/').'/'.self::validateName($name);
    }

    /**
     * Validate and normalize a full project specification.
     *
     * @param  array<string, mixed>  $input  name, repo_url, branch, domain, php_version
     * @return array{name: string, repo_url: string, branch: string, domain: string, php_version: string, path: string}
     *
     * @throws InvalidProject
     */
    public static function validate(array $input): array
    {
        $name = self::validateName((string) ($input['name'] ?? ''));
        $path = self::pathFor($name);

        $errors = [];

        // --- repository URL ------------------------------------------------
        $repo = trim((string) ($input['repo_url'] ?? ''));

        if ($repo === '') {
            $errors['repo_url'] = 'Repository URL is required.';
        } elseif (strlen($repo) > 300) {
            $errors['repo_url'] = 'Repository URL is too long.';
        } elseif (preg_match('#^(https?://|git@|ssh://)[A-Za-z0-9][A-Za-z0-9._:/@%+~?&=-]*$#', $repo) !== 1) {
            $errors['repo_url'] = 'Enter a git URL like https://github.com/user/repo.git or git@github.com:user/repo.git.';
        } elseif (($hostError = self::repoHostError($repo)) !== null) {
            // The clone runs as root, so a URL pointing at an internal service
            // (localhost, private ranges, cloud metadata) would be an SSRF.
            $errors['repo_url'] = $hostError;
        }

        // --- branch ---------------------------------------------------------
        $branch = trim((string) ($input['branch'] ?? ''));

        if ($branch === '') {
            $branch = 'main';
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $branch) !== 1 || str_contains($branch, '..')) {
            $errors['branch'] = 'Branch looks invalid.';
        }

        // --- domain, PHP version, document root (SiteSpec boundary) ---------
        try {
            $site = SiteSpec::validate([
                'domain' => (string) ($input['domain'] ?? ''),
                'wildcard' => false,
                'type' => 'php',
                'php_version' => (string) ($input['php_version'] ?? ''),
                'docroot' => $path,
            ], (string) config('panel.www_dir', '/var/www'));
        } catch (InvalidSiteSpec $exception) {
            foreach ($exception->errors as $key => $message) {
                if ($key === 'docroot') {
                    continue; // derived from the (already valid) name
                }

                $errors[$key] = $message;
            }

            $site = null;
        }

        if ($errors !== []) {
            throw new InvalidProject($errors);
        }

        assert($site !== null);

        return [
            'name' => $name,
            'repo_url' => $repo,
            'branch' => $branch,
            'domain' => $site['domain'],
            'php_version' => (string) $site['php_version'],
            'path' => $path,
        ];
    }

    /**
     * Why this repository URL must not be contacted, or null when it is safe.
     */
    private static function repoHostError(string $repo): ?string
    {
        $host = self::repoHost($repo);

        if ($host === null) {
            return 'Repository URL could not be parsed — use a host name, not an IP literal.';
        }

        if (self::isPrivateHost($host)) {
            return 'Repository host looks like a private, loopback or reserved address — use a public host name.';
        }

        return null;
    }

    /**
     * Extract the host from a git URL (https://, ssh:// or scp-style user@host:path).
     */
    private static function repoHost(string $repo): ?string
    {
        if (preg_match('#^(?:https?|ssh)://(?:[^/@]*@)?(\[[^\]]+\]|[^/:?#]+)#i', $repo, $matches) === 1) {
            $host = $matches[1];

            return str_starts_with($host, '[') ? substr($host, 1, -1) : $host;
        }

        if (preg_match('#^[^@/:]+@([^/:?#]+)#', $repo, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * True for loopback, private, link-local, cloud-metadata and reserved hosts.
     */
    private static function isPrivateHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '') {
            return true;
        }

        // Host names that only ever resolve inside a private network.
        $blockedNames = ['localhost', 'localhost.localdomain'];

        foreach ($blockedNames as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return true;
            }
        }

        if (str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')
            || str_ends_with($host, '.localdomain')) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip === false) {
            return false; // A host name — DNS resolves it at clone time, not here.
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }

        // Ranges PHP's flags do not cover (CGNAT, TEST-NET, benchmarking).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = (int) sprintf('%u', ip2long($ip));

            foreach ([[0x64400000, 10], [0xC0000000, 24], [0xC6120000, 15]] as [$net, $bits]) {
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

                if (($long & $mask) === $net) {
                    return true;
                }
            }
        }

        return false;
    }
}
