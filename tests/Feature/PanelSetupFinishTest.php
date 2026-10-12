<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use App\Support\SecretInput;
use Dotenv\Dotenv;
use Illuminate\Testing\PendingCommand;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelSetupFinishTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-setup-finish-'.uniqid();
        mkdir($this->dir, 0755, true);

        config([
            'panel.setup.env_file' => $this->dir.'/.env',
            'panel.secrets_file' => $this->dir.'/panel-secrets',
            'panel.pdns.conf_file' => $this->dir.'/pdns.conf',
        ]);

        file_put_contents($this->dir.'/.env', $this->initialEnv());
        file_put_contents($this->dir.'/panel-secrets', implode("\n", [
            'PDNS_DB_PASSWORD=db-password-from-installer',
            'PDNS_API_KEY=api-key-from-installer',
            '',
        ]));
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
        $this->finish(['mysql_password' => 'new-password'], $this->passwordMatchesAndPdnsExists())
            ->expectsOutputToContain('MySQL root password matches the one you entered.')
            ->expectsOutputToContain('PowerDNS database already exists.')
            ->assertSuccessful();

        $this->assertSame('new-password', $this->parsedEnv('MYSQL_ROOT_PASSWORD'));
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD=new-password', $this->secrets());
        $this->assertStringNotContainsString('old-password', $this->env());
        $this->assertFileDoesNotExist($this->dir.'/pdns.conf');
    }

    public function test_it_sets_the_password_while_the_server_still_uses_auth_socket(): void
    {
        $this->finish(['mysql_password' => 'brand-new'], $this->serverStillUnlocked())
            ->expectsOutputToContain('MySQL root password set.')
            ->expectsOutputToContain('PowerDNS database created')
            ->assertSuccessful();

        $this->assertStringContainsString("MYSQL_ROOT_PASSWORD='brand-new'", $this->env());
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD=brand-new', $this->secrets());

        $config = $this->pdnsConfig();
        $this->assertStringContainsString('launch=gmysql', $config);
        $this->assertStringContainsString('gmysql-password=db-password-from-installer', $config);
        $this->assertStringContainsString('api-key=api-key-from-installer', $config);
        $this->assertStringContainsString('api=yes', $config);
    }

    public function test_it_rejects_a_password_that_is_wrong(): void
    {
        $this->finish(['mysql_password' => 'wrong-password'], $this->serverRefusesThePassword())
            ->expectsOutputToContain('MySQL rejected this root password')
            ->assertFailed();

        $this->assertSame($this->initialEnv(), $this->env());
        $this->assertFileDoesNotExist($this->dir.'/pdns.conf');
    }

    public function test_it_reports_a_stopped_server_as_stopped(): void
    {
        $this->finish(['mysql_password' => 'anything'], $this->serverIsDown())
            ->expectsOutputToContain('MySQL is not running')
            ->assertFailed();

        $this->assertSame($this->initialEnv(), $this->env());
    }

    public function test_it_stores_the_administrator_credentials_as_well(): void
    {
        $this->finish([
            'mysql_password' => 'new-password',
            'admin_email' => 'Admin@Example.com',
            'admin_password' => 'super-secret-123',
        ], $this->passwordMatchesAndPdnsExists())->assertSuccessful();

        $this->assertStringContainsString('PANEL_ADMIN_EMAIL=Admin@Example.com', $this->secrets());
        $this->assertStringContainsString('PANEL_ADMIN_PASSWORD=super-secret-123', $this->secrets());
        $this->assertSame(0600, fileperms($this->dir.'/panel-secrets') & 0777);
    }

    public function test_it_appends_the_password_when_the_env_has_no_mysql_line(): void
    {
        file_put_contents($this->dir.'/.env', "APP_NAME=Panel\n");

        $this->finish(['mysql_password' => 'new-password'], $this->passwordMatchesAndPdnsExists())
            ->assertSuccessful();

        $this->assertSame('new-password', $this->parsedEnv('MYSQL_ROOT_PASSWORD'));
    }

    public function test_a_password_needing_quotes_survives_the_env_round_trip(): void
    {
        $password = "it's a p@ss \"word\" with \$home and #hash";

        $this->finish(['mysql_password' => $password], $this->passwordMatchesAndPdnsExists())
            ->assertSuccessful();

        $this->assertSame($password, $this->parsedEnv('MYSQL_ROOT_PASSWORD'));
    }

    public function test_it_requires_the_payload_on_standard_input(): void
    {
        $this->mock(SecretInput::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->never();
        });

        $this->artisan('panel:setup:finish')
            ->expectsOutputToContain('--json-stdin')
            ->assertFailed();
    }

    public function test_it_rejects_a_payload_that_is_not_json(): void
    {
        $this->mock(SecretInput::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->andReturn('mysql_password=plain');
        });

        $this->artisan('panel:setup:finish', ['--json-stdin' => true])
            ->expectsOutputToContain('not valid JSON')
            ->assertFailed();
    }

    public function test_it_rejects_a_password_spanning_more_than_one_line(): void
    {
        $this->finish(['mysql_password' => "line\nbreak"], $this->passwordMatchesAndPdnsExists())
            ->expectsOutputToContain('must not contain line breaks')
            ->assertFailed();

        $this->assertSame($this->initialEnv(), $this->env());
    }

    /**
     * The password matches and the PowerDNS database is already there.
     *
     * @return callable(string, bool): array{exit: int, stdout: string, stderr: string}
     */
    private function passwordMatchesAndPdnsExists(): callable
    {
        return function (string $sql, bool $tcp): array {
            if ($tcp && str_starts_with($sql, 'SELECT 1')) {
                return ['exit' => 0, 'stdout' => '1', 'stderr' => ''];
            }

            if (str_contains($sql, 'information_schema.SCHEMATA')) {
                return ['exit' => 0, 'stdout' => 'pdns', 'stderr' => ''];
            }

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        };
    }

    /**
     * A fresh server that still lets a local root session in over the socket
     * but has no password to match over TCP yet.
     *
     * @return callable(string, bool): array{exit: int, stdout: string, stderr: string}
     */
    private function serverStillUnlocked(): callable
    {
        $altered = false;

        return function (string $sql, bool $tcp) use (&$altered): array {
            if (str_starts_with($sql, 'SELECT 1')) {
                if ($altered || ! $tcp) {
                    return ['exit' => 0, 'stdout' => '1', 'stderr' => ''];
                }

                return [
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => "ERROR 1045 (28000): Access denied for user 'root'@'localhost'",
                ];
            }

            if (str_starts_with($sql, 'ALTER USER')) {
                $altered = true;

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            if (str_contains($sql, 'information_schema.SCHEMATA')) {
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        };
    }

    /**
     * The server is up but the password is wrong over both transports.
     *
     * @return callable(string, bool): array{exit: int, stdout: string, stderr: string}
     */
    private function serverRefusesThePassword(): callable
    {
        return function (string $sql, bool $tcp): array {
            if (str_starts_with($sql, 'SELECT 1')) {
                return [
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => "ERROR 1045 (28000): Access denied for user 'root'@'localhost'",
                ];
            }

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        };
    }

    /**
     * @return callable(string, bool): array{exit: int, stdout: string, stderr: string}
     */
    private function serverIsDown(): callable
    {
        return function (string $sql, bool $tcp): array {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => "ERROR 2002 (HY000): Can't connect to local MySQL server through socket '/var/run/mysqld/mysqld.sock' (2)",
            ];
        };
    }

    /**
     * Feed the command a payload and answer its host commands through the
     * given router, which sees the SQL text and whether it ran over TCP.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(string, bool): array{exit: int, stdout: string, stderr: string}  $router
     */
    private function finish(array $payload, callable $router): PendingCommand
    {
        $this->mock(SecretInput::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('read')->andReturn(json_encode($payload));
        });

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($router): void {
            $mock->shouldReceive('run')->andReturnUsing(
                function (array $command, float $timeout = 60.0, ?array $env = null, ?string $cwd = null, ?string $input = null) use ($router): array {
                    if (in_array('sudo', $command, true)) {
                        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                    }

                    $position = array_search('-e', $command, true);
                    $sql = $position === false ? '' : (string) $command[$position + 1];

                    return $router($sql, in_array('--protocol=TCP', $command, true));
                },
            );
        });

        return $this->artisan('panel:setup:finish', ['--json-stdin' => true]);
    }

    private function initialEnv(): string
    {
        return "APP_NAME=Panel\nMYSQL_ROOT_PASSWORD='old-password'\n";
    }

    private function parsedEnv(string $key): ?string
    {
        return Dotenv::createMutable($this->dir, '.env')->load()[$key] ?? null;
    }

    private function env(): string
    {
        return (string) file_get_contents($this->dir.'/.env');
    }

    private function secrets(): string
    {
        return (string) file_get_contents($this->dir.'/panel-secrets');
    }

    private function pdnsConfig(): string
    {
        return (string) file_get_contents($this->dir.'/pdns.conf');
    }
}
