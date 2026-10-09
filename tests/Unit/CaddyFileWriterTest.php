<?php

namespace Tests\Unit;

use App\Services\CaddyFileWriter;
use App\Support\HostCommand;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CaddyFileWriterTest extends TestCase
{
    private string $dir;

    private string $caddyfile;

    private string $sitesDir;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-writer-'.uniqid();
        $this->sitesDir = $this->dir.'/sites';
        mkdir($this->sitesDir, 0755, true);
        $this->caddyfile = $this->dir.'/Caddyfile';
        file_put_contents($this->caddyfile, "{}\n");

        $this->backupDir = $this->dir.'/backups';
        mkdir($this->backupDir, 0755, true);

        config([
            'panel.caddy_sites_dir' => $this->sitesDir,
            'panel.caddyfile' => $this->caddyfile,
            'panel.backup_dir' => $this->backupDir,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sitesDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->backupDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->caddyfile);
        @rmdir($this->sitesDir);
        @rmdir($this->backupDir);
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Program the command seam: caddy validate and systemctl reload succeed.
     */
    private function commandWhereValidationSucceeds(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'caddy' && $command[1] === 'validate')
                ->andReturn(['exit' => 0, 'stdout' => 'Valid configuration', 'stderr' => '']);

            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'systemctl' && $command[1] === 'reload')
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });
    }

    public function test_writing_a_site_validates_reloads_and_adds_the_import(): void
    {
        $this->commandWhereValidationSucceeds();

        $writer = app(CaddyFileWriter::class);
        $writer->write('example.com', "example.com {\n\troot * /var/www/example.com\n}\n");

        $this->assertFileExists($this->sitesDir.'/example.com.caddy');
        $this->assertStringContainsString(
            'import '.$this->sitesDir.'/*.caddy',
            (string) file_get_contents($this->caddyfile),
        );
    }

    public function test_a_failed_validation_restores_the_previous_file(): void
    {
        $path = $this->sitesDir.'/example.com.caddy';
        file_put_contents($path, "OLD CONTENT\n");

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'caddy')
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'adapt: Caddyfile:0: parse error']);

            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'systemctl')
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $writer = app(CaddyFileWriter::class);

        try {
            $writer->write('example.com', "BROKEN {\n");
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restored', $exception->getMessage());
        }

        $this->assertSame("OLD CONTENT\n", (string) file_get_contents($path));
    }

    public function test_a_failed_validation_of_a_new_site_leaves_no_file_behind(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'caddy')
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'adapt: Caddyfile:0: parse error']);

            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'systemctl')
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $writer = app(CaddyFileWriter::class);

        try {
            $writer->write('new.example.com', "whatever {\n");
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFileDoesNotExist($this->sitesDir.'/new.example.com.caddy');
    }

    public function test_removing_a_missing_site_fails(): void
    {
        $writer = app(CaddyFileWriter::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $writer->remove('ghost.example.com');
    }

    public function test_it_refuses_path_traversal_names(): void
    {
        $writer = app(CaddyFileWriter::class);

        $this->expectException(RuntimeException::class);

        $writer->write('../../etc/caddy/Caddyfile', 'nope');
    }
}
