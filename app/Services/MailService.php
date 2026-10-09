<?php

namespace App\Services;

use App\Exceptions\InvalidMailRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\StalwartError;
use App\Exceptions\StalwartUnavailable;
use App\Support\MailSpec;

/**
 * Mail domain and mailbox management on top of the Stalwart JMAP API.
 *
 * Domains are created with automatic DKIM and, when PowerDNS is reachable,
 * automatic DNS records published through Stalwart's native PowerDns
 * provider — MX/SPF/DMARC/DKIM for every zone the panel hosts. The zone
 * itself is created in PowerDNS first (the provider updates, it does not
 * create), and certificate management reuses the ACME provider registered
 * during install when one exists (DNS-01, no port conflict with Caddy).
 */
class MailService
{
    public function __construct(
        private readonly StalwartClient $client,
        private readonly DnsService $dns,
    ) {}

    /**
     * ok · bootstrap (wizard not finished) · unauthorized · unreachable ·
     * error (something else went wrong).
     */
    public function status(): string
    {
        try {
            $bootstrap = $this->client->call('x:Bootstrap/get', ['ids' => ['singleton']]);

            if (($bootstrap['list'] ?? []) !== []) {
                return 'bootstrap';
            }

            $this->client->call('x:Domain/query', ['filter' => (object) [], 'limit' => 1]);

            return 'ok';
        } catch (StalwartUnavailable) {
            return 'unreachable';
        } catch (StalwartError $exception) {
            return $exception->unauthorized ? 'unauthorized' : 'error';
        }
    }

    /**
     * Public mail hostname, e.g. mail.panel.example.com.
     */
    public function hostname(): string
    {
        $host = (string) config('panel.stalwart.host');

        if ($host !== '') {
            return $host;
        }

        return 'mail.'.config('panel.domain');
    }

    /**
     * @return list<array{id: string, name: string, enabled: bool, aliases: list<string>}>
     */
    public function domains(): array
    {
        $query = $this->client->call('x:Domain/query', ['filter' => (object) [], 'limit' => 500]);
        $ids = $this->ids($query);

        if ($ids === []) {
            return [];
        }

        $get = $this->client->call('x:Domain/get', ['ids' => $ids]);
        $domains = [];

        foreach ($get['list'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }

            $domains[] = [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? ''),
                'enabled' => (bool) ($entry['isEnabled'] ?? true),
                'aliases' => $this->stringSet($entry['aliases'] ?? []),
            ];
        }

        usort($domains, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $domains;
    }

    /**
     * One domain by id; null when it no longer exists.
     *
     * @return array{id: string, name: string, enabled: bool, aliases: list<string>}|null
     */
    public function domain(string $id): ?array
    {
        $get = $this->client->call('x:Domain/get', ['ids' => [$id]]);

        foreach ($get['list'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }

            return [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? ''),
                'enabled' => (bool) ($entry['isEnabled'] ?? true),
                'aliases' => $this->stringSet($entry['aliases'] ?? []),
            ];
        }

        return null;
    }

    /**
     * Mailboxes (accounts) that belong to a domain, excluding non-mailbox
     * principals such as the wizard's admin account.
     *
     * @return list<array{id: string, name: string, description: string, enabled: bool}>
     */
    public function mailboxes(string $domainId): array
    {
        return array_map(
            fn (array $account): array => [
                'id' => $account['id'],
                'name' => $account['name'],
                'description' => $account['description'],
                'enabled' => $account['enabled'],
            ],
            array_values(array_filter(
                $this->accounts($domainId, false),
                fn (array $account): bool => $account['isMailbox'],
            )),
        );
    }

    /**
     * Every account id belonging to a domain — including principals that
     * are not regular mailboxes (needed before a domain can be deleted).
     *
     * @return list<string>
     */
    private function accountIds(string $domainId): array
    {
        return array_map(
            fn (array $account): string => $account['id'],
            $this->accounts($domainId, true),
        );
    }

    /**
     * @return list<array{id: string, name: string, description: string, enabled: bool, isMailbox: bool}>
     */
    private function accounts(string $domainId, bool $skipRoles): array
    {
        $query = $this->client->call('x:Account/query', ['filter' => (object) [], 'limit' => 1000]);
        $ids = $this->ids($query);

        if ($ids === []) {
            return [];
        }

        $get = $this->client->call('x:Account/get', [
            'ids' => $ids,
            'properties' => $skipRoles
                ? ['name', 'domainId', 'description', 'isEnabled']
                : ['name', 'domainId', 'description', 'isEnabled', 'roles'],
        ]);

        $accounts = [];

        foreach ($get['list'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }

            if ((string) ($entry['domainId'] ?? '') !== (string) $domainId) {
                continue;
            }

            // Keep the display list to regular mailboxes — the wizard's
            // admin account lives in the default domain too but is not one.
            $roles = $entry['roles'] ?? null;
            $roleType = is_array($roles) ? (string) ($roles['@type'] ?? '') : (string) $roles;

            $accounts[] = [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? ''),
                'description' => (string) ($entry['description'] ?? ''),
                'enabled' => (bool) ($entry['isEnabled'] ?? true),
                'isMailbox' => $roles === null || $roleType === '' || $roleType === 'User',
            ];
        }

        usort($accounts, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $accounts;
    }

    /**
     * Create a mail domain: PowerDNS zone first (auto-records need it), then
     * the Stalwart domain with the panel's PowerDNS provider attached.
     *
     * @return string the new domain id
     *
     * @throws InvalidMailRecord
     * @throws PowerDnsError
     * @throws StalwartError
     */
    public function createDomain(string $name, ?string $ip = null): string
    {
        $name = MailSpec::domain($name);

        foreach ($this->domains() as $existing) {
            if ($existing['name'] === $name) {
                throw new InvalidMailRecord(['domain' => "Mail domain {$name} already exists."]);
            }
        }

        if (! $this->dns->zoneExists($name)) {
            $this->dns->createZone($name, $ip, false);
        }

        $create = [
            'name' => $name,
            'aliases' => (object) [],
            'certificateManagement' => $this->certificateManagement(),
            'dkimManagement' => ['@type' => 'Automatic'],
            'dnsManagement' => ['@type' => 'Automatic', 'dnsServerId' => $this->dnsServerId()],
            'subAddressing' => ['@type' => 'Enabled'],
            'isEnabled' => true,
            'description' => 'Created by VPS Panel',
        ];

        $payload = $this->client->call('x:Domain/set', ['create' => ['new1' => $create]]);
        $this->expectClean($payload, 'create the domain');

        $id = $payload['created']['new1']['id'] ?? null;

        if ($id === null) {
            throw new StalwartError('Stalwart did not return a domain id.');
        }

        return (string) $id;
    }

    /**
     * Delete a domain together with its mailboxes (accounts are destroyed
     * first — a domain with accounts is not deletable).
     *
     * @throws StalwartError
     */
    public function deleteDomain(string $id): void
    {
        $accountIds = $this->accountIds($id);

        if ($accountIds !== []) {
            $payload = $this->client->call('x:Account/set', ['destroy' => $accountIds]);
            $this->expectClean($payload, 'delete the mailboxes');
        }

        $payload = $this->client->call('x:Domain/set', ['destroy' => [$id]]);
        $this->expectClean($payload, 'delete the domain');
    }

    /**
     * Create a mailbox and return its address.
     *
     * @return array{id: string, email: string}
     *
     * @throws InvalidMailRecord
     * @throws StalwartError
     */
    public function createMailbox(string $domainId, string $domainName, array $input): array
    {
        $name = MailSpec::localPart((string) ($input['name'] ?? ''));
        $password = MailSpec::password((string) ($input['password'] ?? ''));
        $description = MailSpec::description((string) ($input['description'] ?? ''));

        $payload = $this->client->call('x:Account/set', ['create' => ['new1' => [
            '@type' => 'User',
            'name' => $name,
            'domainId' => $domainId,
            'credentials' => ['0' => ['@type' => 'Password', 'secret' => $password]],
            'roles' => ['@type' => 'User'],
            'permissions' => ['@type' => 'Inherit'],
            'quotas' => (object) [],
            'aliases' => (object) [],
            'memberGroupIds' => (object) [],
            'encryptionAtRest' => ['@type' => 'Disabled'],
            'description' => $description,
        ]]]);

        $this->expectClean($payload, 'create the mailbox');

        $id = $payload['created']['new1']['id'] ?? null;

        if ($id === null) {
            throw new StalwartError('Stalwart did not return a mailbox id.');
        }

        return [
            'id' => (string) $id,
            'email' => $name.'@'.$domainName,
        ];
    }

    /**
     * Replace a mailbox password. The credentials list is rewritten as a
     * single password credential — app passwords/API keys stored on the
     * account are replaced as well.
     *
     * @throws InvalidMailRecord
     * @throws StalwartError
     */
    public function resetMailboxPassword(string $id, string $password): void
    {
        $password = MailSpec::password($password);

        $payload = $this->client->call('x:Account/set', ['update' => [
            $id => ['credentials' => ['0' => ['@type' => 'Password', 'secret' => $password]]],
        ]]);

        $this->expectClean($payload, 'reset the password');
    }

    /**
     * @throws StalwartError
     */
    public function deleteMailbox(string $id): void
    {
        $payload = $this->client->call('x:Account/set', ['destroy' => [$id]]);
        $this->expectClean($payload, 'delete the mailbox');
    }

    /**
     * The panel's PowerDNS provider inside Stalwart, created on first use.
     */
    private function dnsServerId(): string
    {
        $query = $this->client->call('x:DnsServer/query', ['filter' => (object) [], 'limit' => 100]);

        foreach ($query['ids'] ?? [] as $id) {
            $get = $this->client->call('x:DnsServer/get', ['ids' => [(string) $id]]);

            foreach ($get['list'] ?? [] as $entry) {
                if ((string) ($entry['@type'] ?? '') === 'PowerDns') {
                    return (string) ($entry['id'] ?? $id);
                }
            }
        }

        $payload = $this->client->call('x:DnsServer/set', ['create' => ['new1' => [
            '@type' => 'PowerDns',
            'description' => 'VPS Panel PowerDNS',
            'apiKey' => (string) config('panel.pdns.key'),
            'endpoint' => (string) config('panel.pdns.url'),
            'serverId' => (string) config('panel.pdns.server'),
        ]]]);

        $this->expectClean($payload, 'register PowerDNS');

        $id = $payload['created']['new1']['id'] ?? null;

        if ($id === null) {
            throw new StalwartError('Stalwart did not return the DNS provider id.');
        }

        return (string) $id;
    }

    /**
     * Automatic certificates via the ACME provider registered at install
     * (DNS-01 through PowerDNS); manual when none exists.
     *
     * @return array<string, mixed>
     */
    private function certificateManagement(): array
    {
        try {
            $query = $this->client->call('x:AcmeProvider/query', ['filter' => (object) [], 'limit' => 1]);
        } catch (StalwartError) {
            return ['@type' => 'Manual'];
        }

        $ids = $this->ids($query);

        if ($ids === []) {
            return ['@type' => 'Manual'];
        }

        return ['@type' => 'Automatic', 'acmeProviderId' => $ids[0]];
    }

    /**
     * Surface the first notCreated/notUpdated/notDestroyed entry as an error.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws StalwartError
     */
    private function expectClean(array $payload, string $action): void
    {
        foreach (['notCreated', 'notUpdated', 'notDestroyed'] as $key) {
            foreach ($payload[$key] ?? [] as $entry) {
                $description = is_array($entry) ? trim((string) ($entry['description'] ?? '')) : '';

                throw new StalwartError(
                    $description !== ''
                        ? "Could not {$action}: {$description}"
                        : "Stalwart could not {$action}.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function ids(array $result): array
    {
        $ids = [];

        foreach ($result['ids'] ?? [] as $id) {
            if (is_string($id) || is_int($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * A Set<String> decoded from JSON — either a list or an index-keyed map.
     *
     * @return list<string>
     */
    private function stringSet(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_is_list($value) ? $value : array_keys($value);
        $strings = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
