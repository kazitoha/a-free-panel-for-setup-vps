<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use Mockery\MockInterface;
use Tests\TestCase;

class StalwartUninstallTest extends TestCase
{
    private string $dir;

    private string $sitesDir;

    private string $caddyfile;

    /**
     * @var list<list<string>>
     */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-stalwart-'.uniqid();
        $this->sitesDir = $this->dir.'/sites';
        mkdir($this->sitesDir, 0755, true);
        $this->caddyfile = $this->dir.'/Caddyfile';
        file_put_contents($this->caddyfile, "{}\n");

        config([
            'panel.caddy_sites_dir' => $this->sitesDir,
            'panel.caddyfile' => $this->caddyfile,
            'panel.backup_dir' => $this->dir.'/backups',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sitesDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->caddyfile);
        @rmdir($this->sitesDir);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_does_nothing_when_stalwart_is_not_installed(): void
    {
        $this->fakeHost([]);

        $this->artisan('panel:stalwart:uninstall')
            ->expectsOutputToContain('Stalwart is not installed')
            ->assertSuccessful();

        $this->assertFalse($this->ran('systemctl', 'disable', '--now', 'stalwart'));
    }

    public function test_it_removes_the_unit_files_and_the_mail_site(): void
    {
        $this->writeMailSite();
        $this->fakeHost(['stalwart']);

        $this->artisan('panel:stalwart:uninstall')
            ->expectsOutputToContain('Stalwart mail removed')
            ->assertSuccessful();

        $this->assertTrue($this->ran('systemctl', 'disable', '--now', 'stalwart'));
        $this->assertTrue($this->ran('systemctl', 'daemon-reload'));
        $this->assertTrue($this->ran('systemctl', 'reload', 'caddy'));
        $this->assertFileDoesNotExist($this->sitesDir.'/mail.caddy');
    }

    private function writeMailSite(): void
    {
        file_put_contents($this->sitesDir.'/mail.caddy', "mail.example.com {\n\treverse_proxy 127.0.0.1:8080\n}\n");
    }

    /**
     * @param  list<string>  $installed
     */
    private function fakeHost(array $installed): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($installed): void {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($installed): array {
                $this->commands[] = array_values(array_map('strval', $command));

                if (($command[0] ?? '') === 'systemctl' && ($command[1] ?? '') === 'cat') {
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
}
