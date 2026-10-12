<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use Mockery\MockInterface;
use Tests\TestCase;

class PdnsUninstallTest extends TestCase
{
    /**
     * @var list<list<string>>
     */
    private array $commands = [];

    public function test_it_cleans_up_leftovers_when_powerdns_is_not_installed(): void
    {
        $this->fakeHost([]);

        $this->artisan('panel:pdns:uninstall')
            ->expectsOutputToContain('PowerDNS is not installed')
            ->assertSuccessful();

        $this->assertFalse($this->ran('systemctl', 'disable', '--now', 'pdns'));
    }

    public function test_it_stops_purges_and_deletes_powerdns(): void
    {
        $this->fakeHost(['pdns-server', 'pdns-backend-mysql']);

        $this->artisan('panel:pdns:uninstall')
            ->expectsOutputToContain('PowerDNS removed')
            ->assertSuccessful();

        $this->assertTrue($this->ran('systemctl', 'disable', '--now', 'pdns'));

        $purge = $this->find('apt-get', 'purge');
        $this->assertNotNull($purge);
        $this->assertContains('pdns-server', $purge);
        $this->assertContains('pdns-backend-mysql', $purge);
    }

    /**
     * @param  list<string>  $installed
     */
    private function fakeHost(array $installed): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($installed): void {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($installed): array {
                $this->commands[] = array_values(array_map('strval', $command));

                if (($command[0] ?? '') === 'dpkg' && ($command[1] ?? '') === '-s') {
                    return in_array($command[2] ?? '', $installed, true)
                        ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
                        : ['exit' => 1, 'stdout' => '', 'stderr' => ''];
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
