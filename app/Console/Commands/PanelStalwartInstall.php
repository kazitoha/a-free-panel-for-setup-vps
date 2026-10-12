<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidDnsRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Exceptions\StalwartError;
use App\Exceptions\StalwartUnavailable;
use App\Models\Install;
use App\Services\CaddyFileWriter;
use App\Services\DnsService;
use App\Services\StalwartClient;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side Stalwart installer, spawned in the background by InstallRunner.
 *
 * Runs the official install.sh, pins a recovery administrator so the panel
 * can talk to the JMAP API, completes the setup wizard over JMAP
 * (x:Bootstrap/set — hostname, default domain, PowerDNS provider, DKIM,
 * ACME via DNS-01), locks the HTTP listener to loopback and frees port 443
 * for Caddy, writes the mail.<panel-domain> Caddy site, seeds the mail host
 * A record and opens the mail ports. Every step logs to stdout (captured
 * into the install log) and updates the installs row for the web UI poller.
 */
#[Signature('panel:stalwart:install {id : Install record id}')]
#[Description('Install Stalwart, complete the setup wizard and serve it behind Caddy')]
class PanelStalwartInstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly CaddyFileWriter $writer,
        private readonly StalwartClient $client,
        private readonly DnsService $dns,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'stalwart') {
            $this->error('Install record not found.');

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
        ])->save();

        try {
            $this->runSteps($install);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $install->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ])->save();

        $this->info('Stalwart mail is ready at https://'.$this->hostname().'/admin');

        return self::SUCCESS;
    }

    private function runSteps(Install $install): void
    {
        $domain = (string) config('panel.domain');
        $mailHost = $this->hostname();

        // 1. Install the server -------------------------------------------
        $unit = $this->detectUnit();

        if ($unit === null) {
            $this->installServer();
            $unit = $this->detectUnit();

            if ($unit === null) {
                throw new RuntimeException('install.sh finished but no stalwart systemd unit was found.');
            }
        } else {
            $this->line("Stalwart unit {$unit} already present — skipping download.");
        }

        // 2. Pin the recovery administrator --------------------------------
        $pin = $this->pinRecoveryAdmin($mailHost);
        [$pinUser, $pinPassword] = explode(':', $pin, 2) + ['0' => 'admin', '1' => ''];
        $admin = $this->client->as($pinUser, $pinPassword);

        $this->restart($unit);
        $mode = $this->waitForMode($admin, ['bootstrap', 'normal'], 120);
        $this->line($mode === 'bootstrap' ? 'Bootstrap mode reached.' : 'Already bootstrapped — repairing.');

        // 3. Complete the setup wizard -------------------------------------
        $captured = null;

        if ($mode === 'bootstrap') {
            $captured = $this->completeBootstrap($admin, $unit, $mailHost, $domain);
        }

        // 4. Free port 443 for Caddy, keep the HTTP listener local ---------
        $this->line('Hardening listeners (drop https, bind http to loopback)...');
        $this->hardenListeners($admin);

        $this->restart($unit);

        if ($this->waitForMode($admin, ['normal'], 90) !== 'normal') {
            throw new RuntimeException('Stalwart did not return to normal mode after the listener change.');
        }

        // 5. Panel credentials ---------------------------------------------
        $this->writePanelEnv($mailHost, $pinUser, $pinPassword, $captured);

        // 6. Caddy site -----------------------------------------------------
        $this->line('Configuring Caddy site...');
        $spec = SiteSpec::validate([
            'domain' => $mailHost,
            'wildcard' => false,
            'type' => 'proxy',
            'target' => '127.0.0.1:8080',
        ]);

        $this->writer->write('mail', SiteSpec::build($spec));
        $this->line("Caddy site {$mailHost} is live.");

        // 7. Mail host A record + firewall ----------------------------------
        $this->seedMailHostRecord($domain, $mailHost);
        $this->openMailPorts();

        // 8. Final check -----------------------------------------------------
        $this->verify($admin);
    }

    /**
     * Download and run the official Stalwart installer (non-interactive).
     */
    private function installServer(): void
    {
        $this->line('Downloading Stalwart installer...');
        $script = '/tmp/panel-stalwart-install.sh';

        $result = $this->command->run([
            'curl', '-fsSL', '--connect-timeout', '15', '--max-time', '120',
            '-o', $script, 'https://get.stalw.art/install.sh',
        ], 130.0);

        if ($result['exit'] !== 0 || ! is_file($script)) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Download of install.sh failed.'
                .($detail !== '' ? ' '.mb_substr($detail, 0, 400) : '')
            );
        }

        try {
            $this->line('Running install.sh...');
            $this->mustRun(['sh', $script], 600.0);
        } finally {
            @unlink($script);
        }
    }

    /**
     * 'stalwart' | 'stalwart-mail' | null when neither unit exists.
     */
    private function detectUnit(): ?string
    {
        foreach (['stalwart', 'stalwart-mail'] as $candidate) {
            if ($this->command->run(['systemctl', 'cat', $candidate], 15)['exit'] === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ensure STALWART_RECOVERY_ADMIN exists in the env file and return it
     * as "user:password". An existing pin is reused so a re-run (or a wiped
     * panel .env) can always get back in.
     */
    private function pinRecoveryAdmin(string $mailHost): string
    {
        $path = '/etc/stalwart/stalwart.env';
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $pin = '';

        if (preg_match('/^STALWART_RECOVERY_ADMIN=(\S+)$/m', $content, $matches) === 1
            && str_contains($matches[1], ':')) {
            $pin = $matches[1];
        }

        if ($pin === '') {
            $pin = 'admin:'.bin2hex(random_bytes(16));
            $this->line('Generated a recovery administrator for the JMAP API.');
        }

        $this->upsertFile($path, [
            'STALWART_RECOVERY_ADMIN' => $pin,
            'STALWART_PUBLIC_URL' => 'https://'.$mailHost,
        ]);

        if (! is_file($path)) {
            throw new RuntimeException("Could not write {$path}.");
        }

        return $pin;
    }

    /**
     * Complete the setup wizard over JMAP and return the permanent admin
     * credentials when the response carried them (null on connection drop —
     * the restart in that case proves whether it was accepted).
     *
     * @return array{username: string, secret: string}|null
     */
    private function completeBootstrap(StalwartClient $admin, string $unit, string $mailHost, string $domain): ?array
    {
        $this->ensureZone($domain);

        $payload = [
            'serverHostname' => $mailHost,
            'defaultDomain' => $domain,
            'requestTlsCertificate' => true,
            'generateDkimKeys' => true,
            'dnsServer' => [
                '@type' => 'PowerDns',
                'description' => 'VPS Panel PowerDNS',
                'apiKey' => (string) config('panel.pdns.key'),
                'endpoint' => (string) config('panel.pdns.url'),
                'serverId' => (string) config('panel.pdns.server'),
            ],
        ];

        $captured = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->line("Completing setup (attempt {$attempt})...");

            try {
                $response = $admin->call('x:Bootstrap/set', ['update' => ['singleton' => $payload]]);
                $updated = $response['updated']['singleton'] ?? null;

                if (! is_array($updated)) {
                    throw new StalwartError('Stalwart did not accept the setup — no update confirmation.');
                }

                if (isset($updated['username'], $updated['secret'])) {
                    $captured = [
                        'username' => (string) $updated['username'],
                        'secret' => (string) $updated['secret'],
                    ];
                    $this->line("Permanent administrator created: {$captured['username']}");
                }
            } catch (StalwartUnavailable) {
                $this->line('Connection dropped — Stalwart restarted mid-request.');
            }

            $this->restart($unit);

            if ($this->waitForMode($admin, ['normal'], 90) === 'normal') {
                $this->line('Setup completed — Stalwart is running normally.');

                return $captured;
            }

            if ($this->mode($admin) === 'bootstrap') {
                $this->line('Still in bootstrap mode — retrying.');
            }
        }

        throw new RuntimeException('Stalwart did not complete the setup wizard — check the service logs.');
    }

    /**
     * The default https listener would fight Caddy for port 443 and the
     * default http listener binds every interface — drop the first, keep
     * the second on loopback so only the mail Caddy site can reach it.
     */
    private function hardenListeners(StalwartClient $admin): void
    {
        $query = $admin->call('x:NetworkListener/query', ['filter' => (object) [], 'limit' => 50]);
        $ids = [];

        foreach ($query['ids'] ?? [] as $id) {
            $ids[] = (string) $id;
        }

        if ($ids === []) {
            $this->line('No listeners found — defaults will be recreated on next start.');

            return;
        }

        $get = $admin->call('x:NetworkListener/get', ['ids' => $ids]);
        $set = [];

        foreach ($get['list'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['id'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === 'https') {
                $set['destroy'] ??= [];
                $set['destroy'][] = (string) $entry['id'];
            } elseif ($name === 'http') {
                $set['update'] ??= [];
                $set['update'][(string) $entry['id']] = ['bind' => ['0' => '127.0.0.1:8080']];
            }
        }

        if (! isset($set['update']) && ! isset($set['destroy'])) {
            $this->line('Listeners already hardened.');

            return;
        }

        $payload = $admin->call('x:NetworkListener/set', $set);
        $this->expectClean($payload, 'update the listeners');
    }

    /**
     * @param  list<string>  $targets
     * @return string the mode that was reached
     */
    private function waitForMode(StalwartClient $client, array $targets, int $seconds): string
    {
        $deadline = microtime(true) + $seconds;
        $mode = 'down';

        do {
            $mode = $this->mode($client);

            if (in_array($mode, $targets, true)) {
                return $mode;
            }

            sleep(2);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'Timed out waiting for Stalwart ('.implode(' or ', $targets)
            ." — last state: {$mode}). Check systemctl status and port 8080.",
        );
    }

    /**
     * 'bootstrap' · 'normal' · 'down'.
     */
    private function mode(StalwartClient $client): string
    {
        try {
            $get = $client->call('x:Bootstrap/get', ['ids' => ['singleton']]);
        } catch (StalwartUnavailable) {
            return 'down';
        }

        return ($get['list'] ?? []) === [] ? 'normal' : 'bootstrap';
    }

    /**
     * The panel domain zone must exist before Stalwart publishes records
     * (and before ACME DNS-01 challenges) through PowerDNS.
     */
    private function ensureZone(string $domain): void
    {
        if ($this->dns->zoneExists($domain)) {
            return;
        }

        $ip = (string) (config('panel.pdns.server_ip') ?: '');
        $this->dns->createZone($domain, $ip !== '' ? $ip : null, false);
        $this->line("Created PowerDNS zone {$domain}.");
    }

    /**
     * Persist panel credentials: the wizard's permanent admin when we saw
     * it, otherwise keep what .env already has, otherwise the pin.
     *
     * @param  array{username: string, secret: string}|null  $captured
     */
    private function writePanelEnv(string $mailHost, string $pinUser, string $pinPassword, ?array $captured): void
    {
        $existingPassword = (string) config('panel.stalwart.password', '');

        if ($captured !== null) {
            $user = $captured['username'];
            $password = $captured['secret'];
        } elseif ($existingPassword !== '') {
            $user = (string) config('panel.stalwart.user', $pinUser);
            $password = $existingPassword;
        } else {
            $user = $pinUser;
            $password = $pinPassword;
        }

        // The chosen credentials must actually work — fall back to the pin
        // (honoured in every mode) when they do not.
        try {
            $this->client->as($user, $password)->call('x:Domain/query', ['filter' => (object) [], 'limit' => 1]);
        } catch (StalwartError $exception) {
            if (! $exception->unauthorized || ($user === $pinUser && $password === $pinPassword)) {
                throw $exception;
            }

            $this->line('Wizard credentials rejected — falling back to the recovery administrator.');
            $user = $pinUser;
            $password = $pinPassword;
        }

        $this->upsertFile(base_path('.env'), [
            'STALWART_URL' => (string) config('panel.stalwart.url'),
            'STALWART_USER' => $user,
            'STALWART_PASSWORD' => $password,
            'STALWART_HOST' => $mailHost,
        ]);

        $this->line('Panel credentials written to .env.');
    }

    /**
     * The MX target (mail.<domain>) needs an A record inside the panel zone;
     * Stalwart publishes MX/SPF/DMARC/DKIM itself but never address records.
     */
    private function seedMailHostRecord(string $domain, string $mailHost): void
    {
        $ip = (string) (config('panel.pdns.server_ip') ?: '');

        if ($ip === '' || $mailHost !== 'mail.'.$domain) {
            return;
        }

        try {
            if (! $this->dns->zoneExists($domain)) {
                return;
            }

            $this->dns->addRecord($domain, [
                'name' => 'mail',
                'type' => 'A',
                'content' => $ip,
                'ttl' => 3600,
            ]);

            $this->line("A record {$mailHost} → {$ip} added.");
        } catch (InvalidDnsRecord $exception) {
            if (! str_contains(implode(' ', $exception->errors), 'already exists')) {
                throw $exception;
            }
        } catch (PowerDnsError|PowerDnsUnavailable $exception) {
            $this->line('Could not add the mail host A record: '.$exception->getMessage());
        }
    }

    private function openMailPorts(): void
    {
        foreach (['25/tcp', '465/tcp', '587/tcp', '993/tcp', '995/tcp', '4190/tcp'] as $port) {
            $result = $this->command->run(['ufw', 'allow', $port], 15);

            if ($result['exit'] !== 0) {
                $this->line("UFW rule for {$port} skipped (ufw inactive or unavailable).");
            }
        }
    }

    private function verify(StalwartClient $admin): void
    {
        $admin->call('x:Domain/query', ['filter' => (object) [], 'limit' => 1]);
        $this->line('JMAP API answered on '.config('panel.stalwart.url').' — all good.');
    }

    private function hostname(): string
    {
        $host = (string) config('panel.stalwart.host');

        return $host !== '' ? $host : 'mail.'.config('panel.domain');
    }

    private function restart(string $unit): void
    {
        $this->line("Restarting {$unit}...");
        $this->mustRun(['systemctl', 'restart', $unit], 90.0);
    }

    private function mustRun(array $command, float $timeout = 300.0): void
    {
        $result = $this->command->run($command, $timeout);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Command failed: '.implode(' ', $command)
                .($detail !== '' ? ' — '.mb_substr($detail, 0, 400) : '')
            );
        }
    }

    /**
     * Set/create/destroy entries come back under notCreated/notUpdated/
     * notDestroyed — surface the first one.
     *
     * @param  array<string, mixed>  $payload
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
     * Insert or replace KEY=value lines in a file, creating it when absent.
     *
     * @param  array<string, string>  $values
     */
    private function upsertFile(string $path, array $values): void
    {
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content) === 1) {
                $content = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content, 1);
            } else {
                $content = rtrim($content)."\n".$line."\n";
            }
        }

        if (@file_put_contents($path, $content) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }

        @chmod($path, 0640);
    }
}
