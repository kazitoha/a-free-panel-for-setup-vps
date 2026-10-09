<?php

namespace Tests\Feature;

use App\Models\BackupSchedule;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-health-'.uniqid();
        mkdir($this->tmp, 0777, true);

        config([
            'panel.cron_file' => $this->tmp.'/cron.d-panel',
            'panel.backup.dir' => $this->tmp.'/backups',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function installCronFile(): void
    {
        file_put_contents(config('panel.cron_file'), "# managed\n");
    }

    private function makeSchedule(array $overrides = []): BackupSchedule
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $schedule = new BackupSchedule(array_merge([
            'target_type' => BackupSchedule::TYPE_DATABASE,
            'target_id' => $database->id,
            'times_per_day' => 1,
            'retention_days' => 7,
            'enabled' => true,
            'next_run_at' => now()->addHour(),
        ], $overrides));
        $schedule->save();

        return $schedule;
    }

    public function test_the_dashboard_shows_all_checks_green_when_nothing_is_scheduled(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('jobs / health')
            ->assertSee('No backup schedules yet.')
            ->assertSee('No cron jobs yet.');
    }

    public function test_the_dashboard_flags_an_overdue_backup_schedule(): void
    {
        $this->installCronFile();
        $this->makeSchedule(['next_run_at' => now()->subMinutes(45)]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Scheduler idle')
            ->assertSee('fail');
    }

    public function test_the_dashboard_warns_about_a_failed_backup_run(): void
    {
        $this->installCronFile();
        $this->makeSchedule([
            'next_run_at' => now()->addHour(),
            'last_run_at' => now()->subHour(),
            'last_status' => 'failed',
            'last_error' => 'mysqldump died: Got error 1045',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Last run failed')
            ->assertSee('mysqldump died: Got error 1045');
    }

    public function test_the_dashboard_warns_about_a_failed_cron_job(): void
    {
        $this->installCronFile();
        CronJob::create([
            'name' => 'Nightly restart',
            'cron_minute' => '0',
            'cron_hour' => '3',
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => '*',
            'command' => 'systemctl restart caddy',
            'enabled' => true,
            'last_run_at' => now()->subDay(),
            'last_status' => 'failed',
            'last_exit' => 1,
            'last_output' => 'Unit caddy not found.',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nightly restart')
            ->assertSee('Unit caddy not found.');
    }

    public function test_the_dashboard_flags_a_missing_cron_file_when_jobs_exist(): void
    {
        $this->makeSchedule();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('is missing');
    }

    public function test_the_dashboard_reports_healthy_backups_when_the_schedule_is_fine(): void
    {
        $this->installCronFile();
        $this->makeSchedule([
            'next_run_at' => now()->addHour(),
            'last_run_at' => now()->subHour(),
            'last_status' => 'success',
            'last_path' => '/var/backups/panel/databases/myapp-x.sql.gz',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('schedule(s) healthy')
            ->assertDontSee('Scheduler idle');
    }
}
