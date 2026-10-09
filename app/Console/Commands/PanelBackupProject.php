<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('panel:backup:project
    {project : Project id}
    {--retention= : Days to keep (defaults to panel.backup.retention_days)}')]
#[Description('Archive a panel project to a verified .tar.gz file')]
class PanelBackupProject extends Command
{
    protected $hidden = true;

    public function __construct(private readonly BackupService $backups)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $project = Project::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error('Project not found.');

            return self::FAILURE;
        }

        try {
            $path = $this->backups->backupProject($project, $this->retention());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Saved {$path} (".filesize($path).' bytes)');

        return self::SUCCESS;
    }

    private function retention(): int
    {
        $retention = $this->option('retention');

        if ($retention !== null && $retention !== '') {
            return max(1, min(365, (int) $retention));
        }

        return max(1, (int) config('panel.backup.retention_days'));
    }
}
