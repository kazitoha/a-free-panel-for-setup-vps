<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the shell runner script that backs sudo access.
 * These tests call bash directly and need no root privileges.
 */
class RunWrapperTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = dirname(__DIR__, 2).'/scripts/run.sh';
    }

    public function test_it_rejects_commands_outside_the_panel_namespace(): void
    {
        $result = $this->runScript(['whoami']);

        $this->assertSame(77, $result['exit'], 'non-panel commands must be denied');
        $this->assertStringContainsString('denied', $result['output']);
    }

    public function test_it_rejects_shell_metacharacters(): void
    {
        $result = $this->runScript(['panel:x; rm -rf /']);

        $this->assertSame(77, $result['exit']);
        $this->assertStringContainsString('denied', $result['output']);
    }

    public function test_it_rejects_empty_invocation(): void
    {
        $result = $this->runScript([]);

        $this->assertSame(77, $result['exit']);
    }

    public function test_it_delegates_allowlisted_commands_to_artisan(): void
    {
        $result = $this->runScript(['panel:about'], [
            'PANEL_APP_DIR' => dirname(__DIR__, 2),
            'PANEL_PHP_BIN' => '/bin/true',
        ]);

        $this->assertSame(0, $result['exit'], 'allowlisted commands must reach the PHP runner');
        $this->assertStringNotContainsString('denied', $result['output']);
    }

    public function test_it_fails_cleanly_when_the_application_is_missing(): void
    {
        $result = $this->runScript(['panel:about'], [
            'PANEL_APP_DIR' => '/nonexistent/panel',
            'PANEL_PHP_BIN' => '/bin/true',
        ]);

        $this->assertSame(78, $result['exit']);
        $this->assertStringContainsString('not found', $result['output']);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return array{exit: int, output: string}
     */
    private function runScript(array $arguments, array $environment = []): array
    {
        $command = 'bash '.escapeshellarg($this->script);
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }

        $prefix = '';
        foreach ($environment as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }

        $output = [];
        $exit = 0;
        exec($prefix.$command.' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
