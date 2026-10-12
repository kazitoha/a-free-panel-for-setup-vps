<?php

namespace Tests\Unit;

use App\Support\InstallStatus;
use Tests\TestCase;

class InstallStatusTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-install-'.uniqid();
        mkdir($this->dir.'/steps', 0755, true);

        config(['panel.install.status_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/steps/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->dir.'/manifest.json');
        @rmdir($this->dir.'/steps');
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $keys
     */
    private function manifest(array $keys): void
    {
        file_put_contents($this->dir.'/manifest.json', json_encode([
            'steps' => array_map(fn (string $key): array => ['key' => $key, 'label' => ucfirst($key)], $keys),
        ]));
    }

    private function writeResult(string $key, string $status, ?string $message = null): void
    {
        file_put_contents($this->dir.'/steps/'.$key.'.json', json_encode([
            'key' => $key,
            'label' => ucfirst($key),
            'status' => $status,
            'message' => $message,
        ]));
    }

    public function test_without_a_manifest_nothing_is_reported(): void
    {
        $this->assertFalse(InstallStatus::available());
        $this->assertSame([], InstallStatus::steps());
        $this->assertSame([], InstallStatus::problems());
    }

    public function test_steps_without_a_result_are_pending(): void
    {
        $this->manifest(['system', 'php']);

        $steps = InstallStatus::steps();

        $this->assertCount(2, $steps);
        $this->assertSame('system', $steps[0]['key']);
        $this->assertSame('pending', $steps[0]['status']);
        $this->assertNull($steps[0]['message']);
    }

    public function test_problems_lists_failures_first_with_their_message(): void
    {
        $this->manifest(['system', 'php', 'caddy']);
        $this->writeResult('system', 'success');
        $this->writeResult('php', 'pending');
        $this->writeResult('caddy', 'failed', 'apt-get failed (exit 100)');

        $problems = InstallStatus::problems();

        $this->assertSame(['caddy', 'php'], array_column($problems, 'key'));
        $this->assertSame('apt-get failed (exit 100)', $problems[0]['message']);
    }

    public function test_a_clean_install_has_no_problems(): void
    {
        $this->manifest(['system', 'php']);
        $this->writeResult('system', 'success');
        $this->writeResult('php', 'success');

        $this->assertTrue(InstallStatus::available());
        $this->assertSame([], InstallStatus::problems());
    }

    public function test_broken_json_is_ignored(): void
    {
        file_put_contents($this->dir.'/manifest.json', '{not valid json');

        $this->assertSame([], InstallStatus::steps());
    }

    public function test_the_step_scripts_write_results_the_panel_reads(): void
    {
        $lib = base_path('scripts/install/lib.sh');
        $this->assertFileExists($lib);

        $script = <<<'SH'
        set -euo pipefail
        source "$LIB"
        panel_install_write_manifest "60-code|Panel code" "70-app|Laravel application"
        panel_install_write_step "60-code" "Panel code" "success" "" "0" "2026-01-01T00:00:00Z" "2026-01-01T00:01:00Z"
        panel_install_write_step "70-app" "Laravel application" "failed" "composer install failed — no network" "1" "2026-01-01T00:01:00Z" "2026-01-01T00:02:00Z"
        SH;

        exec(
            'LIB='.escapeshellarg($lib)
            .' PANEL_INSTALL_STATE='.escapeshellarg($this->dir)
            .' bash -c '.escapeshellarg($script).' 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame(['60-code', '70-app'], array_column(InstallStatus::steps(), 'key'));

        $problems = InstallStatus::problems();

        $this->assertCount(1, $problems);
        $this->assertSame('70-app', $problems[0]['key']);
        $this->assertSame('Laravel application', $problems[0]['label']);
        $this->assertSame('composer install failed — no network', $problems[0]['message']);
    }
}
