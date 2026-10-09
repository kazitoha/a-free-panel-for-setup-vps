<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelUpdateRunTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private string $dir;

    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-update-run-'.uniqid();
        $this->dir = $this->tmp.'/checkout';
        mkdir($this->dir.'/.git', 0755, true);
        mkdir($this->dir.'/storage', 0755, true);

        $this->statusFile = $this->tmp.'/storage/update-status.json';

        config([
            'panel.update.dir' => $this->dir,
            'panel.update.status_file' => $this->statusFile,
            'panel.update.branch' => 'main',
            'panel.php' => '8.3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        foreach (Install::ofType('update')->pluck('id') as $id) {
            @unlink(storage_path('logs/installs/'.$id.'.log'));
        }

        parent::tearDown();
    }

    private function makeInstall(string $type = 'update'): Install
    {
        return Install::create([
            'type' => $type,
            'label' => 'Apply panel update',
            'command' => 'panel:update:run',
            'status' => Install::STATUS_PENDING,
        ]);
    }

    public function test_it_rejects_an_install_record_of_another_type(): void
    {
        $install = $this->makeInstall('pma');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:update:run', ['id' => $install->id])
            ->expectsOutputToContain('Install record not found.')
            ->assertFailed();
    }

    public function test_it_fast_forwards_and_marks_the_install_successful(): void
    {
        file_put_contents($this->dir.'/composer.json', "{}\n");
        file_put_contents($this->dir.'/package.json', "{}\n");
        file_put_contents($this->dir.'/.env', "APP_KEY=base64:test\n");

        $install = $this->makeInstall();

        $head = "aaaa\n";

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$head): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command === [
                    'git', '-C', $this->dir, 'pull', '--quiet', '--ff-only', 'origin', 'main',
                ])
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command === ['git', '-C', $this->dir, 'rev-parse', 'HEAD'])
                ->andReturnUsing(function () use (&$head): array {
                    $previous = $head;
                    $head = "bbbb\n";

                    return ['exit' => 0, 'stdout' => $previous, 'stderr' => ''];
                });
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('diff', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "resources/css/app.css\n", 'stderr' => '']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:run', ['id' => $install->id])
            ->expectsOutputToContain('Panel updated successfully.')
            ->assertSuccessful();

        $install->refresh();
        $this->assertSame(Install::STATUS_SUCCESS, $install->status);
        $this->assertNull($install->message);
        $this->assertNotNull($install->started_at);
        $this->assertNotNull($install->finished_at);

        $status = json_decode((string) file_get_contents($this->statusFile), true);
        $this->assertNotNull($status['checked_at']);
    }

    public function test_it_skips_the_build_when_no_frontend_files_changed(): void
    {
        file_put_contents($this->dir.'/package.json', "{}\n");

        $install = $this->makeInstall();

        $head = "aaaa\n";

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$head): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('pull', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command === ['git', '-C', $this->dir, 'rev-parse', 'HEAD'])
                ->andReturnUsing(function () use (&$head): array {
                    $previous = $head;
                    $head = "bbbb\n";

                    return ['exit' => 0, 'stdout' => $previous, 'stderr' => ''];
                });
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('diff', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('npm', $command, true))
                ->never();
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:run', ['id' => $install->id])->assertSuccessful();

        $this->assertSame(Install::STATUS_SUCCESS, $install->refresh()->status);
    }

    public function test_it_reports_a_failed_fast_forward(): void
    {
        $install = $this->makeInstall();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('pull', $command, true))
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'error: cannot fast-forward']);
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:run', ['id' => $install->id])
            ->expectsOutputToContain('error: cannot fast-forward')
            ->assertFailed();

        $install->refresh();
        $this->assertSame(Install::STATUS_FAILED, $install->status);
        $this->assertStringContainsString('error: cannot fast-forward', (string) $install->message);
    }

    public function test_it_finishes_without_building_when_head_is_already_current(): void
    {
        $install = $this->makeInstall();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('pull', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command === ['git', '-C', $this->dir, 'rev-parse', 'HEAD'])
                ->andReturn(['exit' => 0, 'stdout' => "aaaa\n", 'stderr' => '']);
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('migrate', $command, true))
                ->never();
            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->artisan('panel:update:run', ['id' => $install->id])
            ->expectsOutputToContain('Panel is already up to date.')
            ->assertSuccessful();

        $this->assertSame(Install::STATUS_SUCCESS, $install->refresh()->status);
    }

    public function test_it_requires_an_install_record(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:update:run', ['id' => 999])
            ->expectsOutputToContain('Install record not found.')
            ->assertFailed();
    }
}
