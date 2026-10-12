<?php

namespace App\Console\Commands;

use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Models\Install;
use App\Models\Project;
use App\Services\CaddyFileWriter;
use App\Services\DnsService;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side project deployer, spawned in the background by InstallRunner.
 *
 * Clones or updates the repository, builds assets, generates .env, migrates,
 * fixes permissions, optionally ensures the DNS zone and writes the Caddy
 * site. Every step logs to stdout (captured into the deploy log) and updates
 * the installs/projects rows so the web UI can poll progress.
 */
#[Signature('panel:project:deploy
    {project : Project id}
    {id? : Install record id}
    {--dns : Create the DNS zone when it is missing}')]
#[Description('Clone/update, build and serve a project behind Caddy')]
class PanelProjectDeploy extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly CaddyFileWriter $writer,
        private readonly DnsService $dns,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $project = Project::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error('Project record not found.');

            return self::FAILURE;
        }

        $install = null;

        if ($this->argument('id') !== null) {
            $install = Install::find((int) $this->argument('id'));

            if ($install === null || $install->type !== 'project') {
                $this->error('Install record not found.');

                return self::FAILURE;
            }

            $install->forceFill([
                'status' => Install::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'message' => null,
            ])->save();
        }

        try {
            $this->deploy($project);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $project->forceFill([
                'last_deploy_status' => 'failed',
                'last_deploy_message' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            $install?->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            return self::FAILURE;
        }

        $project->forceFill([
            'last_deployed_at' => now(),
            'last_deploy_status' => 'success',
            'last_deploy_message' => null,
        ])->save();

        $install?->forceFill([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ])->save();

        $this->info("Project {$project->name} deployed successfully.");

        return self::SUCCESS;
    }

    private function deploy(Project $project): void
    {
        $path = $project->path;
        $wwwDir = rtrim((string) config('panel.www_dir', '/var/www'), '/');

        if (! str_starts_with($path, $wwwDir.'/') || str_contains($path, '..')) {
            throw new RuntimeException("Project path {$path} is outside {$wwwDir}.");
        }

        // 1. Code ------------------------------------------------------------
        $this->syncCode($project, $path);

        // 2. Composer --------------------------------------------------------
        if (is_file($path.'/composer.json')) {
            if ($this->binaryAvailable('composer')) {
                $this->line('Installing composer dependencies...');
                $this->mustRun(
                    ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-scripts'],
                    900,
                    $path,
                );

                // Scripts are disabled so a compromised package cannot execute code
                // here — rediscover the Laravel package manifest explicitly.
                if (is_file($path.'/artisan')) {
                    $this->line('Discovering packages...');
                    $manifest = $this->command->run(
                        ['php', 'artisan', 'package:discover', '--ansi', '--no-interaction'],
                        120,
                        null,
                        $path,
                    );

                    if ($manifest['exit'] !== 0) {
                        $this->warn('package:discover failed — the app may need it before it can boot.');
                    }
                }
            } else {
                $this->warn('composer is not installed on this server — skipping dependencies.');
            }
        }

        // 3. Node assets -----------------------------------------------------
        $this->buildAssets($path);

        // 4. Environment -----------------------------------------------------
        $this->writeEnv($project, $path);

        // 5. Migrations ------------------------------------------------------
        $database = $project->database;

        if ($database !== null && is_file($path.'/artisan') && $this->hasMigrations($path)) {
            $this->line('Running migrations...');
            $this->mustRun(['php', 'artisan', 'migrate', '--force'], 180, $path);
        }

        if (is_file($path.'/artisan') && is_dir($path.'/storage/app')) {
            $link = $this->command->run(['php', 'artisan', 'storage:link'], 60, null, $path);

            if ($link['exit'] !== 0) {
                $this->warn('storage:link failed — continuing (the app may not need it).');
            }
        }

        // 6. Permissions -----------------------------------------------------
        foreach (['storage', 'bootstrap/cache'] as $writable) {
            if (is_dir($path.'/'.$writable)) {
                $this->mustRun(['mkdir', '-p', $path.'/'.$writable], 30);
                $this->mustRun(['chown', '-R', 'www-data:www-data', $path.'/'.$writable], 120);
            }
        }

        // 7. DNS -------------------------------------------------------------
        if ($this->option('dns')) {
            $this->ensureZone($project);
        }

        // 8. Caddy site ------------------------------------------------------
        $this->line('Configuring Caddy site...');

        $spec = SiteSpec::validate([
            'domain' => $project->domain,
            'wildcard' => false,
            'type' => 'php',
            'php_version' => $project->php_version,
            'docroot' => $path,
        ], (string) config('panel.www_dir', '/var/www'));

        $this->writer->write(SiteSpec::nameFor($project->domain), SiteSpec::build($spec));

        $this->info("Caddy site {$project->domain} is live.");
    }

    private function syncCode(Project $project, string $path): void
    {
        if (is_dir($path.'/.git')) {
            $this->line("Updating {$path}...");
            $this->mustRun(['git', '-C', $path, 'fetch', '--quiet', '--all', '--prune'], 300);
            $this->mustRun(['git', '-C', $path, 'checkout', '--quiet', $project->branch], 60);
            $this->mustRun(['git', '-C', $path, 'pull', '--quiet', '--ff-only', 'origin', $project->branch], 300);

            return;
        }

        if (is_dir($path)) {
            $entries = array_diff(scandir($path) ?: [], ['.', '..']);

            if ($entries !== []) {
                throw new RuntimeException(
                    "Directory {$path} already exists and is not a git checkout. Remove it (or purge the project) and deploy again."
                );
            }
        }

        $this->line("Cloning {$project->repo_url}...");
        $this->mustRun(
            ['git', 'clone', '--quiet', '--branch', $project->branch, $project->repo_url, $path],
            600,
        );
    }

    private function buildAssets(string $path): void
    {
        if (! is_file($path.'/package.json')) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($path.'/package.json'), true);

        if (! is_array($manifest) || ! isset($manifest['scripts']['build'])) {
            return;
        }

        if (! $this->binaryAvailable('npm')) {
            $this->warn('npm is not installed on this server — skipping asset build.');

            return;
        }

        $this->line('Building frontend assets...');

        $ci = $this->command->run(['npm', 'ci'], 900, null, $path);

        if ($ci['exit'] !== 0) {
            $this->warn('npm ci failed — falling back to npm install.');
            $this->mustRun(['npm', 'install', '--no-audit', '--no-fund'], 900, $path);
        }

        $this->mustRun(['npm', 'run', 'build'], 900, $path);
    }

    private function writeEnv(Project $project, string $path): void
    {
        $envPath = $path.'/.env';

        if (! is_file($envPath)) {
            $lines = [
                'APP_NAME="'.addslashes($project->name).'"',
                'APP_ENV=production',
                'APP_KEY=',
                'APP_DEBUG=false',
                'APP_URL=https://'.$project->domain,
                'LOG_CHANNEL=daily',
                'LOG_LEVEL=info',
                'CACHE_STORE=file',
                'CACHE_DRIVER=file',
                'SESSION_DRIVER=file',
                'QUEUE_CONNECTION=sync',
            ];

            $database = $project->database;

            if ($database !== null) {
                $lines[] = 'DB_CONNECTION=mysql';
                $lines[] = 'DB_HOST=127.0.0.1';
                $lines[] = 'DB_PORT=3306';
                $lines[] = 'DB_DATABASE='.$database->name;
                $lines[] = 'DB_USERNAME='.$database->username;
                $lines[] = 'DB_PASSWORD='.$database->password;
            }

            if (@file_put_contents($envPath, implode("\n", $lines)."\n") === false) {
                throw new RuntimeException("Could not write {$envPath}.");
            }

            @chmod($envPath, 0640);
            $this->line('Created .env');
        }

        if (is_file($path.'/artisan')) {
            $env = (string) file_get_contents($envPath);

            if (! preg_match('/^APP_KEY=.+/m', $env)) {
                $this->line('Generating application key...');
                $this->mustRun(['php', 'artisan', 'key:generate', '--force'], 60, $path);
            }
        }
    }

    private function ensureZone(Project $project): void
    {
        try {
            if ($this->dns->zoneExists($project->domain)) {
                $this->line("DNS zone {$project->domain} already exists.");

                return;
            }

            $ip = config('panel.pdns.server_ip');

            if (blank($ip)) {
                $this->warn('PDNS_SERVER_IP is not set — skipping zone creation.');

                return;
            }

            $this->line("Creating DNS zone {$project->domain}...");
            $this->dns->createZone($project->domain, (string) $ip, false);
        } catch (PowerDnsError|PowerDnsUnavailable $exception) {
            $this->warn('DNS step failed — continuing: '.mb_substr($exception->getMessage(), 0, 300));
        }
    }

    private function hasMigrations(string $path): bool
    {
        $dir = $path.'/database/migrations';

        if (! is_dir($dir)) {
            return false;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    private function binaryAvailable(string $binary): bool
    {
        $result = $this->command->run([$binary, '--version'], 15);

        return $result['exit'] === 0;
    }

    private function mustRun(array $command, float $timeout = 300, ?string $cwd = null): void
    {
        $result = $this->command->run($command, $timeout, null, $cwd);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Command failed: '.implode(' ', $command)
                .($detail !== '' ? ' — '.mb_substr($detail, 0, 500) : '')
            );
        }
    }
}
