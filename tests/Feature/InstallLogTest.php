<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstall(array $overrides = []): Install
    {
        $install = Install::create($overrides + [
            'type' => 'pma',
            'label' => 'Install phpMyAdmin',
            'command' => 'panel:pma:install',
            'status' => Install::STATUS_PENDING,
        ]);

        return $install;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('logs/installs/*.log')) ?: [] as $log) {
            @unlink($log);
        }

        parent::tearDown();
    }

    public function test_the_install_page_requires_authentication(): void
    {
        $install = $this->makeInstall();

        $this->get(route('installs.show', $install))->assertRedirect(route('login'));
    }

    public function test_it_renders_the_install_page(): void
    {
        $install = $this->makeInstall();

        $this->actingAs(User::factory()->create())
            ->get(route('installs.show', $install))
            ->assertOk()
            ->assertSee('Install phpMyAdmin')
            ->assertSee('install-log');
    }

    public function test_the_log_endpoint_streams_status_and_content(): void
    {
        $install = $this->makeInstall(['status' => Install::STATUS_RUNNING]);
        $install->forceFill(['started_at' => now()])->save();

        $dir = storage_path('logs/installs');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/'.$install->id.'.log', "Downloading phpMyAdmin 5.2.3...\n");

        $this->actingAs(User::factory()->create())
            ->getJson(route('installs.log', $install))
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'finished' => false,
            ])
            ->assertJsonPath('content', "Downloading phpMyAdmin 5.2.3...\n");
    }

    public function test_a_finished_install_stops_polling(): void
    {
        $install = $this->makeInstall([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('installs.log', $install))
            ->assertOk()
            ->assertJson(['status' => 'success', 'finished' => true]);
    }

    public function test_a_never_started_job_reports_stalled(): void
    {
        $install = $this->makeInstall();
        $install->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->actingAs(User::factory()->create())
            ->getJson(route('installs.log', $install))
            ->assertOk()
            ->assertJson(['status' => 'stalled', 'finished' => true])
            ->assertJsonPath('message', 'The installer did not respond — check sudo setup and logs.');
    }
}
