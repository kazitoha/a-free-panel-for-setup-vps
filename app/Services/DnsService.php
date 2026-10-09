<?php

namespace App\Services;

use App\Exceptions\InvalidDnsRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Support\DnsSpec;

/**
 * DNS domain logic on top of the PowerDNS HTTP API.
 *
 * All names are handled in display form (no trailing dot) at this layer and
 * converted to API form (absolute, trailing dot) at the edges. Record changes
 * rely on PowerDNS' soa_edit_api=DEFAULT to bump the SOA serial automatically;
 * legacy zones without it are normalized on first touch.
 */
class DnsService
{
    public function __construct(private readonly PowerDnsClient $client) {}

    /**
     * ok · unreachable (not installed / down) · unauthorized (bad API key).
     */
    public function status(): string
    {
        try {
            $this->client->server();

            return 'ok';
        } catch (PowerDnsUnavailable) {
            return 'unreachable';
        } catch (PowerDnsError) {
            return 'unauthorized';
        }
    }

    /**
     * @return list<array{name: string, kind: string, serial: int, protected: bool}>
     */
    public function zones(): array
    {
        $zones = [];

        foreach ($this->client->zones() as $zone) {
            $name = $this->display($zone['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $zones[] = [
                'name' => $name,
                'kind' => (string) ($zone['kind'] ?? 'Native'),
                'serial' => (int) ($zone['serial'] ?? 0),
                'protected' => $this->isProtected($name),
            ];
        }

        usort($zones, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $zones;
    }

    public function zoneExists(string $zone): bool
    {
        return $this->client->zones($this->api($zone)) !== [];
    }

    /**
     * Zone detail with flattened record rows for the UI.
     *
     * @return array{name: string, kind: string, serial: int, protected: bool, records: list<array{name: string, type: string, content: string, ttl: int, editable: bool}>}
     */
    public function zone(string $zone): array
    {
        $raw = $this->client->zone($this->api($zone));

        $records = [];

        foreach ($raw['rrsets'] ?? [] as $rrset) {
            foreach ($rrset['records'] ?? [] as $record) {
                if (! empty($record['disabled'])) {
                    continue;
                }

                $records[] = [
                    'name' => $this->relative($rrset['name'] ?? '', $zone),
                    'type' => (string) ($rrset['type'] ?? ''),
                    'content' => (string) ($record['content'] ?? ''),
                    'ttl' => (int) ($rrset['ttl'] ?? 3600),
                    'editable' => ($rrset['type'] ?? '') !== 'SOA',
                ];
            }
        }

        usort($records, fn (array $a, array $b): int => [$a['name'], $a['type'], $a['content']]
            <=> [$b['name'], $b['type'], $b['content']]);

        return [
            'name' => $this->display($raw['name'] ?? $zone),
            'kind' => (string) ($raw['kind'] ?? 'Native'),
            'serial' => (int) ($raw['serial'] ?? 0),
            'protected' => $this->isProtected($zone),
            'records' => $records,
        ];
    }

    /**
     * Create a zone with SOA, NS and optional apex/wildcard A records.
     *
     * @param  string|null  $ip  server IPv4 for the apex record
     *
     * @throws InvalidDnsRecord
     * @throws PowerDnsError
     */
    public function createZone(string $zone, ?string $ip, bool $wildcard): void
    {
        $zone = DnsSpec::zoneName($zone);

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidDnsRecord(['ip' => 'Server IP must be a valid IPv4 address.']);
        }

        if ($this->zoneExists($zone)) {
            throw new InvalidDnsRecord(['domain' => "Zone {$zone} already exists."]);
        }

        $nsDomain = $this->nsDomain();
        $api = $this->api($zone);

        $rrsets = [[
            'name' => $api,
            'type' => 'SOA',
            'ttl' => 86400,
            'records' => [[
                'content' => DnsSpec::soaContent("ns1.{$nsDomain}", "admin.{$nsDomain}", time()),
                'disabled' => false,
            ]],
        ], [
            'name' => $api,
            'type' => 'NS',
            'ttl' => 86400,
            'records' => [
                ['content' => "ns1.{$nsDomain}.", 'disabled' => false],
                ['content' => "ns2.{$nsDomain}.", 'disabled' => false],
            ],
        ]];

        if ($ip !== null) {
            $rrsets[] = [
                'name' => $api,
                'type' => 'A',
                'ttl' => 3600,
                'records' => [['content' => $ip, 'disabled' => false]],
            ];

            if ($wildcard) {
                $rrsets[] = [
                    'name' => "*.{$api}",
                    'type' => 'A',
                    'ttl' => 3600,
                    'records' => [['content' => $ip, 'disabled' => false]],
                ];
            }
        }

        $this->client->createZone([
            'name' => $api,
            'kind' => 'Native',
            'masters' => [],
            'rrsets' => $rrsets,
        ]);
    }

    /**
     * Import many zones at once (customer-domain wizard).
     *
     * @param  list<string>  $domains
     * @return array{created: list<string>, failed: list<array{domain: string, error: string}>}
     */
    public function importZones(array $domains, ?string $ip, bool $wildcard): array
    {
        $created = [];
        $failed = [];

        foreach ($domains as $domain) {
            $domain = trim($domain);

            if ($domain === '') {
                continue;
            }

            try {
                $this->createZone($domain, $ip, $wildcard);
                $created[] = $domain;
            } catch (InvalidDnsRecord $exception) {
                $failed[] = ['domain' => $domain, 'error' => implode(' ', $exception->errors)];
            } catch (PowerDnsError $exception) {
                $failed[] = ['domain' => $domain, 'error' => $exception->getMessage()];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $input  name, type, content, ttl
     *
     * @throws InvalidDnsRecord
     */
    public function addRecord(string $zone, array $input): void
    {
        $record = DnsSpec::record($zone, $input);
        $raw = $this->client->zone($this->api($zone));
        $this->ensureSerialManagement($zone, $raw);

        $existing = $this->rrset($raw, $record['name'], $record['type']);
        $contents = $this->contents($existing);

        if (in_array($record['content'], $contents, true)) {
            throw new InvalidDnsRecord(['content' => 'That record already exists.']);
        }

        $contents[] = $record['content'];

        $this->client->patchZone($this->api($zone), [$this->replace($record['name'], $record['type'], $record['ttl'], $contents)]);
    }

    /**
     * Edit one record row (content and/or ttl, may move name/type).
     *
     * @param  array{name: string, type: string, content: string}  $current
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidDnsRecord
     */
    public function updateRecord(string $zone, array $current, array $input): void
    {
        $oldName = DnsSpec::recordName($zone, (string) $current['name']);
        $oldType = strtoupper((string) $current['type']);
        $oldContent = (string) $current['content'];

        if ($oldName === null) {
            throw new InvalidDnsRecord(['name' => 'Record not found in this zone.']);
        }

        if ($oldType === 'SOA') {
            throw new InvalidDnsRecord(['type' => 'The SOA record is managed automatically and cannot be edited.']);
        }

        $record = DnsSpec::record($zone, $input);
        $raw = $this->client->zone($this->api($zone));
        $this->ensureSerialManagement($zone, $raw);

        $old = $this->rrset($raw, $oldName, $oldType);
        $oldContents = $this->contents($old);

        if (! in_array($oldContent, $oldContents, true)) {
            throw new InvalidDnsRecord(['content' => 'That record no longer exists — reload the page.']);
        }

        $changes = [];

        if ($record['name'] === $oldName && $record['type'] === $oldType) {
            if ($record['content'] !== $oldContent && in_array($record['content'], $oldContents, true)) {
                throw new InvalidDnsRecord(['content' => 'Another record with that content already exists here.']);
            }

            $contents = $record['content'] === $oldContent
                ? $oldContents
                : [...array_values(array_filter(
                    $oldContents,
                    fn (string $content): bool => $content !== $oldContent,
                )), $record['content']];

            $changes[] = $this->replace($oldName, $oldType, $record['ttl'], $contents);
        } else {
            // Moved to another name/type: remove from the old rrset, add to the new one.
            $remaining = array_values(array_filter($oldContents, fn (string $content): bool => $content !== $oldContent));
            $changes[] = $remaining === []
                ? ['name' => $oldName, 'type' => $oldType, 'changetype' => 'DELETE']
                : $this->replace($oldName, $oldType, (int) $old['ttl'], $remaining);

            $target = $this->rrset($raw, $record['name'], $record['type']);
            $targetContents = $this->contents($target);

            if (in_array($record['content'], $targetContents, true)) {
                throw new InvalidDnsRecord(['content' => 'Another record with that content already exists here.']);
            }

            $changes[] = $this->replace($record['name'], $record['type'], $record['ttl'], [...$targetContents, $record['content']]);
        }

        $this->client->patchZone($this->api($zone), $changes);
    }

    /**
     * Delete one record row.
     *
     * @param  array{name: string, type: string, content: string}  $current
     *
     * @throws InvalidDnsRecord
     */
    public function deleteRecord(string $zone, array $current): void
    {
        $name = DnsSpec::recordName($zone, (string) $current['name']);
        $type = strtoupper((string) $current['type']);
        $content = (string) $current['content'];

        if ($name === null) {
            throw new InvalidDnsRecord(['name' => 'Record not found in this zone.']);
        }

        if ($type === 'SOA') {
            throw new InvalidDnsRecord(['type' => 'The SOA record cannot be deleted.']);
        }

        $raw = $this->client->zone($this->api($zone));
        $this->ensureSerialManagement($zone, $raw);

        $existing = $this->rrset($raw, $name, $type);
        $contents = $this->contents($existing);

        if (! in_array($content, $contents, true)) {
            throw new InvalidDnsRecord(['content' => 'That record no longer exists — reload the page.']);
        }

        $remaining = array_values(array_filter($contents, fn (string $c): bool => $c !== $content));

        $change = $remaining === []
            ? ['name' => $name, 'type' => $type, 'changetype' => 'DELETE']
            : $this->replace($name, $type, (int) $existing['ttl'], $remaining);

        $this->client->patchZone($this->api($zone), [$change]);
    }

    /**
     * @throws InvalidDnsRecord when the zone is protected from deletion
     */
    public function deleteZone(string $zone): void
    {
        $zone = $this->display($zone);

        if ($this->isProtected($zone)) {
            throw new InvalidDnsRecord(['domain' => "{$zone} is the panel/nameserver zone and cannot be deleted."]);
        }

        $this->client->deleteZone($this->api($zone));
    }

    /**
     * The zone is protected when it hosts the panel or the nameservers —
     * deleting it would cut this server off from the world.
     */
    public function isProtected(string $zone): bool
    {
        $zone = $this->display($zone);

        return in_array($zone, [
            $this->nsDomain(),
            $this->display((string) config('panel.domain')),
        ], true);
    }

    /**
     * Legacy zones created outside the API may lack soa_edit_api=DEFAULT;
     * without it PowerDNS would never bump the serial after our changes.
     *
     * @param  array<string, mixed>  $raw
     */
    private function ensureSerialManagement(string $zone, array $raw): void
    {
        if (($raw['soa_edit_api'] ?? '') === 'DEFAULT') {
            return;
        }

        $this->client->updateZone($this->api($zone), ['soa_edit_api' => 'DEFAULT']);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function rrset(array $raw, string $name, string $type): array
    {
        foreach ($raw['rrsets'] ?? [] as $rrset) {
            if (($rrset['name'] ?? '') === $name && ($rrset['type'] ?? '') === $type) {
                return $rrset;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $rrset
     * @return list<string>
     */
    private function contents(?array $rrset): array
    {
        $contents = [];

        foreach ($rrset['records'] ?? [] as $record) {
            if (! empty($record['disabled'])) {
                continue;
            }

            $contents[] = (string) $record['content'];
        }

        return $contents;
    }

    /**
     * @param  list<string>  $contents
     * @return array<string, mixed>
     */
    private function replace(string $name, string $type, int $ttl, array $contents): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => array_map(
                fn (string $content): array => ['content' => $content, 'disabled' => false],
                $contents,
            ),
        ];
    }

    private function nsDomain(): string
    {
        return $this->display((string) (config('panel.pdns.ns_domain') ?: config('panel.domain')));
    }

    /**
     * API form: absolute name with trailing dot.
     */
    private function api(string $zone): string
    {
        return rtrim($zone, '.').'.';
    }

    /**
     * Display form: lowercase, no trailing dot.
     */
    private function display(string $zone): string
    {
        return strtolower(rtrim(trim($zone), '.'));
    }

    /**
     * Display form of a record name relative to its zone (@ for the apex).
     */
    private function relative(string $name, string $zone): string
    {
        $name = strtolower(rtrim($name, '.'));
        $zone = $this->display($zone);

        if ($name === $zone) {
            return '@';
        }

        if (str_ends_with($name, '.'.$zone)) {
            return substr($name, 0, -strlen($zone) - 1);
        }

        return $name;
    }
}
