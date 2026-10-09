<?php

namespace Tests\Unit;

use App\Services\SystemService;
use App\Support\HostCommand;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class SystemServiceTest extends TestCase
{
    public function test_parse_meminfo_reports_total_used_and_percent(): void
    {
        $memory = SystemService::parseMeminfo(
            "MemTotal:       8000000 kB\nMemFree:        2000000 kB\nMemAvailable:   4000000 kB\n"
        );

        $this->assertSame(7813, $memory['total_mb']);
        $this->assertSame(3906, $memory['used_mb']);
        $this->assertSame(50, $memory['percent']);
    }

    public function test_parse_loadavg_reads_the_three_windows(): void
    {
        $load = SystemService::parseLoadavg("0.42 0.31 0.22 1/512 99999\n");

        $this->assertSame(0.42, $load['load1']);
        $this->assertSame(0.31, $load['load5']);
        $this->assertSame(0.22, $load['load15']);
    }

    public function test_parse_uptime_converts_seconds_to_days_hours_minutes(): void
    {
        $uptime = SystemService::parseUptime('90061.32 400000.10');

        $this->assertSame(90061, $uptime['seconds']);
        $this->assertSame(1, $uptime['days']);
        $this->assertSame(1, $uptime['hours']);
        $this->assertSame(1, $uptime['minutes']);
    }

    public function test_parse_listening_ports_extracts_unique_sorted_ports(): void
    {
        $ssOutput = implode("\n", [
            'LISTEN 0 511 0.0.0.0:443 0.0.0.0:*',
            'LISTEN 0 511 0.0.0.0:80 0.0.0.0:*',
            'LISTEN 0 128 127.0.0.1:3306 0.0.0.0:*',
            'LISTEN 0 128 [::]:443 [::]:*',
        ]);

        $this->assertSame(['80', '443', '3306'], SystemService::parseListeningPorts($ssOutput));
    }

    public function test_parse_os_release_returns_pretty_name(): void
    {
        $os = SystemService::parseOsRelease("NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04.2 LTS\"\n");

        $this->assertSame('Ubuntu 24.04.2 LTS', $os);
    }

    public function test_service_states_only_reports_installed_units(): void
    {
        $service = new SystemService($this->fakeHostCommand());

        $states = $service->serviceStates();
        $units = array_column($states, 'unit');

        $this->assertContains('caddy', $units);
        $this->assertContains('mysql', $units);
        $this->assertNotContains('pdns', $units);
        $this->assertNotContains('stalwart', $units);

        $caddy = array_values(array_filter($states, fn (array $row) => $row['unit'] === 'caddy'))[0];
        $this->assertSame('active', $caddy['state']);
    }

    private function fakeHostCommand(): HostCommand
    {
        return Mockery::mock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager'])
                ->andReturn([
                    'exit' => 0,
                    'stdout' => "caddy.service                    enabled\nmysql.service                    enabled\nssh.service                      enabled\n",
                    'stderr' => '',
                ]);

            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => ($command[0] ?? null) === 'systemctl' && ($command[1] ?? null) === 'is-active')
                ->andReturnUsing(function (array $command) {
                    // Multi-unit call: one state line per unit, matching order.
                    if (count($command) > 3) {
                        $states = array_map(
                            fn (string $unit): string => $unit === 'caddy' ? 'active' : 'inactive',
                            array_slice($command, 2),
                        );

                        return ['exit' => 0, 'stdout' => implode("\n", $states)."\n", 'stderr' => ''];
                    }

                    return [
                        'exit' => 0,
                        'stdout' => (($command[2] ?? '') === 'caddy' ? 'active' : 'inactive')."\n",
                        'stderr' => '',
                    ];
                });

            $mock->shouldReceive('run')
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => ''])
                ->byDefault();
        });
    }
}
