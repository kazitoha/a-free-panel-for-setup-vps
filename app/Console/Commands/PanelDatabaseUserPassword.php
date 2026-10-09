<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidDatabase;
use App\Support\DatabaseSpec;
use App\Support\HostCommand;
use App\Support\MySql;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:database:user-password
    {username : MySQL username}
    {password : New password}')]
#[Description('Rotate the password of a panel-created MySQL user (runs as root via the panel runner).')]
class PanelDatabaseUserPassword extends Command
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
            $username = DatabaseSpec::validateUsername((string) $this->argument('username'));
            DatabaseSpec::assertPassword((string) $this->argument('password'));
        } catch (InvalidDatabase $exception) {
            foreach ($exception->errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = MySql::literal($username);
        $password = MySql::literal((string) $this->argument('password'));

        $exists = MySql::query($this->command, "SELECT USER FROM mysql.user WHERE User = {$user}");

        if ($exists['exit'] !== 0) {
            $detail = trim($exists['stderr']) !== '' ? trim($exists['stderr']) : "mysql exited with code {$exists['exit']}.";

            $this->error(MySql::unavailable($exists)
                ? "MySQL is not reachable: {$detail}"
                : $detail);

            return self::FAILURE;
        }

        if (trim($exists['stdout']) === '') {
            $this->error("Username {$username} does not exist on this server.");

            return self::FAILURE;
        }

        $sql = "ALTER USER {$user}@'localhost' IDENTIFIED BY {$password}; "
            ."ALTER USER {$user}@'127.0.0.1' IDENTIFIED BY {$password};";

        $alter = MySql::query($this->command, $sql, 60.0);

        if ($alter['exit'] !== 0) {
            $detail = trim($alter['stderr']) !== '' ? trim($alter['stderr']) : "mysql exited with code {$alter['exit']}.";

            $this->error(MySql::unavailable($alter)
                ? "MySQL is not reachable: {$detail}"
                : $detail);

            return self::FAILURE;
        }

        $this->info("Password updated for {$username}.");

        return self::SUCCESS;
    }
}
