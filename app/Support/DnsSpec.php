<?php

namespace App\Support;

use App\Exceptions\InvalidDnsRecord;

/**
 * Validates and normalizes DNS input for the PowerDNS HTTP API.
 *
 * The API itself performs final validation; this layer exists so the panel
 * form can reject garbage with friendly messages before any HTTP call, and
 * so names/content arrive in the exact shape PowerDNS expects (trailing-dot
 * absolute names, "prio host" MX content, quoted CAA values, …).
 */
class DnsSpec
{
    /**
     * @var list<string>
     */
    public const array TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SRV', 'CAA'];

    /**
     * Validate a zone (domain) name. Returns the display form (no trailing
     * dot, lowercase).
     *
     * @throws InvalidDnsRecord
     */
    public static function zoneName(string $input): string
    {
        $name = strtolower(trim($input));
        $name = rtrim($name, '.');

        $error = self::zoneNameError($name);

        if ($error !== null) {
            throw new InvalidDnsRecord(['domain' => $error]);
        }

        return $name;
    }

    public static function zoneNameError(string $name): ?string
    {
        if ($name === '') {
            return 'Domain is required.';
        }

        if (strlen($name) > 253) {
            return 'Domain is too long.';
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $name) !== 1) {
            return 'Use a full domain like example.com (letters, digits, dots, hyphens).';
        }

        if (str_contains($name, '..')) {
            return 'Domain cannot contain consecutive dots.';
        }

        return null;
    }

    /**
     * Validate and normalize one record for a zone. Returns API-shaped data:
     * absolute names with trailing dot, ready-to-use content, integer ttl.
     *
     * @param  string  $zone  display form, e.g. example.com
     * @param  array{name: mixed, type: mixed, content: mixed, ttl: mixed}  $input
     * @return array{name: string, type: string, content: string, ttl: int}
     *
     * @throws InvalidDnsRecord
     */
    public static function record(string $zone, array $input): array
    {
        $errors = [];

        $type = strtoupper(trim((string) ($input['type'] ?? '')));

        if (! in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Choose one of: '.implode(', ', self::TYPES).'.';
        }

        $name = self::recordName($zone, (string) ($input['name'] ?? ''));
        if ($name === null) {
            $errors['name'] = "Name must be inside {$zone}: use @, www, *.sub or a full name.";
        }

        $ttlRaw = (string) ($input['ttl'] ?? '3600');
        $ttl = filter_var($ttlRaw, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 60 || $ttl > 604800) {
            $errors['ttl'] = 'TTL must be a number of seconds between 60 and 604800.';
            $ttl = 3600;
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            $errors['content'] = 'Content is required.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $content) === 1) {
            $errors['content'] = 'Content cannot contain line breaks or control characters.';
        } elseif (in_array($type, self::TYPES, true)) {
            $normalized = self::normalizeContent($type, $content, $contentError);

            if ($normalized === null) {
                $errors['content'] = $contentError ?? "Invalid {$type} content.";
            }

            $content = $normalized ?? $content;
        }

        if ($errors !== []) {
            throw new InvalidDnsRecord($errors);
        }

        return [
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl,
        ];
    }

    /**
     * Normalize a record name input to an absolute API name (trailing dot).
     * Returns null only when a label is invalid. Names are always kept
     * inside the zone: already-absolute names under the zone are kept as-is,
     * everything else is treated as relative (sub)labels.
     *
     * Accepts: @ · www · *.sub · _sip._tcp · mail · example.com · www.example.com.
     */
    public static function recordName(string $zone, string $input): ?string
    {
        $name = strtolower(trim($input));

        if ($name === '' || $name === '@') {
            return $zone.'.';
        }

        if ($name === '*') {
            return '*.'.$zone.'.';
        }

        $name = rtrim($name, '.');

        if ($name === $zone) {
            return $zone.'.';
        }

        $candidate = str_ends_with($name, '.'.$zone) || str_ends_with($name, $zone.'')
            ? $name
            : $name.'.'.$zone;

        if (strlen($candidate) > 253) {
            return null;
        }

        $labels = explode('.', $candidate);

        foreach ($labels as $label) {
            if (preg_match('/^(\*\.)?[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?$/', $label) !== 1) {
                return null;
            }
        }

        return $candidate.'.';
    }

    /**
     * Per-type content normalization. Returns the API-ready content or null
     * (with $error set) when the value is invalid for the type.
     */
    private static function normalizeContent(string $type, string $content, ?string &$error = null): ?string
    {
        $error = null;

        return match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: self::fail($error, 'A content must be an IPv4 address like 203.0.113.10.'),
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: self::fail($error, 'AAAA content must be an IPv6 address.'),
            'CNAME', 'NS' => self::host($content) ? rtrim(self::host($content), '.').'.' : self::fail($error, "{$type} content must be a hostname like ns1.example.com."),
            'MX' => self::mx($content, $error),
            'SRV' => self::srv($content, $error),
            'CAA' => self::caa($content, $error),
            'TXT' => strlen($content) <= 1024 ? $content : self::fail($error, 'TXT content is too long (max 1024 characters).'),
            default => null,
        };
    }

    private static function fail(?string &$error, string $message): null
    {
        $error = $message;

        return null;
    }

    /**
     * MX content: "10 mail.example.com" → "10 mail.example.com."
     */
    private static function mx(string $content, ?string &$error): ?string
    {
        if (preg_match('/^(\d{1,5})\s+(\S+)$/', $content, $matches) !== 1) {
            return self::fail($error, 'MX content must look like: 10 mail.example.com');
        }

        if ((int) $matches[1] > 65535) {
            return self::fail($error, 'MX priority must be between 0 and 65535.');
        }

        $host = self::host($matches[2]);

        if ($host === null) {
            return self::fail($error, 'MX target must be a hostname like mail.example.com.');
        }

        return $matches[1].' '.rtrim($host, '.').'.';
    }

    /**
     * SRV content: "10 60 5060 sip.example.com" → trailing-dot target.
     */
    private static function srv(string $content, ?string &$error): ?string
    {
        if (preg_match('/^(\d{1,5})\s+(\d{1,5})\s+(\d{1,5})\s+(\S+)$/', $content, $matches) !== 1) {
            return self::fail($error, 'SRV content must look like: 10 60 5060 sip.example.com');
        }

        [$prio, $weight, $port] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];

        if ($prio > 65535 || $weight > 65535) {
            return self::fail($error, 'SRV priority and weight must be between 0 and 65535.');
        }

        if ($port < 1 || $port > 65535) {
            return self::fail($error, 'SRV port must be between 1 and 65535.');
        }

        $host = self::host($matches[4]);

        if ($host === null) {
            return self::fail($error, 'SRV target must be a hostname.');
        }

        return "{$prio} {$weight} {$port} ".rtrim($host, '.').'.';
    }

    /**
     * CAA content: '0 issue "letsencrypt.org"' — value auto-quoted.
     */
    private static function caa(string $content, ?string &$error): ?string
    {
        if (preg_match('/^(\d{1,3})\s+([a-z]+)\s+(.+)$/i', $content, $matches) !== 1) {
            return self::fail($error, 'CAA content must look like: 0 issue "letsencrypt.org"');
        }

        if ((int) $matches[1] > 255) {
            return self::fail($error, 'CAA flags must be between 0 and 255.');
        }

        $tag = strtolower($matches[2]);

        if (! in_array($tag, ['issue', 'issuewild', 'iodef', 'contact'], true)) {
            return self::fail($error, 'CAA tag must be issue, issuewild, iodef or contact.');
        }

        $value = trim($matches[3]);

        if (str_starts_with($value, '"')) {
            if (! str_ends_with($value, '"') || strlen($value) < 2) {
                return self::fail($error, 'CAA value quotes are unbalanced.');
            }

            return "{$matches[1]} {$tag} {$value}";
        }

        if (preg_match('/\s/', $value) === 1) {
            return self::fail($error, 'CAA value must be quoted when it contains spaces.');
        }

        return "{$matches[1]} {$tag} \"{$value}\"";
    }

    /**
     * A hostname for CNAME/NS/MX/SRV targets (trailing dot optional).
     */
    private static function host(string $value): ?string
    {
        $value = rtrim(trim($value), '.');

        if ($value === '' || strlen($value) > 253) {
            return null;
        }

        foreach (explode('.', $value) as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Build SOA content for a freshly created zone.
     */
    public static function soaContent(string $mname, string $rname, int $serial): string
    {
        return sprintf('%s. %s. %d 10800 3600 604800 3600', rtrim($mname, '.'), rtrim($rname, '.'), $serial);
    }
}
