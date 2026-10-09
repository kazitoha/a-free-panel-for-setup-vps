<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Replace the Caddyfile from a file the panel staged, with the same
 * backup → validate → reload safety net as every other Caddy mutation:
 * a configuration that fails validation is rolled back automatically.
 */
#[Signature('panel:caddyfile:write {file : Path to the staged Caddyfile content}')]
#[Description('Replace the Caddyfile after validating the new configuration')]
class PanelCaddyfileWrite extends Command
{
    protected $hidden = true;

    private const int MAX_BYTES = 200_000;

    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staged = (string) $this->argument('file');
        $target = (string) config('panel.caddyfile');

        try {
            $content = $this->readStaged($staged);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $previous = is_file($target) ? file_get_contents($target) : null;

        if (@file_put_contents($target, $content) === false) {
            $this->error("Could not write {$target}.");

            return self::FAILURE;
        }

        @chmod($target, 0644);

        $validate = $this->command->run(['caddy', 'validate', '--config', $target], 30);

        if ($validate['exit'] !== 0) {
            $this->restore($target, $previous);

            $detail = trim($validate['stderr'].$validate['stdout']);

            $this->error(
                'Caddy validation failed — previous Caddyfile restored.'
                .($detail !== '' ? "\n".mb_substr($detail, 0, 800) : '')
            );

            return self::FAILURE;
        }

        $reload = $this->command->run(['systemctl', 'reload', 'caddy'], 30);

        if ($reload['exit'] !== 0) {
            $detail = trim($reload['stderr'].$reload['stdout']);

            $this->error(
                'Caddyfile saved but Caddy reload failed.'
                .($detail !== '' ? "\n".mb_substr($detail, 0, 400) : '')
            );

            return self::FAILURE;
        }

        $this->info('Caddyfile saved and Caddy reloaded.');

        return self::SUCCESS;
    }

    private function readStaged(string $staged): string
    {
        $root = realpath(storage_path('app'));
        $real = realpath($staged);

        if ($root === false || $real === false || ! str_starts_with($real, $root.'/')) {
            throw new RuntimeException('The staged file must live inside storage/app.');
        }

        if (! is_file($real)) {
            throw new RuntimeException('Staged file not found.');
        }

        $size = (int) filesize($real);

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('The Caddyfile is too large (max 200 KB).');
        }

        $content = (string) file_get_contents($real);

        if (str_contains($content, "\0")) {
            throw new RuntimeException('The Caddyfile contains binary data.');
        }

        return $content;
    }

    private function restore(string $target, ?string $previous): void
    {
        if ($previous !== null) {
            @file_put_contents($target, $previous);
            @chmod($target, 0644);

            return;
        }

        @unlink($target);
    }
}
