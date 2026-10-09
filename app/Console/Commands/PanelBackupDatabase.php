<?php

namespace App\Console\Commands;

use App\Models\Database;
use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('panel:backup:database
    {database : Database id}
    {--retention= : Days to keep (defaults to panel.backup.retention_days)}')]
#[Description('Dump a panel database to a verified .sql.gz archive')]
class PanelBackupDatabase extends Command
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
        $database = Database::find((int) $this->argument('database'));

        if ($database === null) {
            $this->error('Database not found.');

            return self::FAILURE;
        }

        try {
            $path = $this->backups->backupDatabase($database, $this->retention());
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
