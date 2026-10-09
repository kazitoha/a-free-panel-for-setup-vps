<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use App\Support\MySql;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:database:list')]
#[Description('List MySQL databases with sizes as JSON (runs as root via the panel runner).')]
class PanelDatabaseList extends Command
{
    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sql = <<<'SQL'
            SELECT s.SCHEMA_NAME,
                   COALESCE(ROUND(SUM(t.DATA_LENGTH + t.INDEX_LENGTH) / 1024), 0),
                   COUNT(t.TABLE_NAME)
            FROM information_schema.SCHEMATA s
            LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
            WHERE s.SCHEMA_NAME NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')
            GROUP BY s.SCHEMA_NAME
            ORDER BY s.SCHEMA_NAME
            SQL;

        $result = MySql::query($this->command, $sql);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : "mysql exited with code {$result['exit']}.";

            $this->error(MySql::unavailable($result)
                ? "MySQL is not reachable: {$detail}"
                : $detail);

            return self::FAILURE;
        }

        $rows = [];

        foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $sizeKb, $tables] = array_pad(explode("\t", $line), 3, '0');

            $rows[] = [
                'name' => $name,
                'size_kb' => (int) $sizeKb,
                'tables' => (int) $tables,
            ];
        }

        $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
