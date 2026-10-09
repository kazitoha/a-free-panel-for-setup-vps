<?php

namespace App\Support;

use App\Exceptions\InvalidSiteSpec;

/**
 * Validates, normalizes, builds and parses Caddy site specifications.
 *
 * This is the single source of truth for what may end up inside a Caddyfile:
 * every field is restricted to a strict character set so user input can never
 * inject directives, braces or newlines. The same validation runs twice —
 * once in the web controller (for friendly form errors) and once inside the
 * root-side `panel:caddy:*` commands (as the security boundary).
 */
class SiteSpec
{
    /**
     * @var list<string>
     */
    public const array TYPES = ['php', 'static', 'proxy'];

    /**
     * Validate and normalize a site specification.
     *
     * @param  array<string, mixed>  $input
     * @return array{domain: string, wildcard: bool, type: string, php_version: ?string, docroot: ?string, target: ?string}
     *
     * @throws InvalidSiteSpec
     */
    public static function validate(array $input): array
    {
        $errors = [];

        // --- domain -------------------------------------------------------
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));

        if ($domain === '') {
            $errors['domain'] = 'Domain is required.';
        } elseif (strlen($domain) > 253) {
            $errors['domain'] = 'Domain is too long.';
        } elseif (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) !== 1) {
            $errors['domain'] = 'Use only lowercase letters, digits, dots and hyphens.';
        } elseif (str_contains($domain, '..')) {
            $errors['domain'] = 'Domain cannot contain consecutive dots.';
        } elseif (! str_contains($domain, '.')) {
            $errors['domain'] = 'Enter a full domain, for example example.com.';
        }

        // --- type ---------------------------------------------------------
        $type = strtolower(trim((string) ($input['type'] ?? 'php')));

        if (! in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Choose one of: PHP, static, reverse proxy.';
        }

        $wildcard = filter_var($input['wildcard'] ?? false, FILTER_VALIDATE_BOOL);

        // --- php version --------------------------------------------------
        $phpVersion = trim((string) ($input['php_version'] ?? ''));

        if ($type === 'php') {
            if ($phpVersion === '') {
                $errors['php_version'] = 'Select a PHP version.';
            } elseif (preg_match('/^\d+\.\d+$/', $phpVersion) !== 1) {
                $errors['php_version'] = 'PHP version looks invalid.';
            }
        } else {
            $phpVersion = null;
        }

        // --- document root ------------------------------------------------
        $docroot = trim((string) ($input['docroot'] ?? ''));

        if (in_array($type, ['php', 'static'], true)) {
            if ($docroot === '') {
                $errors['docroot'] = 'Document root is required.';
            } elseif (strlen($docroot) > 400
                || preg_match('#^/var/www/[A-Za-z0-9._/-]+$#', $docroot) !== 1
                || str_contains($docroot, '..')) {
                $errors['docroot'] = 'Document root must live under /var/www (letters, digits, dot, underscore, hyphen and slash only).';
            }
        } else {
            $docroot = null;
        }

        // --- reverse proxy target ----------------------------------------
        $target = trim((string) ($input['target'] ?? ''));

        if ($type === 'proxy') {
            if ($target === '') {
                $errors['target'] = 'Proxy target is required, for example 127.0.0.1:8080.';
            } elseif (preg_match('/^(\[[0-9a-fA-F:]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)(:\d{1,5})?$/i', $target) !== 1) {
                $errors['target'] = 'Proxy target must be a host or host:port, for example 127.0.0.1:9000.';
            }
        } else {
            $target = null;
        }

        if ($errors !== []) {
            throw new InvalidSiteSpec($errors);
        }

        return [
            'domain' => $domain,
            'wildcard' => $wildcard,
            'type' => $type,
            'php_version' => $phpVersion,
            'docroot' => $docroot,
            'target' => $target,
        ];
    }

    /**
     * Build the Caddyfile snippet for a validated specification.
     *
     * @param  array{domain: string, wildcard: bool, type: string, php_version: ?string, docroot: ?string, target: ?string}  $spec
     */
    public static function build(array $spec): string
    {
        $addresses = [$spec['domain']];

        if ($spec['wildcard']) {
            $addresses[] = '*.'.$spec['domain'];
        }

        $lines = [implode(', ', $addresses).' {', '    # Managed by VPS Panel', '    encode zstd gzip'];

        match ($spec['type']) {
            'php' => array_push(
                $lines,
                '    root * '.$spec['docroot'],
                '    php_fastcgi unix//run/php/php'.$spec['php_version'].'-fpm.sock',
                '    file_server',
            ),
            'static' => array_push(
                $lines,
                '    root * '.$spec['docroot'],
                '    file_server',
            ),
            'proxy' => array_push(
                $lines,
                '    reverse_proxy '.$spec['target'],
            ),
        };

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Best-effort parse of an existing Caddyfile snippet back into a spec.
     * Returns null when the file does not look like something the panel built.
     *
     * @return array{domain: string, wildcard: bool, type: string, php_version: ?string, docroot: ?string, target: ?string}|null
     */
    public static function parse(string $content): ?array
    {
        if (preg_match('/^([^{]+)\{\s*$/m', trim($content), $matches) !== 1) {
            return null;
        }

        $addresses = array_values(array_filter(array_map('trim', explode(',', $matches[1]))));

        if ($addresses === []) {
            return null;
        }

        $wildcard = false;
        $domain = null;

        foreach ($addresses as $address) {
            if (str_starts_with($address, '*.')) {
                $wildcard = true;
                $domain ??= substr($address, 2);
            } else {
                $domain ??= $address;
            }
        }

        if ($domain === null || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain) !== 1) {
            return null;
        }

        $spec = [
            'domain' => strtolower($domain),
            'wildcard' => $wildcard,
            'type' => 'static',
            'php_version' => null,
            'docroot' => null,
            'target' => null,
        ];

        if (preg_match('/php_fastcgi\s+unix\/\/run\/php\/php(\d+\.\d+)-fpm\.sock/', $content, $php) === 1) {
            $spec['type'] = 'php';
            $spec['php_version'] = $php[1];
        } elseif (preg_match('/^\s*reverse_proxy\s+(\S+)/m', $content, $proxy) === 1) {
            $spec['type'] = 'proxy';
            $spec['target'] = $proxy[1];
        }

        if (preg_match('/^\s*root\s+\*\s+(\S+)/m', $content, $root) === 1) {
            $spec['docroot'] = $root[1];
        }

        if ($spec['type'] !== 'proxy' && $spec['docroot'] === null) {
            return null;
        }

        if ($spec['type'] === 'php' && $spec['php_version'] === null) {
            return null;
        }

        return $spec;
    }

    /**
     * File name (without extension) for a domain, e.g. example.com.
     */
    public static function nameFor(string $domain): string
    {
        $name = strtolower(trim($domain));

        return preg_replace('/[^a-z0-9.-]/', '', $name) ?? '';
    }

    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 253
            && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name) === 1
            && ! str_contains($name, '..');
    }
}
