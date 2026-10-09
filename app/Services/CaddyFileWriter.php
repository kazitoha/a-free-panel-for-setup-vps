<?php

namespace App\Services;

use App\Support\HostCommand;
use App\Support\SiteSpec;
use RuntimeException;

/**
 * Root-side Caddy file operations with backup → validate → reload safety.
 *
 * Every mutation: snapshot the current file, write the new one, make sure the
 * whole Caddyfile still validates, reload Caddy — and roll the snapshot back
 * when validation fails so a bad write can never take the server down.
 */
class CaddyFileWriter
{
    public function __construct(private readonly HostCommand $command) {}

    public function sitesDir(): string
    {
        return (string) config('panel.caddy_sites_dir');
    }

    public function caddyfile(): string
    {
        return (string) config('panel.caddyfile');
    }

    /**
     * Write a site file (create or replace) and reload Caddy.
     *
     * @throws RuntimeException when the site cannot be saved safely
     */
    public function write(string $name, string $content): void
    {
        $path = $this->sitePath($name);
        $backup = $this->backup($path);

        $this->ensureDirectory($this->sitesDir());

        if (@file_put_contents($path, $content) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }

        @chmod($path, 0644);

        $this->apply($path, $backup);
    }

    /**
     * Remove a site file and reload Caddy.
     *
     * @throws RuntimeException when the removal cannot be applied safely
     */
    public function remove(string $name): void
    {
        $path = $this->sitePath($name);

        if (! is_file($path)) {
            throw new RuntimeException("Site file {$name}.caddy does not exist.");
        }

        $backup = $this->backup($path);

        if (! @unlink($path)) {
            throw new RuntimeException("Could not remove {$path}.");
        }

        $this->apply($path, $backup);
    }

    /**
     * Validate the main Caddyfile; on failure restore the snapshot and throw.
     * On success reload Caddy (throwing when the reload itself fails).
     */
    private function apply(string $path, ?string $backup): void
    {
        $this->ensureImport();

        $validate = $this->command->run(['caddy', 'validate', '--config', $this->caddyfile()], 30);

        if ($validate['exit'] !== 0) {
            $this->restore($path, $backup);

            $detail = trim($validate['stderr'].$validate['stdout']);

            throw new RuntimeException(
                'Caddy validation failed — previous configuration restored.'
                .($detail !== '' ? "\n".mb_substr($detail, 0, 800) : '')
            );
        }

        $reload = $this->command->run(['systemctl', 'reload', 'caddy'], 30);

        if ($reload['exit'] !== 0) {
            $detail = trim($reload['stderr'].$reload['stdout']);

            throw new RuntimeException(
                'Site saved but Caddy reload failed.'
                .($detail !== '' ? "\n".mb_substr($detail, 0, 400) : '')
            );
        }
    }

    private function ensureImport(): void
    {
        $caddyfile = $this->caddyfile();

        if (! is_file($caddyfile)) {
            @file_put_contents($caddyfile, "{}\n\nimport ".$this->sitesDir()."/*.caddy\n");

            return;
        }

        $content = (string) file_get_contents($caddyfile);

        if (! str_contains($content, 'import '.$this->sitesDir().'/*.caddy')) {
            file_put_contents($caddyfile, rtrim($content)."\n\nimport ".$this->sitesDir()."/*.caddy\n");
        }
    }

    private function backup(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $backupDir = (string) config('panel.backup_dir', '/root/panel-backups/caddy');
        $this->ensureDirectory($backupDir);

        $backup = $backupDir.'/'.basename($path).'.'.date('Ymd-His');

        if (! @copy($path, $backup)) {
            throw new RuntimeException('Could not back up the existing Caddy file.');
        }

        @chmod($backup, 0600);

        return $backup;
    }

    private function restore(string $path, ?string $backup): void
    {
        if ($backup !== null && is_file($backup)) {
            @copy($backup, $path);

            return;
        }

        // The file did not exist before the failed write — undo the creation.
        @unlink($path);
    }

    private function sitePath(string $name): string
    {
        if (! SiteSpec::isValidName($name)) {
            throw new RuntimeException("Invalid site name: {$name}.");
        }

        return $this->sitesDir().'/'.$name.'.caddy';
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            throw new RuntimeException("Could not create directory {$dir}.");
        }
    }
}
