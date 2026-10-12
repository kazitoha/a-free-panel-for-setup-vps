<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Models\Install;
use App\Models\Project;
use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProjectModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $wwwDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wwwDir = sys_get_temp_dir().'/panel-www-'.uniqid();
        mkdir($this->wwwDir, 0755, true);

        config([
            'panel.www_dir' => $this->wwwDir,
            'panel.caddy_sites_dir' => $this->wwwDir.'/sites',
            'panel.caddyfile' => $this->wwwDir.'/Caddyfile',
            'panel.domain' => 'panel.example.com',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(array $overrides = []): Project
    {
        return Project::create(array_merge([
            'name' => 'myapp',
            'repo_url' => 'https://github.com/user/myapp.git',
            'branch' => 'main',
            'domain' => 'myapp.example.com',
            'php_version' => '8.3',
            'path' => $this->wwwDir.'/myapp',
        ], $overrides));
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_projects(): void
    {
        $this->makeProject();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('myapp')
            ->assertSee('myapp.example.com')
            ->assertSee('never deployed');
    }

    public function test_store_creates_the_project_and_spawns_a_deploy(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => str_contains($command[2] ?? '', 'panel:project:deploy'))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'myapp',
                'repo_url' => 'https://github.com/user/myapp.git',
                'branch' => 'main',
                'domain' => 'myapp.example.com',
                'php_version' => '8.3',
                'dns' => '1',
            ])
            ->assertRedirect();

        $project = Project::firstOrFail();
        $this->assertSame($this->wwwDir.'/myapp', $project->path);

        $install = Install::ofType('project')->firstOrFail();
        $this->assertSame('panel:project:deploy '.$project->id.' --dns', $install->command);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
    }

    public function test_store_rejects_an_invalid_repository_url(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'myapp',
                'repo_url' => 'myapp; rm -rf /',
                'branch' => 'main',
                'domain' => 'myapp.example.com',
                'php_version' => '8.3',
            ])
            ->assertSessionHasErrors('repo_url');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_store_rejects_repository_hosts_inside_private_networks(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $blocked = [
            'https://127.0.0.1/user/myapp.git',
            'https://10.1.2.3/user/myapp.git',
            'http://169.254.169.254/latest/meta-data',
            'https://localhost/user/myapp.git',
            'https://panel.internal/user/myapp.git',
            'git@gitlab.internal:user/myapp.git',
        ];

        foreach ($blocked as $index => $repo) {
            $this->actingAs(User::factory()->create())
                ->from(route('projects.create'))
                ->post(route('projects.store'), [
                    'name' => "myapp{$index}",
                    'repo_url' => $repo,
                    'branch' => 'main',
                    'domain' => "myapp{$index}.example.com",
                    'php_version' => '8.3',
                ])
                ->assertSessionHasErrors('repo_url');
        }

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_store_accepts_a_public_ssh_repository_url(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => str_contains($command[2] ?? '', 'panel:project:deploy'))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'myapp',
                'repo_url' => 'git@github.com:user/myapp.git',
                'branch' => 'main',
                'domain' => 'myapp.example.com',
                'php_version' => '8.3',
            ])
            ->assertRedirect();

        $this->assertSame('git@github.com:user/myapp.git', Project::firstOrFail()->repo_url);
    }

    public function test_store_rejects_a_reserved_name(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'panel',
                'repo_url' => 'https://github.com/user/panel.git',
                'branch' => 'main',
                'domain' => 'other.example.com',
                'php_version' => '8.3',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_a_duplicate_domain(): void
    {
        $this->makeProject();

        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'second',
                'repo_url' => 'https://github.com/user/second.git',
                'branch' => 'main',
                'domain' => 'myapp.example.com',
                'php_version' => '8.3',
            ])
            ->assertSessionHasErrors('domain');
    }

    public function test_store_rejects_a_domain_without_a_dot(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'myapp',
                'repo_url' => 'https://github.com/user/myapp.git',
                'branch' => 'main',
                'domain' => 'localhost',
                'php_version' => '8.3',
            ])
            ->assertSessionHasErrors('domain');
    }

    public function test_store_rejects_a_branch_with_metacharacters(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('projects.create'))
            ->post(route('projects.store'), [
                'name' => 'myapp',
                'repo_url' => 'https://github.com/user/myapp.git',
                'branch' => 'main; id',
                'domain' => 'myapp.example.com',
                'php_version' => '8.3',
            ])
            ->assertSessionHasErrors('branch');
    }

    public function test_deploy_redirects_to_an_active_install_instead_of_spawning_twice(): void
    {
        $project = $this->makeProject();

        $running = Install::create([
            'type' => 'project',
            'label' => 'Deploy myapp',
            'command' => 'panel:project:deploy '.$project->id,
            'status' => Install::STATUS_RUNNING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('projects.deploy', $project))
            ->assertRedirect(route('installs.show', $running));
    }

    public function test_deploy_spawns_a_background_job(): void
    {
        $project = $this->makeProject();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => str_contains($command[2] ?? '', 'panel:project:deploy'))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('projects.deploy', $project));

        $response->assertRedirect();
        $this->assertSame(
            route('installs.show', Install::ofType('project')->firstOrFail()),
            $response->headers->get('Location'),
        );
    }

    public function test_destroy_removes_the_record_and_allows_purging_files(): void
    {
        $project = $this->makeProject();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    return in_array('panel:project:destroy', $command, true)
                        && in_array('--purge', $command, true);
                })
                ->andReturn(['exit' => 0, 'stdout' => "Project files removed.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('projects.index'))
            ->delete(route('projects.destroy', $project), ['purge' => '1'])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_destroy_keeps_the_record_when_the_command_fails(): void
    {
        $project = $this->makeProject();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'caddy validate failed']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('projects.index'))
            ->delete(route('projects.destroy', $project))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('projects', 1);
    }

    public function test_deploy_command_reports_a_missing_project(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:project:deploy', ['project' => 4242])->assertFailed();
    }

    public function test_deploy_command_runs_the_pipeline_and_writes_the_caddy_site(): void
    {
        $project = $this->makeProject();
        mkdir($project->path.'/.git', 0755, true);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->andReturnUsing(fn (array $command) => ['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->artisan('panel:project:deploy', ['project' => $project->id])
            ->expectsOutputToContain('Caddy site myapp.example.com is live.')
            ->assertSuccessful();

        $project->refresh();
        $this->assertSame('success', $project->last_deploy_status);
        $this->assertNotNull($project->last_deployed_at);

        // .env was generated with the domain and no database section.
        $env = (string) file_get_contents($project->path.'/.env');
        $this->assertStringContainsString('APP_URL=https://myapp.example.com', $env);
        $this->assertStringNotContainsString('DB_PASSWORD', $env);

        // The site file landed in the configured sites directory.
        $site = config('panel.caddy_sites_dir').'/myapp.example.com.caddy';
        $this->assertFileExists($site);
        $this->assertStringContainsString('php_fastcgi', (string) file_get_contents($site));
    }

    public function test_deploy_command_writes_database_credentials_into_env(): void
    {
        $database = Database::create(['name' => 'myappdb', 'username' => 'myappuser', 'password' => 'secret1234']);
        $project = $this->makeProject(['database_id' => $database->id]);
        mkdir($project->path.'/.git', 0755, true);
        mkdir($project->path.'/database/migrations', 0755, true);
        touch($project->path.'/database/migrations/0001_01_01_000000_create_things_table.php');
        touch($project->path.'/artisan');

        $ran = [];
        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$ran): void {
            $mock->shouldReceive('run')
                ->andReturnUsing(function (array $command) use (&$ran) {
                    $ran[] = implode(' ', $command);

                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                });
        });

        $this->artisan('panel:project:deploy', ['project' => $project->id])
            ->assertSuccessful();

        $env = (string) file_get_contents($project->path.'/.env');
        $this->assertStringContainsString('DB_DATABASE=myappdb', $env);
        $this->assertStringContainsString('DB_USERNAME=myappuser', $env);
        $this->assertStringContainsString('DB_PASSWORD=secret1234', $env);

        $this->assertContains('php artisan migrate --force', $ran);
    }

    public function test_deploy_command_fails_when_the_directory_is_not_a_checkout(): void
    {
        $project = $this->makeProject();
        mkdir($project->path, 0755, true);
        touch($project->path.'/stray.txt');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:project:deploy', ['project' => $project->id])
            ->expectsOutputToContain('not a git checkout')
            ->assertFailed();

        $this->assertSame('failed', $project->fresh()->last_deploy_status);
    }

    public function test_destroy_command_refuses_an_unexpected_path(): void
    {
        $project = $this->makeProject(['path' => '/tmp/evil-location']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:project:destroy', [
            'project' => $project->id,
            '--purge' => true,
        ])->expectsOutputToContain('Refusing to purge')->assertFailed();
    }
}
