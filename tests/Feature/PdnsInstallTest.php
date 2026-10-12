<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Support\HostCommand;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * panel:pdns:install — the DNS module installing PowerDNS (and MySQL first,
 * when it is missing) for itself.
 */
class PdnsInstallTest extends TestCase
{
    use RefreshDatabase;

    private const string API = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-pdns-install-'.uniqid();
        mkdir($this->dir, 0755, true);

        config([
            'panel.setup.env_file' => $this->dir.'/.env',
            'panel.secrets_file' => $this->dir.'/panel-secrets',
            'panel.pdns.conf_file' => $this->dir.'/pdns.conf',
            'panel.mysql.recovery_file' => $this->dir.'/recovery.cnf',
            'panel.mysql.debian_cnf' => $this->dir.'/debian.cnf',
            'panel.mysql.root_password' => 'root-pass',
            'panel.domain' => 'panel.example.com',
            'panel.pdns.ns_domain' => 'example.com',
            'panel.pdns.server_ip' => '203.0.113.10',
            'panel.pdns.url' => 'http://127.0.0.1:8081',
            'panel.pdns.key' => '',
        ]);

        file_put_contents($this->dir.'/.env', "APP_NAME=Panel\nMYSQL_ROOT_PASSWORD='root-pass'\nPDNS_API_KEY=\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_installs_the_stack_and_seeds_the_initial_zones(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones*' => Http::response([], 200),
        ]);

        $state = $this->server();

        $this->install($state)
            ->expectsOutputToContain('Installing pdns-server and the MySQL backend')
            ->expectsOutputToContain('Creating the PowerDNS database and user')
            ->expectsOutputToContain('PowerDNS API is listening on 127.0.0.1:8081')
            ->expectsOutputToContain('Created DNS zone example.com.')
            ->expectsOutputToContain('PowerDNS is ready')
            ->assertSuccessful();

        $this->assertContains(['apt-get', 'install', '-y', '-qq', 'pdns-server', 'pdns-backend-mysql'], $state['commands']);

        $config = $this->pdnsConfig();
        $this->assertStringContainsString('launch=gmysql', $config);
        $this->assertStringContainsString('gmysql-user=pdns_user', $config);
        $this->assertStringContainsString('api=yes', $config);

        $secrets = $this->secrets();
        $this->assertMatchesRegularExpression('/^PDNS_DB_PASSWORD=.+$/m', $secrets);
        $this->assertMatchesRegularExpression('/^PDNS_API_KEY=.+$/m', $secrets);

        preg_match('/^PDNS_API_KEY=(.+)$/m', $secrets, $match);
        $this->assertStringContainsString('api-key='.$match[1], $config);
        $this->assertSame($match[1], $this->parsedEnv('PDNS_API_KEY'));
        $this->assertSame($match[1], config('panel.pdns.key'));
    }

    public function test_it_reuses_the_existing_database_and_credentials(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones*' => Http::response([], 200),
        ]);

        file_put_contents($this->dir.'/panel-secrets', implode("\n", [
            'MYSQL_ROOT_PASSWORD=root-pass',
            'PDNS_DB_PASSWORD=db-password-from-installer',
            'PDNS_API_KEY=api-key-from-installer',
            '',
        ]));
        file_put_contents($this->dir.'/pdns.conf', implode("\n", [
            'launch=gmysql',
            'gmysql-host=127.0.0.1',
            'gmysql-port=3306',
            'gmysql-user=pdns_user',
            'gmysql-password=some-other-password',
            'gmysql-dbname=pdns',
            '',
        ]));

        $state = $this->server(['databaseExists' => true, 'pdnsInstalled' => true]);

        $this->install($state)
            ->expectsOutputToContain('PowerDNS is already installed')
            ->expectsOutputToContain('PowerDNS database already exists')
            ->expectsOutputToContain('PowerDNS is ready')
            ->assertSuccessful();

        $this->assertStringNotContainsString('CREATE DATABASE', $state['sql']);
        $this->assertStringContainsString('gmysql-password=db-password-from-installer', $this->pdnsConfig());
        $this->assertStringContainsString('api-key=api-key-from-installer', $this->pdnsConfig());
        $this->assertSame('api-key-from-installer', $this->parsedEnv('PDNS_API_KEY'));
        $this->assertStringContainsString('PDNS_API_KEY=api-key-from-installer', $this->secrets());
    }

    public function test_an_unknown_install_record_is_refused(): void
    {
        $install = Install::create([
            'type' => 'mysql',
            'label' => 'Install MySQL',
            'command' => 'panel:mysql:install',
            'status' => Install::STATUS_PENDING,
        ]);

        $this->artisan('panel:pdns:install', ['id' => $install->id])
            ->expectsOutputToContain('Install record not found')
            ->assertFailed();
    }

    /**
     * @param  array{databaseExists?: bool, pdnsInstalled?: bool}  $options
     * @return array{databaseExists: bool, pdnsInstalled: bool, commands: list<list<string>>, sql: string}
     */
    private function server(array $options = []): array
    {
        return [
            'databaseExists' => $options['databaseExists'] ?? false,
            'pdnsInstalled' => $options['pdnsInstalled'] ?? false,
            'commands' => [],
            'sql' => '',
        ];
    }

    private function createInstall(): int
    {
        return Install::create([
            'type' => 'pdns',
            'label' => 'Install PowerDNS',
            'command' => 'panel:pdns:install',
            'status' => Install::STATUS_PENDING,
        ])->id;
    }

    private function install(array &$state): PendingCommand
    {
        $id = $this->createInstall();
        $this->mockHost($state);

        return $this->artisan('panel:pdns:install', ['id' => $id]);
    }

    private function mockHost(array &$state): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$state): void {
            $mock->shouldReceive('run')->andReturnUsing(
                function (array $command, float $timeout = 60.0, ?array $env = null, ?string $cwd = null, ?string $input = null) use (&$state): array {
                    $state['commands'][] = array_values(array_map('strval', $command));

                    return $this->answer($state, $command, $env);
                },
            );
        });
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
        $empty = ['exit' => 0, 'stdout' => '', 'stderr' => ''];

        if ($command[0] === 'dpkg') {
            $package = (string) ($command[2] ?? '');

            if ($package === 'pdns-server') {
                return $state['pdnsInstalled']
                    ? $empty
                    : ['exit' => 1, 'stdout' => '', 'stderr' => 'dpkg-query: package pdns-server is not installed'];
            }

            return $empty;
        }

        if ($command[0] === 'systemctl' && in_array('is-active', $command, true)) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
        }

        if ($command[0] === 'mysql') {
            $position = array_search('-e', $command, true);
            $sql = $position === false ? '' : (string) $command[$position + 1];
            $state['sql'] .= $sql."\n";

            if (str_starts_with($sql, 'SELECT 1')) {
                $password = $env['MYSQL_PWD'] ?? '';
                $tcp = in_array('--protocol=TCP', $command, true);

                if ($tcp) {
                    return $password === 'root-pass'
                        ? $ok
                        : ['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 1045 (28000): Access denied'];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 1045 (28000): Access denied'];
            }

            if (str_contains($sql, 'information_schema.SCHEMATA')) {
                return $state['databaseExists']
                    ? ['exit' => 0, 'stdout' => "pdns\n", 'stderr' => '']
                    : $empty;
            }

            return $empty;
        }

        return match ($command[0]) {
            'apt-get', 'systemctl', 'ln', 'test', 'curl' => $ok,
            default => $empty,
        };
    }

    private function pdnsConfig(): string
    {
        return (string) file_get_contents($this->dir.'/pdns.conf');
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
}
