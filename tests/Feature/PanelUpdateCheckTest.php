<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelUpdateCheckTest extends TestCase
{
    private string $tmp;

    private string $dir;

    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-update-check-'.uniqid();
        $this->dir = $this->tmp.'/checkout';
        mkdir($this->dir.'/.git', 0755, true);

        $this->statusFile = $this->tmp.'/storage/update-status.json';

        config([
            'panel.update.dir' => $this->dir,
            'panel.update.status_file' => $this->statusFile,
            'panel.update.branch' => 'main',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    public function test_it_records_a_pending_update(): void
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
                ->andReturn(['exit' => 0, 'stdout' => "5\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('log', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Add the update banner\n", 'stderr' => '']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:check')
            ->expectsOutputToContain('Update available: 5 commit(s) behind main.')
            ->assertSuccessful();

        $status = json_decode((string) file_get_contents($this->statusFile), true);

        $this->assertTrue($status['update_available']);
        $this->assertSame(5, $status['behind']);
        $this->assertSame('aaaa', $status['current']);
        $this->assertSame('bbbb', $status['latest']);
        $this->assertSame('Add the update banner', $status['subject']);
        $this->assertNull($status['error']);
        $this->assertNotNull($status['checked_at']);
    }

    public function test_it_reports_an_up_to_date_checkout(): void
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

        $this->artisan('panel:update:check')
            ->expectsOutputToContain('Panel is up to date.')
            ->assertSuccessful();

        $status = json_decode((string) file_get_contents($this->statusFile), true);

        $this->assertFalse($status['update_available']);
        $this->assertSame(0, $status['behind']);
    }

    public function test_it_records_a_failed_fetch(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('fetch', $command, true))
                ->once()
                ->andReturn(['exit' => 128, 'stdout' => '', 'stderr' => 'fatal: could not read Username']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:check')
            ->expectsOutputToContain('Update check failed: Command failed: git')
            ->assertFailed();

        $status = json_decode((string) file_get_contents($this->statusFile), true);

        $this->assertFalse($status['update_available']);
        $this->assertStringContainsString('could not read Username', (string) $status['error']);
    }

    public function test_it_fails_for_a_directory_that_is_not_a_checkout(): void
    {
        config(['panel.update.dir' => $this->tmp.'/elsewhere']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('git', $command, true))
                ->never();
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:check')
            ->expectsOutputToContain('Not a git checkout')
            ->assertFailed();
    }
}
