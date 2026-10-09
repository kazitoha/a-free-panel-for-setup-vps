<?php

namespace Tests\Unit;

use App\Services\SudoRunner;
use App\Services\UpdateService;
use App\Support\HostCommand;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateServiceTest extends TestCase
{
    private string $tmp;

    private string $dir;

    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-update-'.uniqid();
        $this->dir = $this->tmp.'/checkout';
        mkdir($this->dir.'/.git', 0755, true);

        $this->statusFile = $this->tmp.'/storage/update-status.json';

        config([
            'panel.update.dir' => $this->dir,
            'panel.update.status_file' => $this->statusFile,
            'panel.update.branch' => 'main',
            'panel.update.interval' => 3600,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function service(): UpdateService
    {
        return app(UpdateService::class);
    }

    public function test_status_defaults_when_nothing_has_been_checked(): void
    {
        $status = $this->service()->status();

        $this->assertFalse($status['update_available']);
        $this->assertSame(0, $status['behind']);
        $this->assertNull($status['checked_at']);
        $this->assertNull($status['error']);
        $this->assertSame('main', $status['branch']);
    }

    public function test_status_merges_the_stored_file(): void
    {
        mkdir(dirname($this->statusFile), 0755, true);
        file_put_contents($this->statusFile, json_encode([
            'update_available' => true,
            'behind' => 3,
            'current' => 'aaaa',
            'latest' => 'bbbb',
            'subject' => 'Fix the installer',
            'branch' => 'main',
            'checked_at' => '2026-10-09T10:00:00+00:00',
            'error' => null,
        ]));

        $status = $this->service()->status();

        $this->assertTrue($status['update_available']);
        $this->assertSame(3, $status['behind']);
        $this->assertSame('Fix the installer', $status['subject']);
        $this->assertSame('2026-10-09T10:00:00+00:00', $status['checked_at']);
        $this->assertFalse($status['denied']);
    }

    public function test_a_corrupt_status_file_falls_back_to_the_defaults(): void
    {
        mkdir(dirname($this->statusFile), 0755, true);
        file_put_contents($this->statusFile, 'not json');

        $status = $this->service()->status();

        $this->assertFalse($status['update_available']);
        $this->assertSame(0, $status['behind']);
    }

    public function test_the_status_is_stale_until_a_check_runs(): void
    {
        $this->assertTrue($this->service()->isStale());

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->andReturn(['exit' => 0, 'stdout' => "aaaa\n", 'stderr' => '']);
        });

        $this->service()->check();

        $this->assertFalse($this->service()->isStale());
    }

    public function test_an_old_check_is_stale_again(): void
    {
        mkdir(dirname($this->statusFile), 0755, true);
        file_put_contents($this->statusFile, json_encode([
            'checked_at' => now()->subSeconds(7200)->toIso8601String(),
        ]));

        $this->assertTrue($this->service()->isStale());
    }

    public function test_check_records_a_pending_update(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('fetch', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('HEAD', $command, true) && in_array('rev-parse', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "aaaa\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('refs/remotes/origin/main', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "bbbb\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('rev-list', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "2\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('log', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Fix the installer\n", 'stderr' => '']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $status = $this->service()->check();

        $this->assertTrue($status['update_available']);
        $this->assertSame(2, $status['behind']);
        $this->assertSame('aaaa', $status['current']);
        $this->assertSame('bbbb', $status['latest']);
        $this->assertSame('Fix the installer', $status['subject']);
        $this->assertNull($status['error']);

        $stored = json_decode((string) file_get_contents($this->statusFile), true);
        $this->assertTrue($stored['update_available']);
        $this->assertSame(2, $stored['behind']);
    }

    public function test_check_records_an_up_to_date_checkout(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('rev-parse', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "aaaa\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('rev-list', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "0\n", 'stderr' => '']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $status = $this->service()->check();

        $this->assertFalse($status['update_available']);
        $this->assertSame(0, $status['behind']);
        $this->assertNull($status['error']);
    }

    public function test_check_records_a_fetch_failure(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('fetch', $command, true))
                ->once()
                ->andReturn(['exit' => 128, 'stdout' => '', 'stderr' => 'fatal: unable to access']);
        });

        $status = $this->service()->check();

        $this->assertFalse($status['update_available']);
        $this->assertSame(0, $status['behind']);
        $this->assertStringContainsString('fatal: unable to access', (string) $status['error']);

        $stored = json_decode((string) file_get_contents($this->statusFile), true);
        $this->assertNotNull($stored['error']);
    }

    public function test_check_reports_a_non_git_checkout(): void
    {
        config(['panel.update.dir' => $this->tmp.'/elsewhere']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $status = $this->service()->check();

        $this->assertFalse($status['update_available']);
        $this->assertStringContainsString('Not a git checkout', (string) $status['error']);
    }

    public function test_refresh_reads_the_status_written_by_the_runner(): void
    {
        mkdir(dirname($this->statusFile), 0755, true);
        file_put_contents($this->statusFile, json_encode([
            'update_available' => true,
            'behind' => 4,
            'checked_at' => now()->toIso8601String(),
        ]));

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo'
                    && in_array('panel:update:check', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Update available\n", 'stderr' => '']);
        });

        $status = $this->service()->refresh();

        $this->assertTrue($status['update_available']);
        $this->assertSame(4, $status['behind']);
        $this->assertNull($status['error']);
        $this->assertFalse($status['denied']);
    }

    public function test_refresh_reports_a_runner_denial(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo')
                ->once()
                ->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $status = $this->service()->refresh();

        $this->assertTrue($status['denied']);
        $this->assertSame(SudoRunner::denialMessage(), $status['error']);
    }

    public function test_refresh_reports_a_runner_failure(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'run: panel application not found']);
        });

        $status = $this->service()->refresh();

        $this->assertFalse($status['denied']);
        $this->assertStringContainsString('panel application not found', (string) $status['error']);
    }
}
