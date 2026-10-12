<?php

namespace App\Services;

use App\Models\User;
use Throwable;

/**
 * Everything the Setup page reports: which panel is installed (domain, PHP,
 * database engine and root credentials), the nameserver records the registrar
 * needs, and a checklist of what is still missing. Each checklist entry can
 * carry an action so the view can render a button for it.
 */
class SetupService
{
    public function __construct(
        private readonly PowerDnsClient $pdns,
        private readonly MailService $mail,
    ) {}

    /**
     * @return array{
     *     domain: string,
     *     url: string,
     *     alt_urls: list<array{url: string, note: string}>,
     *     php: array{installed: string, running: string},
     *     admin: string|null,
     *     database: array{engine: string, user: string},
     *     dns: array{
     *         ns_domain: string,
     *         ns1: string,
     *         ns2: string,
     *         server_ip: string,
     *         records: list<array{name: string, type: string, content: string}>,
     *     },
     *     items: list<array{
     *         key: string,
     *         label: string,
     *         status: 'ok'|'pending'|'fail',
     *         detail: string,
     *         optional: bool,
     *         action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null,
     *     }>,
     *     done: int,
     *     total: int,
     *     required_pending: int,
     * }
     */
    public function summary(): array
    {
        $domain = (string) config('panel.domain');
        $nsDomain = (string) config('panel.pdns.ns_domain', $domain);
        $serverIp = (string) config('panel.pdns.server_ip', '');
        $installed = (string) config('panel.php');
        $running = PHP_VERSION;

        $zones = $this->zones();

        $items = [
            $this->panelItem(),
            $this->phpItem($installed, $running),
            $this->caddyItem($domain),
            $this->powerDnsItem($zones),
            $this->zoneItem($zones, $domain),
            $this->phpMyAdminItem($domain),
            $this->emailItem($domain),
        ];

        return [
            'domain' => $domain,
            'url' => rtrim((string) config('app.url'), '/'),
            'alt_urls' => $this->alternativeUrls($serverIp),
            'php' => ['installed' => $installed, 'running' => $running],
            'admin' => User::orderBy('id')->value('email'),
            'database' => [
                'engine' => $this->databaseEngine(),
                'user' => 'root',
            ],
            'dns' => [
                'ns_domain' => $nsDomain,
                'ns1' => 'ns1.'.$nsDomain,
                'ns2' => 'ns2.'.$nsDomain,
                'server_ip' => $serverIp,
                'records' => $this->records($domain, $nsDomain, $serverIp),
            ],
            'items' => $items,
            'done' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'ok')),
            'total' => count($items),
            'required_pending' => count(array_filter(
                $items,
                fn (array $item): bool => $item['status'] !== 'ok' && ! $item['optional'],
            )),
        ];
    }

    /**
     * Zone list, or null when the PowerDNS API does not answer.
     *
     * @return list<array<string, mixed>>|null
     */
    private function zones(): ?array
    {
        try {
            $zones = $this->pdns->zones();

            return is_array($zones) ? $zones : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{name: string, type: string, content: string}>
     */
    private function records(string $domain, string $nsDomain, string $serverIp): array
    {
        if ($nsDomain === '') {
            return [];
        }

        $ns1 = 'ns1.'.$nsDomain;
        $ns2 = 'ns2.'.$nsDomain;

        $records = [
            ['name' => $nsDomain.'.', 'type' => 'NS', 'content' => $ns1.'.'],
            ['name' => $nsDomain.'.', 'type' => 'NS', 'content' => $ns2.'.'],
        ];

        if ($serverIp !== '') {
            $records[] = ['name' => $ns1.'.', 'type' => 'A', 'content' => $serverIp];
            $records[] = ['name' => $ns2.'.', 'type' => 'A', 'content' => $serverIp];
            $records[] = ['name' => $domain.'.', 'type' => 'A', 'content' => $serverIp];
        }

        return $records;
    }

    /**
     * Addresses that answer while DNS for the panel domain still points
     * elsewhere — only those the written Caddy site file actually contains.
     *
     * @return list<array{url: string, note: string}>
     */
    private function alternativeUrls(string $serverIp): array
    {
        $site = (string) config('panel.caddy_sites_dir').'/panel.caddy';

        if ($serverIp === '' || ! is_file($site)) {
            return [];
        }

        $snippet = (string) file_get_contents($site);
        $urls = [];

        if (str_contains($snippet, 'nip.io')) {
            $urls[] = [
                'url' => 'https://panel.'.$serverIp.'.nip.io',
                'note' => 'Real certificate — works before the domain points here.',
            ];
        }

        if (str_contains($snippet, 'http://'.$serverIp)) {
            $urls[] = [
                'url' => 'http://'.$serverIp,
                'note' => 'Plain HTTP — finish setup here, then switch to HTTPS.',
            ];
        }

        return $urls;
    }

    /**
     * MySQL vs MariaDB — the panel cannot query the server as www-data, so
     * the installed daemon binary is what identifies the engine.
     */
    private function databaseEngine(): string
    {
        if (is_file('/usr/sbin/mariadbd')) {
            return 'MariaDB';
        }

        if (is_file('/usr/sbin/mysqld')) {
            return 'MySQL';
        }

        return 'MySQL (not detected)';
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function panelItem(): array
    {
        $admin = User::orderBy('id')->value('email');

        if ($admin === null) {
            return $this->item(
                'panel',
                'Panel',
                'fail',
                'No administrator account exists — run php artisan panel:admin:create.',
            );
        }

        return $this->item(
            'panel',
            'Panel',
            'ok',
            "Application migrated; {$admin} can sign in.",
        );
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function phpItem(string $installed, string $running): array
    {
        $ahead = version_compare($running, $installed, '>=');

        return $this->item(
            'php',
            'PHP',
            $ahead ? 'ok' : 'fail',
            $ahead
                ? "Running PHP {$running}; module sites use PHP {$installed}."
                : "Running PHP {$running} is older than the configured PHP {$installed}.",
        );
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function caddyItem(string $domain): array
    {
        $configured = is_file((string) config('panel.caddyfile'))
            && is_file(config('panel.caddy_sites_dir').'/panel.caddy');

        return $this->item(
            'caddy',
            'Caddy',
            $configured ? 'ok' : 'pending',
            $configured
                ? "Serves https://{$domain} and every module site over HTTPS."
                : "No Caddy site for {$domain} yet — re-run install-panel.sh to write it.",
            false,
            ['label' => 'Open Caddyfile', 'url' => route('logs.index'), 'method' => 'GET'],
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $zones
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function powerDnsItem(?array $zones): array
    {
        return $this->item(
            'powerdns',
            'PowerDNS',
            $zones === null ? 'fail' : 'ok',
            $zones === null
                ? 'The API at 127.0.0.1:8081 did not answer — check systemctl status pdns.'
                : 'API reachable on 127.0.0.1:8081 · '.count($zones).' zone(s) loaded.',
            false,
            ['label' => 'Open DNS module', 'url' => route('dns.index'), 'method' => 'GET'],
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $zones
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function zoneItem(?array $zones, string $domain): array
    {
        if ($zones === null) {
            return $this->item(
                'zone',
                'Panel zone',
                'pending',
                'Create the zone once PowerDNS is running — otherwise your nameservers will not answer.',
                false,
                ['label' => 'Open DNS module', 'url' => route('dns.index'), 'method' => 'GET'],
            );
        }

        $exists = in_array($domain.'.', array_column($zones, 'name'), true);

        return $this->item(
            'zone',
            'Panel zone',
            $exists ? 'ok' : 'pending',
            $exists
                ? "Zone {$domain} exists and answers through ns1/ns2."
                : "Zone {$domain} is not created — your nameservers will not answer for it.",
            false,
            $exists
                ? ['label' => 'Open zone', 'url' => route('dns.show', $domain), 'method' => 'GET']
                : ['label' => 'Create zone', 'url' => route('dns.create'), 'method' => 'GET'],
        );
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function phpMyAdminItem(string $domain): array
    {
        $installed = is_file((string) config('panel.phpmyadmin.dir').'/index.php');
        $url = 'https://pma.'.$domain;

        return $this->item(
            'phpmyadmin',
            'phpMyAdmin',
            $installed ? 'ok' : 'pending',
            $installed
                ? "Ready at {$url}."
                : 'Not installed — browse MySQL as root from the browser.',
            true,
            $installed
                ? ['label' => 'Open phpMyAdmin', 'url' => $url, 'method' => 'external']
                : ['label' => 'Install phpMyAdmin', 'url' => route('pma.install'), 'method' => 'POST'],
        );
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function emailItem(string $domain): array
    {
        try {
            $status = $this->mail->status();
        } catch (Throwable) {
            $status = 'unreachable';
        }

        return match ($status) {
            'ok' => $this->item(
                'email',
                'Email',
                'ok',
                "Stalwart is running and reachable at mail.{$domain}.",
                true,
                ['label' => 'Open Email module', 'url' => route('mail.index'), 'method' => 'GET'],
            ),
            'bootstrap' => $this->item(
                'email',
                'Email',
                'pending',
                'Stalwart is installed but its admin wizard is not finished.',
                true,
                ['label' => 'Complete setup', 'url' => route('mail.install'), 'method' => 'POST'],
            ),
            default => $this->item(
                'email',
                'Email',
                'pending',
                'Not installed — optional mail server for @'.$domain.' addresses.',
                true,
                ['label' => 'Open Email module', 'url' => route('mail.index'), 'method' => 'GET'],
            ),
        };
    }

    /**
     * @param  array{label: string, url: string, method: 'GET'|'POST'|'external'}|null  $action
     * @return array{key: string, label: string, status: 'ok'|'pending'|'fail', detail: string, optional: bool, action: array{label: string, url: string, method: 'GET'|'POST'|'external'}|null}
     */
    private function item(
        string $key,
        string $label,
        string $status,
        string $detail,
        bool $optional = false,
        ?array $action = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'optional' => $optional,
            'action' => $action,
        ];
    }
}
