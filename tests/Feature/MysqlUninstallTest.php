<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use Mockery\MockInterface;
use Tests\TestCase;

class MysqlUninstallTest extends TestCase
{
    /**
     * @var list<list<string>>
     */
    private array $commands = [];

    public function test_it_cleans_up_leftovers_when_mysql_is_not_installed(): void
    {
        $this->fakeHost([]);

        $this->artisan('panel:mysql:uninstall')
            ->expectsOutputToContain('MySQL is not installed')
            ->assertSuccessful();

        $this->assertFalse($this->ran('systemctl', 'disable', '--now', 'mysql'));
        $this->assertFalse($this->ran('apt-get', 'purge'));
    }

    public function test_it_stops_purges_and_deletes_mysql(): void
    {
        $this->fakeHost(['mysql-server', 'mysql-client', 'mysql-common']);

        $this->artisan('panel:mysql:uninstall')
            ->expectsOutputToContain('MySQL removed')
            ->assertSuccessful();

        $this->assertTrue($this->ran('systemctl', 'disable', '--now', 'mysql'));

        $purge = $this->find('apt-get', 'purge');
        $this->assertNotNull($purge);
        $this->assertContains('mysql-server', $purge);
        $this->assertContains('mysql-common', $purge);
        $this->assertNotContains('mysql-server-8.0', $purge);
    }

    public function test_a_failed_purge_is_reported(): void
    {
        $this->fakeHost(['mysql-server'], purgeFails: true);

        $this->artisan('panel:mysql:uninstall')
            ->expectsOutputToContain('Could not remove the MySQL packages')
            ->assertFailed();
    }

    /**
     * @param  list<string>  $installed
     */
    private function fakeHost(array $installed, bool $purgeFails = false): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($installed, $purgeFails): void {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($installed, $purgeFails): array {
                $this->commands[] = array_values(array_map('strval', $command));

                if (($command[0] ?? '') === 'dpkg' && ($command[1] ?? '') === '-s') {
                    return in_array($command[2] ?? '', $installed, true)
                        ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
                        : ['exit' => 1, 'stdout' => '', 'stderr' => ''];
                }

                if ($purgeFails && ($command[0] ?? '') === 'apt-get' && ($command[1] ?? '') === 'purge') {
                    return ['exit' => 100, 'stdout' => '', 'stderr' => 'E: purge failed'];
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            });
        });
    }

    private function ran(string ...$prefix): bool
    {
        $prefix = array_values($prefix);

        foreach ($this->commands as $command) {
            if (array_slice($command, 0, count($prefix)) === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    private function find(string $first, string $second): ?array
    {
        foreach ($this->commands as $command) {
            if (($command[0] ?? '') === $first && ($command[1] ?? '') === $second) {
                return $command;
            }
        }

        return null;
    }
}
