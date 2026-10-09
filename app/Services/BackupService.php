<?php

namespace App\Services;

use App\Models\Database;
use App\Models\Project;
use App\Support\HostCommand;
use RuntimeException;

/**
 * Writes database dumps and project archives into the panel backup directory
 * (root-owned, typically /var/backups/panel), verifies each archive and prunes
 * files older than the retention window. Runs as root through the runner or
 * from cron, so filesystem calls here are direct PHP calls, not shell.
 */
class BackupService
{
    public function __construct(private readonly HostCommand $command) {}

    public function storageDir(): string
    {
        return rtrim((string) config('panel.backup.dir'), '/');
    }

    /**
     * Dump a database to .sql.gz and verify it. Returns the archive path.
     *
     * @throws RuntimeException
     */
    public function backupDatabase(Database $database, int $retentionDays): string
    {
        $dir = $this->prepareDir($this->storageDir().'/databases');
        $stamp = now()->format('Ymd-His');
        $sqlPath = "{$dir}/{$database->name}-{$stamp}.sql";
        $gzPath = $sqlPath.'.gz';

        $dump = $this->command->run([
            'mysqldump',
            '--protocol=socket',
            '-uroot',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--databases',
            $database->name,
            "--result-file={$sqlPath}",
        ], 300.0, ['MYSQL_PWD' => (string) config('panel.mysql.root_password')]);

        if ($dump['exit'] !== 0) {
            @unlink($sqlPath);
            throw new RuntimeException($this->failure('mysqldump', $dump));
        }

        $gzip = $this->command->run(['gzip', '-f', $sqlPath], 120.0);

        if ($gzip['exit'] !== 0 || ! is_file($gzPath)) {
            @unlink($sqlPath);
            @unlink($gzPath);
            throw new RuntimeException($this->failure('gzip', $gzip));
        }

        $verify = $this->command->run(['gzip', '-t', $gzPath], 60.0);

        if ($verify['exit'] !== 0) {
            @unlink($gzPath);
            throw new RuntimeException('Backup verification failed: gzip -t reported a corrupt archive.');
        }

        $this->prune($dir, $database->name.'-*.sql.gz', $retentionDays);

        return $gzPath;
    }

    /**
     * Archive a project (excluding vendor/node_modules/.git) to .tar.gz and
     * verify it. Returns the archive path.
     *
     * @throws RuntimeException
     */
    public function backupProject(Project $project, int $retentionDays): string
    {
        if (! is_dir($project->path)) {
            throw new RuntimeException("Project directory not found: {$project->path}");
        }

        $dir = $this->prepareDir($this->storageDir().'/projects');
        $stamp = now()->format('Ymd-His');
        $tarPath = "{$dir}/{$project->name}-{$stamp}.tar.gz";

        $archive = $this->command->run([
            'tar',
            'czf',
            $tarPath,
            '--exclude=./vendor',
            '--exclude=./node_modules',
            '--exclude=./.git',
            '-C',
            $project->path,
            '.',
        ], 600.0);

        if ($archive['exit'] !== 0 || ! is_file($tarPath)) {
            @unlink($tarPath);
            throw new RuntimeException($this->failure('tar', $archive));
        }

        $verify = $this->command->run(['tar', 'tzf', $tarPath], 120.0);

        if ($verify['exit'] !== 0) {
            @unlink($tarPath);
            throw new RuntimeException('Backup verification failed: tar could not read the archive.');
        }

        if ((int) filesize($tarPath) === 0) {
            @unlink($tarPath);
            throw new RuntimeException('Backup verification failed: the archive is empty.');
        }

        $this->prune($dir, $project->name.'-*.tar.gz', $retentionDays);

        return $tarPath;
    }

    /**
     * Everything currently on disk, newest first, for the backups UI.
     *
     * @return array{databases: list<array{file: string, size: int, mtime: int}>, projects: list<array{file: string, size: int, mtime: int}>}
     */
    public function listAll(): array
    {
        return [
            'databases' => $this->listScope($this->storageDir().'/databases'),
            'projects' => $this->listScope($this->storageDir().'/projects'),
        ];
    }

    /**
     * Delete archives older than $retentionDays in $dir matching $pattern.
     */
    public function prune(string $dir, string $pattern, int $retentionDays): void
    {
        $days = max(1, min(365, $retentionDays));
        $cutoff = now()->subDays($days)->getTimestamp();

        foreach (glob("{$dir}/{$pattern}") ?: [] as $file) {
            if (is_file($file) && (int) filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * @return list<array{file: string, size: int, mtime: int}>
     */
    private function listScope(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $rows = [];

        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $rows[] = [
                'file' => basename($file),
                'size' => (int) filesize($file),
                'mtime' => (int) filemtime($file),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return array_slice($rows, 0, 100);
    }

    private function prepareDir(string $dir): string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create backup directory: {$dir}");
        }

        return $dir;
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function failure(string $binary, array $result): string
    {
        $detail = trim($result['stderr'].$result['stdout']);

        return $detail !== ''
            ? "{$binary} failed: ".mb_substr($detail, 0, 400)
            : "{$binary} failed with exit code {$result['exit']}.";
    }
}
