<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Support\HostCommand;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * panel:mysql:install — the Databases module installing (and if needed
 * unlocking) MySQL for itself.
 */
class MysqlInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-mysql-install-'.uniqid();
        mkdir($this->dir, 0755, true);

        config([
            'panel.setup.env_file' => $this->dir.'/.env',
            'panel.secrets_file' => $this->dir.'/panel-secrets',
            'panel.mysql.recovery_file' => $this->dir.'/recovery.cnf',
            'panel.mysql.debian_cnf' => $this->dir.'/debian.cnf',
            'panel.mysql.root_password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_records_a_password_the_server_already_uses(): void
    {
        $this->writeEnv('known-pass');

        $state = $this->server(['tcpPassword' => 'known-pass']);

        $this->install($state)
            ->expectsOutputToContain('MySQL is already installed')
            ->expectsOutputToContain('The stored root password still works')
            ->expectsOutputToContain('MySQL is ready')
            ->assertSuccessful();

        $this->assertSame('known-pass', $this->parsedEnv('MYSQL_ROOT_PASSWORD'));
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD=known-pass', $this->secrets());
        $this->assertSame(0600, fileperms($this->dir.'/panel-secrets') & 0777);
        $this->assertFalse($state['altered']);
        $this->assertFalse($state['recovered']);
    }

    public function test_a_password_needing_quotes_survives_the_env_round_trip(): void
    {
        $password = "it's a p@ss \"word\" with \$home and #hash";
        $this->writeEnv('');
        file_put_contents($this->dir.'/panel-secrets', "MYSQL_ROOT_PASSWORD={$password}\n");

        $state = $this->server(['tcpPassword' => $password]);

        $this->install($state)->assertSuccessful();

        $this->assertSame($password, $this->parsedEnv('MYSQL_ROOT_PASSWORD'));
        $this->assertStringContainsString($password, $this->secrets());
    }

    public function test_it_installs_the_package_and_sets_a_password_on_a_fresh_server(): void
    {
        $this->writeEnv('');

        $state = $this->server(['installed' => false, 'socketAllowsEmpty' => true]);

        $this->install($state)
            ->expectsOutputToContain('Installing mysql-server')
            ->expectsOutputToContain('auth_socket')
            ->expectsOutputToContain('Root password set')
            ->assertSuccessful();

        $this->assertContains(['apt-get', 'install', '-y', '-qq', 'mysql-server'], $state['commands']);
        $this->assertTrue($state['altered']);

        $password = $this->parsedEnv('MYSQL_ROOT_PASSWORD');
        $this->assertNotNull($password);
        $this->assertNotSame('', $password);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD='.$password, $this->secrets());
    }

    public function test_it_recovers_a_server_nobody_can_log_in_to(): void
    {
        $this->writeEnv('');

        $state = $this->server(['socketAllowsEmpty' => false]);

        $this->install($state)
            ->expectsOutputToContain('grant tables disabled')
            ->expectsOutputToContain('Root password set')
            ->assertSuccessful();

        $this->assertTrue($state['recovered']);
        $this->assertFileDoesNotExist($this->dir.'/recovery.cnf');

        $password = $this->parsedEnv('MYSQL_ROOT_PASSWORD');
        $this->assertNotNull($password);
        $this->assertNotSame('', $password);
    }

    public function test_it_fails_loudly_when_the_password_cannot_be_written(): void
    {
        $this->writeEnv('');

        $state = $this->server(['socketAllowsEmpty' => false, 'sqlAlwaysFails' => true]);

        $this->install($state)
            ->expectsOutputToContain('Could not write a new MySQL root password')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->dir.'/recovery.cnf');
        $this->assertSame('', $this->parsedEnv('MYSQL_ROOT_PASSWORD') ?? '');
    }

    public function test_an_unknown_install_record_is_refused(): void
    {
        $install = Install::create([
            'type' => 'pdns',
            'label' => 'Install PowerDNS',
            'command' => 'panel:pdns:install',
            'status' => Install::STATUS_PENDING,
        ]);

        $this->artisan('panel:mysql:install', ['id' => $install->id])
            ->expectsOutputToContain('Install record not found')
            ->assertFailed();
    }

    private function writeEnv(string $password): void
    {
        file_put_contents($this->dir.'/.env', "APP_NAME=Panel\nMYSQL_ROOT_PASSWORD='{$password}'\n");
    }

    private function env(): string
    {
        return (string) file_get_contents($this->dir.'/.env');
    }

    private function secrets(): string
    {
        return is_file($this->dir.'/panel-secrets')
            ? (string) file_get_contents($this->dir.'/panel-secrets')
            : '';
    }

    private function parsedEnv(string $key): ?string
    {
        return Dotenv::createMutable($this->dir, '.env')->load()[$key] ?? null;
    }

    /**
     * A scriptable stand-in for the host: every flag below is one situation
     * MySqlSetup has to recognise.
     *
     * @param  array{
     *     installed?: bool,
     *     tcpPassword?: string|null,
     *     socketAllowsEmpty?: bool,
     *     sqlAlwaysFails?: bool,
     * }  $options
     * @return array{
     *     installed: bool,
     *     tcpPassword: string|null,
     *     socketAllowsEmpty: bool,
     *     sqlAlwaysFails: bool,
     *     altered: bool,
     *     recovered: bool,
     *     commands: list<list<string>>,
     * }
     */
    private function server(array $options): array
    {
        return [
            'installed' => $options['installed'] ?? true,
            'tcpPassword' => $options['tcpPassword'] ?? null,
            'socketAllowsEmpty' => $options['socketAllowsEmpty'] ?? false,
            'sqlAlwaysFails' => $options['sqlAlwaysFails'] ?? false,
            'altered' => false,
            'recovered' => false,
            'commands' => [],
        ];
    }

    private function install(array &$state): PendingCommand
    {
        $install = Install::create([
            'type' => 'mysql',
            'label' => 'Install MySQL',
            'command' => 'panel:mysql:install',
            'status' => Install::STATUS_PENDING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$state): void {
            $mock->shouldReceive('run')->andReturnUsing(
                function (array $command, float $timeout = 60.0, ?array $env = null, ?string $cwd = null, ?string $input = null) use (&$state): array {
                    $state['commands'][] = array_values(array_map('strval', $command));

                    return $this->answer($state, $command, $env);
                },
            );
        });

        return $this->artisan('panel:mysql:install', ['id' => $install->id]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function answer(array &$state, array $command, ?array $env): array
    {
        $ok = ['exit' => 0, 'stdout' => '1', 'stderr' => ''];
        $denied = ['exit' => 1, 'stdout' => '', 'stderr' => "ERROR 1045 (28000): Access denied for user 'root'@'localhost'"];

        return match ($command[0]) {
            'dpkg' => $state['installed']
                ? $ok
                : ['exit' => 1, 'stdout' => '', 'stderr' => 'dpkg-query: package mysql-server is not installed'],
            'apt-get', 'systemctl', 'ln', 'curl', 'test' => $ok,
            'mysql' => $this->answerMysql($state, $command, $env, $ok, $denied),
            default => $ok,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     * @param  array{exit: int, stdout: string, stderr: string}  $ok
     * @param  array{exit: int, stdout: string, stderr: string}  $denied
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function answerMysql(array &$state, array $command, ?array $env, array $ok, array $denied): array
    {
        $position = array_search('-e', $command, true);
        $sql = $position === false ? '' : (string) $command[$position + 1];

        if (str_starts_with($sql, 'SELECT 1')) {
            $password = $env['MYSQL_PWD'] ?? '';
            $tcp = in_array('--protocol=TCP', $command, true);

            if ($tcp) {
                return $state['recovered']
                    || $state['altered']
                    || ($state['tcpPassword'] !== null && $password === $state['tcpPassword'])
                    ? $ok
                    : $denied;
            }

            return $password === '' && $state['socketAllowsEmpty'] ? $ok : $denied;
        }

        if ($state['sqlAlwaysFails']) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 1396 (HY000): Operation ALTER USER failed'];
        }

        if (str_contains($sql, 'skip-grant-tables') || str_contains($sql, 'FLUSH PRIVILEGES')) {
            $state['recovered'] = true;
        }

        if (str_starts_with($sql, 'ALTER USER') || str_contains($sql, 'ALTER USER')) {
            $state['altered'] = true;
        }

        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
}
