<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:backup:list')]
#[Description('Print the archives currently on disk as JSON')]
class PanelBackupList extends Command
{
    public function __construct(private readonly BackupService $backups)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->output->write(json_encode($this->backups->listAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return self::SUCCESS;
    }
}
