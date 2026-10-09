<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CaddySiteTest extends TestCase
{
    use RefreshDatabase;

    private string $sitesDir;

    private function admin(): User
    {
        return User::factory()->create();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitesDir = sys_get_temp_dir().'/panel-sites-'.uniqid();
        mkdir($this->sitesDir, 0755, true);
        config(['panel.caddy_sites_dir' => $this->sitesDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sitesDir.'/*.caddy') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->sitesDir);

        foreach (glob(storage_path('app/site-specs/*.json')) ?: [] as $spec) {
            @unlink($spec);
        }

        parent::tearDown();
    }

    private function writeSite(string $name, ?string $content = null): string
    {
        $path = $this->sitesDir.'/'.$name.'.caddy';
        file_put_contents($path, $content ?? "example.org {\n\troot * /var/www/example.org\n\tfile_server\n}\n");

        return $path;
    }

    public function test_sites_require_authentication(): void
    {
        $this->get(route('caddy.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_sites_and_marks_reserved_ones(): void
    {
        $this->writeSite('panel');
        $this->writeSite('example.com');

        $this->actingAs($this->admin())
            ->get(route('caddy.index'))
            ->assertOk()
            ->assertSee('example.com')
            ->assertSee('managed by panel');
    }

    public function test_storing_a_valid_site_writes_the_spec_and_applies_it(): void
    {
        $sudo = $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    return $command[0] === 'sudo'
                        && in_array('panel:caddy:save', $command, true)
                        && in_array('example.com', $command, true)
                        && str_starts_with($command[array_key_last($command)] ?? '', '--spec=');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs($this->admin())
            ->post(route('caddy.store'), [
                'domain' => 'example.com',
                'type' => 'php',
                'php_version' => '8.3',
                'docroot' => '/var/www/example.com/public',
            ])
            ->assertRedirect(route('caddy.index'))
            ->assertSessionHas('success');

        $this->assertFileExists(storage_path('app/site-specs/example.com.json'));
    }

    public function test_storing_an_injecting_domain_is_rejected_without_touching_sudo(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs($this->admin())
            ->from(route('caddy.create'))
            ->post(route('caddy.store'), [
                'domain' => "example.com {\n\tabort",
                'type' => 'static',
                'docroot' => '/var/www/x',
            ])
            ->assertRedirect(route('caddy.create'))
            ->assertSessionHasErrors('domain');

        $this->assertSame([], glob($this->sitesDir.'/*.caddy') ?: []);
        $this->assertSame([], glob(storage_path('app/site-specs/*.json')) ?: []);
    }

    public function test_storing_a_duplicate_domain_is_rejected(): void
    {
        $this->writeSite('example.com');

        $this->actingAs($this->admin())
            ->from(route('caddy.create'))
            ->post(route('caddy.store'), [
                'domain' => 'example.com',
                'type' => 'static',
                'docroot' => '/var/www/example.com',
            ])
            ->assertSessionHasErrors('domain');
    }

    public function test_reserved_sites_cannot_be_edited_or_deleted(): void
    {
        $this->writeSite('pma');
        $this->writeSite('panel');

        $this->actingAs($this->admin())
            ->get(route('caddy.edit', 'pma'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->delete(route('caddy.destroy', 'panel'))
            ->assertForbidden();

        $this->assertFileExists($this->sitesDir.'/pma.caddy');
    }

    public function test_editing_prefers_the_saved_spec_over_parsing(): void
    {
        $this->writeSite('example.com');
        file_put_contents(
            storage_path('app/site-specs/example.com.json'),
            json_encode([
                'domain' => 'example.com',
                'wildcard' => false,
                'type' => 'php',
                'php_version' => '8.2',
                'docroot' => '/var/www/example.com/current',
                'target' => null,
            ]),
        );

        $this->actingAs($this->admin())
            ->get(route('caddy.edit', 'example.com'))
            ->assertOk()
            ->assertSee('/var/www/example.com/current', false)
            ->assertSee('PHP 8.2');
    }

    public function test_deleting_a_site_applies_and_forgets_the_spec(): void
    {
        $this->writeSite('example.com');
        file_put_contents(storage_path('app/site-specs/example.com.json'), '{}');

        $sudo = $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:caddy:delete', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs($this->admin())
            ->from(route('caddy.index'))
            ->delete(route('caddy.destroy', 'example.com'))
            ->assertRedirect(route('caddy.index'))
            ->assertSessionHas('success');

        $this->assertFileDoesNotExist(storage_path('app/site-specs/example.com.json'));

        unset($sudo);
    }

    public function test_a_failed_apply_surfaces_the_command_output(): void
    {
        $sudo = $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn([
                'exit' => 1,
                'stdout' => "Caddy validation failed — previous configuration restored.\n",
                'stderr' => '',
            ]);
        });

        $this->actingAs($this->admin())
            ->from(route('caddy.create'))
            ->post(route('caddy.store'), [
                'domain' => 'broken.example.com',
                'type' => 'static',
                'docroot' => '/var/www/broken',
            ])
            ->assertRedirect(route('caddy.create'))
            ->assertSessionHas('error');

        unset($sudo);
    }

    public function test_sudo_denial_is_reported_with_a_helpful_message(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs($this->admin())
            ->from(route('caddy.create'))
            ->post(route('caddy.store'), [
                'domain' => 'denied.example.com',
                'type' => 'static',
                'docroot' => '/var/www/denied',
            ])
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }
}
