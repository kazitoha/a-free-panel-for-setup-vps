<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidDatabase;
use App\Support\DatabaseSpec;
use App\Support\HostCommand;
use App\Support\MySql;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:database:drop
    {name : Database name}
    {username : MySQL username to drop as well}')]
#[Description('Drop a MySQL database and its user (runs as root via the panel runner).')]
class PanelDatabaseDrop extends Command
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
        try {
            $name = DatabaseSpec::validateName((string) $this->argument('name'));
            $username = DatabaseSpec::validateUsername((string) $this->argument('username'));
        } catch (InvalidDatabase $exception) {
            foreach ($exception->errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $db = MySql::identifier($name);
        $user = MySql::literal($username);

        $sql = "DROP DATABASE IF EXISTS {$db}; "
            ."DROP USER IF EXISTS {$user}@'localhost', {$user}@'127.0.0.1';";

        $drop = MySql::query($this->command, $sql, 60.0);

        if ($drop['exit'] !== 0) {
            $detail = trim($drop['stderr']) !== '' ? trim($drop['stderr']) : "mysql exited with code {$drop['exit']}.";

            $this->error(MySql::unavailable($drop)
                ? "MySQL is not reachable: {$detail}"
                : $detail);

            return self::FAILURE;
        }

        $this->info("Dropped {$name} and user {$username}.");

        return self::SUCCESS;
    }
}
