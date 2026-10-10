<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PhpModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $phpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpDir = sys_get_temp_dir().'/panel-php-'.uniqid();
        mkdir($this->phpDir, 0755, true);

        config([
            'panel.php' => '8.3',
            'panel.php_dir' => $this->phpDir,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->phpDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->phpDir);

        parent::tearDown();
    }

    private function installVersion(string $version): void
    {
        mkdir($this->phpDir.'/'.$version, 0755, true);
    }

    /**
     * apt-cache pkgnames php → phpX.Y-fpm lines for the given versions.
     */
    private function fakeApt(array $versions): void
    {
        $stdout = implode("\n", array_map(fn (string $version): string => "php{$version}-fpm", $versions))."\n";

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($stdout): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => ($command[0] ?? '') === 'apt-cache')
                ->andReturn(['exit' => 0, 'stdout' => $stdout, 'stderr' => '']);
        });
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('php.index'))->assertRedirect(route('login'));
    }

    public function test_it_lists_installed_versions_and_offers_the_others(): void
    {
        $this->installVersion('8.3');
        $this->fakeApt(['8.3', '8.4']);

        $this->actingAs(User::factory()->create())
            ->get(route('php.index'))
            ->assertOk()
            ->assertSee('PHP versions')
            ->assertSee('8.3')
            ->assertSee('panel')
            ->assertSee('8.4')
            ->assertSee('Install')
            ->assertDontSee('name="version" value="8.3"', false);
    }

    public function test_the_panel_version_never_offers_a_remove_button(): void
    {
        $this->installVersion('8.3');
        $this->installVersion('8.2');
        $this->fakeApt(['8.3']);

        $this->actingAs(User::factory()->create())
            ->get(route('php.index'))
            ->assertOk()
            ->assertSee('name="version" value="8.2"', false)
            ->assertDontSee('name="version" value="8.3"', false);
    }

    public function test_install_spawns_the_background_installer_and_redirects(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    $script = $command[2] ?? '';

                    return $command[0] === 'bash'
                        && str_contains($script, 'sudo -n /usr/local/panel/run panel:php:install --php=8.4')
                        && str_contains($script, '2>&1 &');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('php.install'), ['version' => '8.4']);

        $response->assertRedirect();

        $install = Install::ofType('php')->firstOrFail();
        $this->assertSame('panel:php:install --php=8.4', $install->command);
        $this->assertSame('Install PHP 8.4', $install->label);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
        $this->assertSame(route('installs.show', $install), $response->headers->get('Location'));

        $this->get(route('installs.show', $install))->assertOk();
    }

    public function test_a_second_install_waits_for_the_running_one(): void
    {
        $running = Install::create([
            'type' => 'php',
            'label' => 'Install PHP 8.4',
            'command' => 'panel:php:install --php=8.4',
            'status' => Install::STATUS_RUNNING,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('php.install'), ['version' => '8.5']);

        $response->assertRedirect(route('installs.show', $running));
        $this->assertSame(1, Install::ofType('php')->count());
    }

    public function test_an_already_installed_version_is_not_reinstalled(): void
    {
        $this->installVersion('8.3');

        $response = $this->actingAs(User::factory()->create())
            ->from(route('php.index'))
            ->post(route('php.install'), ['version' => '8.3']);

        $response->assertRedirect(route('php.index'));
        $response->assertSessionHas('success', 'PHP 8.3 is already installed.');
        $this->assertSame(0, Install::ofType('php')->count());
    }

    public function test_an_invalid_version_is_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('php.install'), ['version' => 'not-a-version']);

        $response->assertSessionHasErrors('version');
        $this->assertSame(0, Install::ofType('php')->count());
    }

    public function test_remove_refuses_the_panel_version(): void
    {
        $this->installVersion('8.3');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $response = $this->actingAs(User::factory()->create())
            ->from(route('php.index'))
            ->delete(route('php.remove'), ['version' => '8.3']);

        $response->assertRedirect(route('php.index'));
        $response->assertSessionHas('error', 'PHP 8.3 runs the panel itself — it cannot be removed.');
    }

    public function test_remove_refuses_a_version_that_is_not_installed(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $response = $this->actingAs(User::factory()->create())
            ->from(route('php.index'))
            ->delete(route('php.remove'), ['version' => '8.2']);

        $response->assertRedirect(route('php.index'));
        $response->assertSessionHas('error', 'PHP 8.2 is not installed.');
    }

    public function test_remove_deletes_an_unused_version_through_the_sudo_runner(): void
    {
        $this->installVersion('8.3');
        $this->installVersion('8.2');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    return $command[0] === 'sudo'
                        && in_array('panel:php:remove', $command, true)
                        && in_array('--php=8.2', $command, true);
                })
                ->andReturn(['exit' => 0, 'stdout' => 'PHP 8.2 removed.', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->from(route('php.index'))
            ->delete(route('php.remove'), ['version' => '8.2']);

        $response->assertRedirect(route('php.index'));
        $response->assertSessionHas('success', 'PHP 8.2 removed.');
    }

    public function test_remove_reports_a_sudo_denial(): void
    {
        $this->installVersion('8.2');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'user is not allowed to execute /usr/local/panel/run']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->from(route('php.index'))
            ->delete(route('php.remove'), ['version' => '8.2']);

        $response->assertRedirect(route('php.index'));
        $response->assertSessionHas('error', SudoRunner::denialMessage());
    }

    public function test_the_remove_command_refuses_the_panel_version(): void
    {
        $this->artisan('panel:php:remove', ['--php' => '8.3'])
            ->expectsOutputToContain('runs the panel itself')
            ->assertFailed();
    }

    public function test_the_remove_command_skips_a_version_that_is_not_installed(): void
    {
        $this->artisan('panel:php:remove', ['--php' => '8.2'])
            ->expectsOutputToContain('nothing to remove')
            ->assertExitCode(0);
    }

    public function test_the_install_command_rejects_an_invalid_version(): void
    {
        $install = Install::create([
            'type' => 'php',
            'label' => 'Install PHP abc',
            'command' => 'panel:php:install --php=abc',
            'status' => Install::STATUS_PENDING,
        ]);

        $this->artisan('panel:php:install', ['id' => $install->id, '--php' => 'abc'])
            ->assertFailed();

        $this->assertSame(Install::STATUS_FAILED, $install->fresh()->status);
    }
}
