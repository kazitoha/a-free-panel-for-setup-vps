<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use App\Support\MySql;
use App\Support\SecretInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:setup:finish
    {--json-stdin : Read the setup payload from standard input (never the process table)}')]
#[Description('Apply the credentials collected on /first-run (runs as root via the panel runner).')]
class PanelSetupFinish extends Command
{
    /**
     * PowerDNS schema — identical to what install-panel.sh writes, so a run
     * that was stopped before the DNS module stays idempotent.
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
        private readonly SecretInput $secrets,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('json-stdin')) {
            $this->error('Provide the setup payload on standard input with --json-stdin.');

            return self::FAILURE;
        }

        $payload = json_decode($this->secrets->read(), true);

        if (! is_array($payload)) {
            $this->error('The setup payload is not valid JSON.');

            return self::FAILURE;
        }

        $password = (string) ($payload['mysql_password'] ?? '');

        if ($password === '') {
            $this->error('No MySQL root password was provided.');

            return self::FAILURE;
        }

        if (str_contains($password, "\n") || str_contains($password, "\r")) {
            $this->error('The MySQL root password must not contain line breaks.');

            return self::FAILURE;
        }

        if (! $this->unlockMysql($password)) {
            return self::FAILURE;
        }

        if (! $this->writeEnvPassword($password)) {
            return self::FAILURE;
        }

        if (! $this->seedPowerDns($password)) {
            return self::FAILURE;
        }

        $this->recordSecrets($payload, $password);

        $this->info('MySQL root password recorded in '.config('panel.setup.env_file').' and '.config('panel.secrets_file').'.');

        return self::SUCCESS;
    }

    /**
     * Confirm the password MySQL already has, or set it when the server still
     * accepts a plain local root session (auth_socket on a fresh install).
     *
     * A TCP probe is what proves a password: over the socket a fresh server
     * lets any local root connection in whatever MYSQL_PWD says.
     */
    private function unlockMysql(string $password): bool
    {
        if ($this->probe($password, true)['exit'] === 0) {
            $this->info('MySQL root password matches the one you entered.');

            return true;
        }

        $session = $this->probe($password, false);

        if ($session['exit'] !== 0) {
            if ($this->couldNotConnect($session)) {
                $this->error('MySQL is not running — start it with "systemctl start mysql" and submit again.');
            } else {
                $this->error('MySQL rejected this root password: '.$this->detail($session));
            }

            return false;
        }

        $set = $this->runSql(
            "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY "
            .MySql::literal($password).";\nFLUSH PRIVILEGES;",
            $password,
        );

        if ($set['exit'] !== 0) {
            $this->error('Could not set the MySQL root password: '.$this->detail($set));

            return false;
        }

        if ($this->probe($password, true)['exit'] === 0) {
            $this->info('MySQL root password set.');

            return true;
        }

        if ($this->probe($password, false)['exit'] === 0) {
            $this->warn('MySQL accepted the password on the socket, but it could not be verified over TCP (127.0.0.1:3306).');

            return true;
        }

        $this->error('MySQL did not accept the new root password.');

        return false;
    }

    /**
     * True when MySQL could not be reached at all.
     *
     * MySql::unavailable() also counts "access denied" as unavailable, which
     * is right when the panel checks its own stored credentials but would
     * misreport a mistyped root password as a stopped server here.
     */
    private function couldNotConnect(array $result): bool
    {
        $combined = strtolower($result['stderr'].$result['stdout']);

        return str_contains($combined, "can't connect")
            || str_contains($combined, 'cannot connect')
            || str_contains($combined, 'connection refused')
            || str_contains($combined, 'no such file or directory')
            || str_contains($combined, 'unknown mysql server host');
    }

    /**
     * Record the password in .env, where the panel's privileged commands read
     * it from (config('panel.mysql.root_password')). Written in place so the
     * file keeps its root:www-data ownership and mode.
     */
    private function writeEnvPassword(string $password): bool
    {
        $path = (string) config('panel.setup.env_file');

        if ($path === '') {
            $this->error('No .env path is configured (panel.setup.env_file).');

            return false;
        }

        if (! is_file($path)) {
            $this->error("The panel .env was not found at {$path} — re-run install-panel.sh first.");

            return false;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->error("Could not read {$path}.");

            return false;
        }

        $line = 'MYSQL_ROOT_PASSWORD='.$this->envValue($password);

        if (preg_match('/^MYSQL_ROOT_PASSWORD=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace_callback(
                '/^MYSQL_ROOT_PASSWORD=.*$/m',
                fn (): string => $line,
                $contents,
                1,
            );
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        $temporary = $path.'.setup';

        if (@file_put_contents($temporary, $contents) === false) {
            $this->error("Could not write {$temporary}.");

            return false;
        }

        $copied = @copy($temporary, $path);
        @unlink($temporary);

        if (! $copied) {
            $this->error("Could not update {$path}.");

            return false;
        }

        return true;
    }

    /**
     * Quote a value for .env: single quotes are passed through untouched by
     * the parser, values holding one fall back to escaped double quotes.
     */
    private function envValue(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /**
     * Create the PowerDNS database, user and schema when they are missing —
     * the step an installer stopped at MySQL never reached.
     */
    private function seedPowerDns(string $password): bool
    {
        $check = $this->runSql(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'pdns'",
            $password,
        );

        if ($check['exit'] !== 0) {
            $this->error('Could not inspect the PowerDNS database: '.$this->detail($check));

            return false;
        }

        if (trim($check['stdout']) !== '') {
            $this->info('PowerDNS database already exists.');

            return true;
        }

        $secrets = $this->readSecrets();
        $databasePassword = $secrets['PDNS_DB_PASSWORD'] ?? bin2hex(random_bytes(16));
        $apiKey = $secrets['PDNS_API_KEY'] ?? bin2hex(random_bytes(24));

        $create = $this->runSql(
            "CREATE DATABASE IF NOT EXISTS pdns;\n"
            ."CREATE USER IF NOT EXISTS 'pdns_user'@'localhost' IDENTIFIED BY ".MySql::literal($databasePassword).";\n"
            ."ALTER USER 'pdns_user'@'localhost' IDENTIFIED BY ".MySql::literal($databasePassword).";\n"
            ."GRANT ALL PRIVILEGES ON pdns.* TO 'pdns_user'@'localhost';\n"
            .'FLUSH PRIVILEGES;',
            $password,
        );

        if ($create['exit'] !== 0) {
            $this->error('Could not create the PowerDNS database: '.$this->detail($create));

            return false;
        }

        $schema = $this->runSql((string) self::PDNS_SCHEMA, $password, 'pdns');

        if ($schema['exit'] !== 0) {
            $this->error('Could not create the PowerDNS schema: '.$this->detail($schema));

            return false;
        }

        foreach (['PDNS_DB_PASSWORD' => $databasePassword, 'PDNS_API_KEY' => $apiKey] as $key => $value) {
            $this->upsertSecret($key, $value);
        }

        $this->writePowerDnsConfig($databasePassword, $apiKey);

        $restart = $this->command->run(['systemctl', 'restart', 'pdns'], 30.0);

        if ($restart['exit'] !== 0) {
            $this->warn('PowerDNS did not restart cleanly — check "systemctl status pdns".');

            return true;
        }

        $this->info('PowerDNS database created and the service restarted.');

        return true;
    }

    /**
     * Ensure pdns.conf has the gmysql block and the localhost HTTP API.
     */
    private function writePowerDnsConfig(string $databasePassword, string $apiKey): void
    {
        $path = (string) config('panel.pdns.conf_file');
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';

        if ($existing === '') {
            $config = <<<CONF
# PowerDNS — written by panel:setup:finish.
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

        if (preg_match('/^[[:space:]]*api[[:space:]]*=/m', $existing) !== 1) {
            @file_put_contents($path, $existing."\n\n".<<<CONF
# HTTP API — appended by panel:setup:finish.
webserver-address=127.0.0.1
webserver-allow-from=127.0.0.1
webserver-port=8081
api=yes
api-key={$apiKey}
CONF."\n", FILE_APPEND);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSecrets(array $payload, string $password): void
    {
        $this->upsertSecret('MYSQL_ROOT_PASSWORD', $password);

        $email = trim((string) ($payload['admin_email'] ?? ''));
        $adminPassword = (string) ($payload['admin_password'] ?? '');

        if ($email !== '') {
            $this->upsertSecret('PANEL_ADMIN_EMAIL', $email);
        }

        if ($adminPassword !== '') {
            $this->upsertSecret('PANEL_ADMIN_PASSWORD', $adminPassword);
        }
    }

    private function upsertSecret(string $key, string $value): void
    {
        $path = (string) config('panel.secrets_file');

        if ($path === '') {
            return;
        }

        $contents = is_file($path) ? (string) @file_get_contents($path) : '';
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace_callback(
                '/^'.preg_quote($key, '/').'=.*$/m',
                fn (): string => $line,
                $contents,
                1,
            );
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        if (@file_put_contents($path, ltrim($contents)) === false) {
            $this->warn("Could not update {$path}.");

            return;
        }

        @chmod($path, 0600);
    }

    /**
     * @return array<string, string>
     */
    private function readSecrets(): array
    {
        $path = (string) config('panel.secrets_file');

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $secrets = [];

        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $secrets[trim($key)] = $value;
        }

        return $secrets;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function probe(string $password, bool $tcp): array
    {
        $server = $tcp ? ['--protocol=TCP', '-h127.0.0.1'] : ['--protocol=socket'];

        return $this->command->run(
            ['mysql', ...$server, '-uroot', '-N', '-B', '-e', 'SELECT 1;'],
            15.0,
            ['MYSQL_PWD' => $password],
        );
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runSql(string $sql, string $password, ?string $database = null): array
    {
        $command = ['mysql', '--protocol=socket', '-uroot', '-N', '-B'];

        if ($database !== null) {
            $command[] = $database;
        }

        return $this->command->run(
            [...$command, '-e', $sql],
            30.0,
            ['MYSQL_PWD' => $password],
        );
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function detail(array $result): string
    {
        $detail = trim($result['stderr'].$result['stdout']);
        $detail = (string) preg_replace('/\s+/', ' ', $detail);

        return $detail !== ''
            ? mb_substr($detail, 0, 300)
            : "the command exited with code {$result['exit']}";
    }
}
