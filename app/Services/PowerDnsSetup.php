<?php

namespace App\Services;

use App\Exceptions\InvalidDnsRecord;
use App\Exceptions\PowerDnsError;
use App\Support\EnvFile;
use App\Support\HostCommand;
use App\Support\MySql;
use App\Support\SecretsFile;
use RuntimeException;

/**
 * Installs PowerDNS (authoritative server + localhost HTTP API) for the DNS
 * module: package, port 53, database, pdns.conf, service and the two zones
 * every install starts with.
 *
 * Runs as root inside a panel:* command. MySQL is set up first through
 * MySqlSetup, so the Databases module's install covers both.
 */
class PowerDnsSetup
{
    /**
     * PowerDNS schema — identical to what the installer used to write, so a
     * half-finished run stays idempotent.
     */
    private const string PDNS_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS domains (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255) NOT NULL,
  master                VARCHAR(128) DEFAULT NULL,
  last_check            INT DEFAULT NULL,
  type                  VARCHAR(6) NOT NULL,
  notified_serial       INT UNSIGNED DEFAULT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY name_index (name)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS records (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT DEFAULT NULL,
  name                  VARCHAR(255) DEFAULT NULL,
  type                  VARCHAR(10) DEFAULT NULL,
  content               TEXT DEFAULT NULL,
  ttl                   INT DEFAULT 3600,
  prio                  INT DEFAULT 0,
  disabled              TINYINT(1) DEFAULT 0,
  ordername             VARCHAR(255) BINARY DEFAULT NULL,
  auth                  TINYINT(1) DEFAULT 1,
  PRIMARY KEY (id),
  KEY rec_name_time_index (name(255), type),
  KEY domain_id (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS supermasters (
  ip                    VARCHAR(64) NOT NULL,
  nameserver            VARCHAR(255) NOT NULL,
  account               VARCHAR(40) NOT NULL,
  PRIMARY KEY (ip, nameserver)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  name                  VARCHAR(255) NOT NULL,
  type                  VARCHAR(10) NOT NULL,
  modified_at           INT NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  comment               TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY name_index (name(255), type),
  KEY domain_id (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS domainmetadata (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  kind                  VARCHAR(32),
  content               TEXT,
  PRIMARY KEY (id),
  KEY domainmetadata_idx (domain_id, kind)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS cryptokeys (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  flags                 INT NOT NULL,
  active                BOOL,
  published             BOOL DEFAULT 1,
  content               TEXT,
  PRIMARY KEY (id),
  KEY domainidindex (domain_id)
) Engine=InnoDB;

CREATE TABLE IF NOT EXISTS tsigkeys (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255),
  algorithm             VARCHAR(50),
  secret                VARCHAR(255),
  PRIMARY KEY (id),
  UNIQUE KEY name_algo_index (name, algorithm)
) Engine=InnoDB;
SQL;

    public function __construct(
        private readonly HostCommand $command,
        private readonly MySqlSetup $mysql,
        private readonly DnsService $dns,
    ) {}

    /**
     * @param  \Closure(string): void  $log
     */
    public function ensure(\Closure $log): void
    {
        $this->mysql->ensure($log);

        $this->freePort53($log);

        if ($this->packageInstalled()) {
            $log('PowerDNS is already installed.');
        } else {
            $log('Installing pdns-server and the MySQL backend...');
            $this->installPackage();
        }

        [$databasePassword, $apiKey] = $this->database($log);

        $this->writeConfig($databasePassword, $apiKey, $log);

        $this->store($databasePassword, $apiKey);

        $this->start($log, $apiKey);

        $this->seedZones($log);
    }

    private function packageInstalled(): bool
    {
        return $this->command->run(['dpkg', '-s', 'pdns-server'], 20.0)['exit'] === 0;
    }

    private function installPackage(): void
    {
        $this->command->run(['apt-get', 'update', '-qq'], 300.0);

        $result = $this->command->run(
            ['apt-get', 'install', '-y', '-qq', 'pdns-server', 'pdns-backend-mysql'],
            900.0,
            ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        if ($result['exit'] !== 0) {
            throw new RuntimeException('Could not install PowerDNS.'.$this->detail($result));
        }
    }

    /**
     * systemd-resolved holds 127.0.0.53:53, which stops pdns from binding
     * 0.0.0.0:53. Turn the stub off and point /etc/resolv.conf at the real
     * upstream list so the host keeps resolving.
     *
     * @param  \Closure(string): void  $log
     */
    private function freePort53(\Closure $log): void
    {
        if ($this->command->run(['systemctl', 'is-active', '--quiet', 'systemd-resolved'], 10.0)['exit'] !== 0) {
            return;
        }

        $path = '/etc/systemd/resolved.conf';
        $contents = (string) @file_get_contents($path);

        if ($contents === '' || preg_match('/^#?DNSStubListener=/m', $contents) !== 1) {
            return;
        }

        $log('Freeing port 53 for PowerDNS (disabling the systemd-resolved stub listener)...');

        @file_put_contents($path, (string) preg_replace('/^#?DNSStubListener=.*/m', 'DNSStubListener=no', $contents, 1));

        $linked = $this->command->run(['ln', '-sfn', '/run/systemd/resolve/resolv.conf', '/etc/resolv.conf'], 10.0);

        if ($linked['exit'] !== 0) {
            @file_put_contents('/etc/resolv.conf', "nameserver 1.1.1.1\nnameserver 8.8.8.8\n");
        }

        $this->command->run(['systemctl', 'restart', 'systemd-resolved'], 30.0);
    }

    /**
     * Create the pdns database, user and schema when missing, and return the
     * credentials pdns.conf needs.
     *
     * @param  \Closure(string): void  $log
     * @return array{0: string, 1: string} [database password, API key]
     */
    private function database(\Closure $log): array
    {
        $secrets = SecretsFile::all();
        $databasePassword = $secrets['PDNS_DB_PASSWORD'] ?? bin2hex(random_bytes(16));
        $apiKey = $secrets['PDNS_API_KEY'] ?? bin2hex(random_bytes(24));

        $check = $this->runSql(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'pdns'"
        );

        if ($check['exit'] !== 0) {
            throw new RuntimeException('Could not inspect MySQL.'.$this->detail($check));
        }

        if (trim($check['stdout']) === '') {
            $log('Creating the PowerDNS database and user...');

            $create = $this->runSql(
                "CREATE DATABASE IF NOT EXISTS pdns;\n"
                ."CREATE USER IF NOT EXISTS 'pdns_user'@'localhost' IDENTIFIED BY ".MySql::literal($databasePassword).";\n"
                ."ALTER USER 'pdns_user'@'localhost' IDENTIFIED BY ".MySql::literal($databasePassword).";\n"
                ."GRANT ALL PRIVILEGES ON pdns.* TO 'pdns_user'@'localhost';\n"
                .'FLUSH PRIVILEGES;'
            );

            if ($create['exit'] !== 0) {
                throw new RuntimeException('Could not create the PowerDNS database.'.$this->detail($create));
            }
        } else {
            $log('PowerDNS database already exists.');
        }

        $schema = $this->runSql((string) self::PDNS_SCHEMA, 'pdns');

        if ($schema['exit'] !== 0) {
            throw new RuntimeException('Could not create the PowerDNS schema.'.$this->detail($schema));
        }

        return [$databasePassword, $apiKey];
    }

    /**
     * Make sure pdns.conf has the gmysql block and the localhost HTTP API,
     * both agreeing with the credentials in the secrets file.
     */
    private function writeConfig(string $databasePassword, string $apiKey, \Closure $log): void
    {
        $path = (string) config('panel.pdns.conf_file');
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';

        if ($existing === '' || preg_match('/^launch=/m', $existing) !== 1) {
            $log("Writing PowerDNS configuration to {$path}...");

            $config = <<<CONF
# PowerDNS — written by the panel's DNS module (re-run to refresh).
launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-user=pdns_user
gmysql-password={$databasePassword}
gmysql-dbname=pdns

local-address=0.0.0.0
local-port=53
master=yes
daemon=yes
guardian=yes

# HTTP API — used by the panel (localhost only).
webserver-address=127.0.0.1
webserver-allow-from=127.0.0.1
webserver-port=8081
api=yes
api-key={$apiKey}
CONF;
            @file_put_contents($path, $config."\n");
            @chmod($path, 0644);

            return;
        }

        $changed = false;

        if (preg_match('/^gmysql-password=.*$/m', $existing) === 1
            && ! str_contains($existing, 'gmysql-password='.$databasePassword)) {
            $existing = (string) preg_replace(
                '/^gmysql-password=.*$/m',
                'gmysql-password='.$databasePassword,
                $existing,
                1,
            );
            $changed = true;
        }

        if (preg_match('/^api-key=.*$/m', $existing) === 1) {
            if (! str_contains($existing, 'api-key='.$apiKey)) {
                $existing = (string) preg_replace('/^api-key=.*$/m', 'api-key='.$apiKey, $existing, 1);
                $changed = true;
            }
        } else {
            $existing .= "\n".<<<CONF

# HTTP API — appended by the panel's DNS module.
webserver-address=127.0.0.1
webserver-allow-from=127.0.0.1
webserver-port=8081
api=yes
api-key={$apiKey}
CONF;
            $changed = true;
        }

        if ($changed) {
            $log("Updating PowerDNS configuration in {$path}...");
            @file_put_contents($path, $existing);
            @chmod($path, 0644);
        }
    }

    private function store(string $databasePassword, string $apiKey): void
    {
        SecretsFile::set('PDNS_DB_PASSWORD', $databasePassword);
        SecretsFile::set('PDNS_API_KEY', $apiKey);
        EnvFile::set('PDNS_API_KEY', $apiKey);

        config(['panel.pdns.key' => $apiKey]);
    }

    /**
     * @param  \Closure(string): void  $log
     */
    private function start(\Closure $log, string $apiKey): void
    {
        $log('Starting PowerDNS...');

        $this->command->run(['systemctl', 'enable', 'pdns'], 30.0);

        $restart = $this->command->run(['systemctl', 'restart', 'pdns'], 60.0);

        if ($restart['exit'] !== 0) {
            throw new RuntimeException('PowerDNS did not start.'.$this->detail($restart).' Check "systemctl status pdns".');
        }

        $url = rtrim((string) config('panel.pdns.url'), '/')
            .'/api/v1/servers/'.(string) config('panel.pdns.server');

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $answer = $this->command->run(
                ['curl', '-fsS', '--max-time', '2', '-H', 'X-API-Key: '.$apiKey, $url],
                10.0,
            );

            if ($answer['exit'] === 0) {
                $log('PowerDNS API is listening on 127.0.0.1:8081.');

                return;
            }

            sleep(1);
        }

        throw new RuntimeException('PowerDNS API did not answer on 127.0.0.1:8081 — see "journalctl -u pdns".');
    }

    /**
     * The zones every install starts with: the nameserver domain (with ns1/ns2
     * glue) and the panel domain itself.
     *
     * @param  \Closure(string): void  $log
     */
    private function seedZones(\Closure $log): void
    {
        $ip = trim((string) config('panel.pdns.server_ip'));

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $log('Server IP unknown — skipping the initial DNS zones.');

            return;
        }

        $nameserver = $this->display(
            (string) (config('panel.pdns.ns_domain') ?: config('panel.domain'))
        );

        $zones = array_unique(array_filter(
            [$nameserver, $this->display((string) config('panel.domain'))],
            static fn (string $zone): bool => $zone !== '',
        ));

        foreach ($zones as $zone) {
            try {
                $this->dns->createZone($zone, $ip, true);
                $log("Created DNS zone {$zone}.");
            } catch (InvalidDnsRecord $exception) {
                $log('DNS zone '.$zone.': '.implode(' ', $exception->errors));

                continue;
            } catch (PowerDnsError $exception) {
                $log('DNS zone '.$zone.': '.$exception->getMessage());

                continue;
            }

            if ($zone !== $nameserver) {
                continue;
            }

            foreach (['ns1', 'ns2'] as $label) {
                try {
                    $this->dns->addRecord($nameserver, [
                        'name' => "{$label}.{$nameserver}",
                        'type' => 'A',
                        'content' => $ip,
                        'ttl' => 3600,
                    ]);
                } catch (InvalidDnsRecord|PowerDnsError $exception) {
                    $log("Glue record {$label}.{$nameserver}: ".$exception->getMessage());
                }
            }
        }
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runSql(string $sql, ?string $database = null): array
    {
        $command = ['mysql', '--protocol=socket', '-uroot', '-N', '-B'];

        if ($database !== null) {
            $command[] = $database;
        }

        return $this->command->run(
            [...$command, '-e', $sql],
            60.0,
            ['MYSQL_PWD' => (string) config('panel.mysql.root_password', '')],
        );
    }

    private function display(string $zone): string
    {
        return rtrim(strtolower(trim($zone)), '.');
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function detail(array $result): string
    {
        $detail = trim($result['stderr'].$result['stdout']);
        $detail = (string) preg_replace('/\s+/', ' ', $detail);

        return $detail !== ''
            ? ' '.mb_substr($detail, 0, 300)
            : " (the command exited with code {$result['exit']})";
    }
}
