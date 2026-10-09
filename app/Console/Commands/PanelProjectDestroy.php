<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\CaddyFileWriter;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Root-side project remover: tears down the Caddy site and optionally
 * deletes the checkout. The projects row is removed by the controller
 * after this command succeeds.
 */
#[Signature('panel:project:destroy
    {project : Project id}
    {--purge : Also delete the project files from the server}')]
#[Description('Remove a project Caddy site (and optionally its files)')]
class PanelProjectDestroy extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly CaddyFileWriter $writer,
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

        $siteName = SiteSpec::nameFor($project->domain);

        try {
            if (is_file($this->writer->sitesDir().'/'.$siteName.'.caddy')) {
                $this->line("Removing Caddy site {$siteName}...");
                $this->writer->remove($siteName);
                $this->info('Caddy site removed.');
            } else {
                $this->line('No Caddy site file to remove.');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $wwwDir = rtrim((string) config('panel.www_dir', '/var/www'), '/');
            $expected = $wwwDir.'/'.$project->name;

            if (! SiteSpec::isValidName($project->name)
                || in_array($project->name, (array) config('panel.reserved_sites'), true)
                || $project->path !== $expected) {
                $this->error("Refusing to purge an unexpected path: {$project->path}");

                return self::FAILURE;
            }

            if (is_dir($project->path)) {
                $this->line("Deleting {$project->path}...");
                $result = $this->command->run(['rm', '-rf', '--', $project->path], 300);

                if ($result['exit'] !== 0) {
                    $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : "rm exited with code {$result['exit']}.";
                    $this->error($detail);

                    return self::FAILURE;
                }
            }

            $this->info('Project files removed.');
        }

        return self::SUCCESS;
    }
}
