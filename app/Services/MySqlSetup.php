<?php

namespace App\Services;

use App\Support\EnvFile;
use App\Support\HostCommand;
use App\Support\MySql;
use App\Support\SecretsFile;
use RuntimeException;

/**
 * Installs MySQL and makes the root account usable by the panel.
 *
 * Everything here runs as root inside a panel:* command — the web user has no
 * MySQL access of its own. Three situations are handled, in order:
 *
 *  1. a password from .env or /root/.panel-secrets still works → done;
 *  2. a local session opens (fresh server: auth_socket, or debian-sys-maint)
 *     → the panel password is written from that session;
 *  3. neither → MySQL is restarted once with its grant tables disabled, the
 *     password is written, and normal authentication is restored.
 *
 * The chosen password ends up in .env and /root/.panel-secrets.
 */
class MySqlSetup
{
    public const string SOCKET = '/run/mysqld/mysqld.sock';

    public function __construct(private readonly HostCommand $command) {}

    /**
     * Make MySQL reachable as root over 127.0.0.1:3306 and return the working
     * password, storing it in .env and the secrets file.
     *
     * @param  \Closure(string): void  $log
     */
    public function ensure(\Closure $log): string
    {
        if ($this->serverInstalled()) {
            $log('MySQL is already installed — checking the root password.');
        } else {
            $log('Installing mysql-server...');
            $this->installPackage();
            $log('MySQL server installed.');
        }

        $this->waitForSocket($log);

        foreach ($this->knownPasswords() as $known) {
            if ($this->tcpWorks($known)) {
                $log('The stored root password still works — nothing to change.');

                return $this->store($known);
            }
        }

        $desired = $this->desiredPassword();
        $session = $this->openSession();

        if ($session !== null) {
            $log($session === ''
                ? 'MySQL accepts passwordless local sessions (auth_socket) — setting the password the panel will use.'
                : 'Local session opened — setting the password the panel will use.');

            $this->changePassword($session, $desired);

            if ($this->tcpWorks($desired)) {
                $log('Root password set.');

                return $this->store($desired);
            }

            $log('That did not take — unlocking MySQL another way.');
        }

        $log('The root password is unknown here: restarting MySQL with grant tables disabled to set a fresh one.');
        $this->recover($desired);

        if (! $this->tcpWorks($desired)) {
            throw new RuntimeException(
                'MySQL restarted but the panel could not set the root password. '
                .'Check /var/log/mysql/error.log, then run the Databases install again.'
            );
        }

        $log('Root password set.');

        return $this->store($desired);
    }

    private function serverInstalled(): bool
    {
        return $this->command->run(['dpkg', '-s', 'mysql-server'], 20.0)['exit'] === 0;
    }

    private function installPackage(): void
    {
        $this->command->run(['apt-get', 'update', '-qq'], 300.0);

        $result = $this->command->run(
            ['apt-get', 'install', '-y', '-qq', 'mysql-server'],
            900.0,
            ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        if ($result['exit'] !== 0) {
            throw new RuntimeException(
                'Could not install mysql-server.'
                .$this->detail($result)
            );
        }
    }

    /**
     * @param  \Closure(string): void  $log
     */
    private function waitForSocket(\Closure $log): void
    {
        for ($attempt = 0; $attempt < 45; $attempt++) {
            if ($this->socketReady()) {
                return;
            }

            if ($attempt === 0) {
                $log('Waiting for the MySQL socket...');
            }

            sleep(1);
        }

        if (! $this->socketReady()) {
            throw new RuntimeException('MySQL did not open '.self::SOCKET.' — see /var/log/mysql/error.log.');
        }
    }

    private function socketReady(): bool
    {
        return $this->command->run(['test', '-S', self::SOCKET], 5.0)['exit'] === 0;
    }

    /**
     * Passwords the panel has been told about: .env first, then the secrets file.
     *
     * @return list<string>
     */
    private function knownPasswords(): array
    {
        $passwords = [
            EnvFile::value('MYSQL_ROOT_PASSWORD'),
            SecretsFile::get('MYSQL_ROOT_PASSWORD'),
        ];

        return array_values(array_unique(array_filter(
            $passwords,
            static fn (?string $password): bool => $password !== null && $password !== '',
        )));
    }

    /**
     * The password the panel should end up using: whatever it already knows,
     * or a freshly generated one.
     */
    private function desiredPassword(): string
    {
        foreach ($this->knownPasswords() as $known) {
            return $known;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * First local session that opens — a remembered password, an empty one
     * (auth_socket) or the Debian maintenance account — otherwise null.
     */
    private function openSession(): ?string
    {
        $candidates = [...$this->knownPasswords(), ''];

        if (($debian = $this->debianPassword()) !== null) {
            $candidates[] = $debian;
        }

        foreach ($candidates as $candidate) {
            if ($this->socketWorks($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function changePassword(?string $sessionPassword, string $newPassword): void
    {
        $sql = "ALTER USER 'root'@'localhost' IDENTIFIED BY ".MySql::literal($newPassword).';';
        $result = $this->runSql($sql, $sessionPassword);

        if ($result['exit'] !== 0) {
            $result = $this->runSql('FLUSH PRIVILEGES; '.$sql, $sessionPassword);
        }

        if ($result['exit'] !== 0) {
            throw new RuntimeException('Could not change the MySQL root password.'.$this->detail($result));
        }
    }

    /**
     * Last resort: restart with skip-grant-tables, write the password with the
     * grant tables briefly reloaded, then restore normal authentication.
     */
    private function recover(string $newPassword): void
    {
        $config = $this->recoveryFile();

        if (! @mkdir(dirname($config), 0755, true) && ! is_dir(dirname($config))) {
            throw new RuntimeException('Could not write '.dirname($config).'.');
        }

        if (@file_put_contents($config, "[mysqld]\nskip-grant-tables\n") === false) {
            throw new RuntimeException('Could not write '.$config.'.');
        }

        $this->command->run(['systemctl', 'restart', 'mysql'], 180.0);
        $this->waitForSocket(static fn () => null);

        $sql = "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY ".MySql::literal($newPassword).';';
        $result = $this->runSql($sql, null);

        @unlink($config);

        $this->command->run(['systemctl', 'restart', 'mysql'], 180.0);
        $this->waitForSocket(static fn () => null);

        if ($result['exit'] !== 0) {
            throw new RuntimeException('Could not write a new MySQL root password.'.$this->detail($result));
        }
    }

    /**
     * Password from /etc/mysql/debian.cnf, when that account still exists.
     */
    private function debianPassword(): ?string
    {
        $path = $this->debianCnf();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        if (preg_match('/^\s*password\s*=\s*(\S.*)$/m', $contents, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    private function tcpWorks(?string $password): bool
    {
        if ($password === null || $password === '') {
            return false;
        }

        $result = $this->command->run(
            ['mysql', '--protocol=TCP', '-h127.0.0.1', '--connect-timeout=5', '-uroot', '-N', '-B', '-e', 'SELECT 1;'],
            15.0,
            ['MYSQL_PWD' => $password],
        );

        return $result['exit'] === 0;
    }

    private function socketWorks(?string $password): bool
    {
        $result = $this->command->run(
            ['mysql', '--protocol=socket', '--connect-timeout=5', '-uroot', '-N', '-B', '-e', 'SELECT 1;'],
            15.0,
            $password === null || $password === '' ? null : ['MYSQL_PWD' => $password],
        );

        return $result['exit'] === 0;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runSql(string $sql, ?string $password): array
    {
        return $this->command->run(
            ['mysql', '--protocol=socket', '-uroot', '-e', $sql],
            30.0,
            $password === null || $password === '' ? null : ['MYSQL_PWD' => $password],
        );
    }

    private function recoveryFile(): string
    {
        $path = config('panel.mysql.recovery_file');

        return is_string($path) ? $path : '/etc/mysql/conf.d/panel-recovery.cnf';
    }

    private function debianCnf(): string
    {
        $path = config('panel.mysql.debian_cnf');

        return is_string($path) ? $path : '/etc/mysql/debian.cnf';
    }

    private function store(string $password): string
    {
        EnvFile::set('MYSQL_ROOT_PASSWORD', $password);
        SecretsFile::set('MYSQL_ROOT_PASSWORD', $password);
        config(['panel.mysql.root_password' => $password]);

        return $password;
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function detail(array $result): string
    {
        $detail = trim($result['stderr'].$result['stdout']);

        return $detail !== '' ? ' '.mb_substr($detail, 0, 400) : '';
    }
}
