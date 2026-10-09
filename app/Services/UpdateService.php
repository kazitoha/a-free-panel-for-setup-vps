<?php

namespace App\Services;

use App\Support\HostCommand;
use RuntimeException;
use Throwable;

/**
 * Tracks how far the panel's git checkout is behind its upstream branch.
 *
 * The result is stored as JSON so the dashboard can render the alert banner
 * with a plain file read — a page load never runs git or sudo. The check
 * itself runs as root through `panel:update:check` (hourly cron, the Check
 * button, and again after every applied update).
 */
class UpdateService
{
    public function __construct(
        private readonly HostCommand $command,
        private readonly SudoRunner $sudo,
    ) {}

    /**
     * The checkout that is kept in sync with the upstream branch.
     */
    public function dir(): string
    {
        $dir = (string) config('panel.update.dir', '');

        return $dir !== '' ? rtrim($dir, '/') : base_path();
    }

    public function branch(): string
    {
        return (string) config('panel.update.branch', 'main');
    }

    public function statusFile(): string
    {
        return (string) config('panel.update.status_file');
    }

    /**
     * The stored status, with every key guaranteed to be present.
     *
     * @return array{update_available: bool, behind: int, current: ?string, latest: ?string, subject: ?string, branch: string, checked_at: ?string, error: ?string, denied: bool}
     */
    public function status(): array
    {
        $status = [
            'update_available' => false,
            'behind' => 0,
            'current' => null,
            'latest' => null,
            'subject' => null,
            'branch' => $this->branch(),
            'checked_at' => null,
            'error' => null,
            'denied' => false,
        ];

        $path = $this->statusFile();

        if (! is_file($path)) {
            return $status;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return $status;
        }

        $status = array_merge($status, $decoded);
        $status['update_available'] = (bool) $status['update_available'];
        $status['behind'] = (int) $status['behind'];
        $status['denied'] = false;

        return $status;
    }

    /**
     * The status has never been recorded, or the last check is older than
     * the configured interval.
     */
    public function isStale(): bool
    {
        $checkedAt = $this->status()['checked_at'];

        if ($checkedAt === null) {
            return true;
        }

        $timestamp = strtotime($checkedAt);

        if ($timestamp === false) {
            return true;
        }

        return (time() - $timestamp) > (int) config('panel.update.interval', 3600);
    }

    /**
     * Fetch the upstream branch and record whether HEAD is behind it.
     *
     * Runs wherever git is allowed to write to the checkout (as root). Failures
     * are recorded in the status instead of thrown, so the caller always gets
     * a writable status back.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        try {
            $status = $this->probe();
        } catch (Throwable $exception) {
            $previous = $this->status();

            $status = [
                'update_available' => false,
                'behind' => 0,
                'current' => $previous['current'],
                'latest' => null,
                'subject' => null,
                'branch' => $this->branch(),
                'checked_at' => now()->toIso8601String(),
                'error' => $exception->getMessage(),
            ];
        }

        return $this->write($status);
    }

    /**
     * Ask the root-side checker to refresh the status file, then read it back.
     *
     * Used by the web layer: the request itself never touches git.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $result = $this->sudo->run(
            ['panel:update:check'],
            (float) config('panel.update.check_timeout', 90),
        );

        if ($result['exit'] === 0) {
            return $this->status();
        }

        $status = $this->status();

        if ($result['denied']) {
            $status['error'] = SudoRunner::denialMessage();
            $status['denied'] = true;

            return $status;
        }

        $detail = trim($result['stderr'].$result['stdout']);

        $status['error'] = $detail !== ''
            ? mb_substr($detail, 0, 300)
            : "The update check exited with code {$result['exit']}.";

        return $status;
    }

    /**
     * Root-created status files would be unwritable by the web user — keep the
     * file owned by www-data (a chown the panel user cannot perform, failures
     * are ignored on purpose).
     */
    public function keepWebWritable(): void
    {
        $this->command->run(['chown', 'www-data:www-data', $this->statusFile()], 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(): array
    {
        $dir = $this->dir();
        $branch = $this->branch();

        if (! is_dir($dir.'/.git')) {
            throw new RuntimeException('Not a git checkout — automatic updates are unavailable.');
        }

        $this->mustRun(['git', '-C', $dir, 'fetch', '--quiet', '--prune', 'origin', $branch], 300);

        $current = $this->output(['git', '-C', $dir, 'rev-parse', 'HEAD']);
        $latest = $this->output(['git', '-C', $dir, 'rev-parse', "refs/remotes/origin/{$branch}"]);
        $behind = (int) $this->output(['git', '-C', $dir, 'rev-list', '--count', "HEAD..{$latest}"]);
        $subject = $this->output(['git', '-C', $dir, 'log', '-1', '--format=%s', $latest]);

        return [
            'update_available' => $current !== $latest,
            'behind' => $behind,
            'current' => $current,
            'latest' => $latest,
            'subject' => $subject,
            'branch' => $branch,
            'checked_at' => now()->toIso8601String(),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function write(array $status): array
    {
        $path = $this->statusFile();
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }

        $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException("Could not write {$path}.");
        }

        @chmod($path, 0644);

        return $status;
    }

    /**
     * @param  list<string>  $command
     */
    private function output(array $command, float $timeout = 60.0): string
    {
        return trim($this->mustRun($command, $timeout)['stdout']);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function mustRun(array $command, float $timeout): array
    {
        $result = $this->command->run($command, $timeout);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Command failed: '.implode(' ', $command)
                .($detail !== '' ? ' — '.mb_substr($detail, 0, 400) : '')
            );
        }

        return $result;
    }
}
