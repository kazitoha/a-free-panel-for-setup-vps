<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidDatabase;
use App\Support\DatabaseSpec;
use App\Support\HostCommand;
use App\Support\MySql;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:database:create
    {name : Database name}
    {username : MySQL username}
    {password : Password for the username}')]
#[Description('Create a MySQL database and its user (runs as root via the panel runner).')]
class PanelDatabaseCreate extends Command
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
            $spec = DatabaseSpec::validate([
                'name' => (string) $this->argument('name'),
                'username' => (string) $this->argument('username'),
                'password' => (string) $this->argument('password'),
            ]);
        } catch (InvalidDatabase $exception) {
            foreach ($exception->errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $exists = MySql::query($this->command, 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.MySql::literal($spec['name']));

        if ($exists['exit'] !== 0) {
            $this->reportMysqlFailure($exists);

            return self::FAILURE;
        }

        if (trim($exists['stdout']) !== '') {
            $this->error("Database {$spec['name']} already exists.");

            return self::FAILURE;
        }

        $user = MySql::query($this->command, 'SELECT USER FROM mysql.user WHERE User = '.MySql::literal($spec['username']));

        if ($user['exit'] !== 0) {
            $this->reportMysqlFailure($user);

            return self::FAILURE;
        }

        if (trim($user['stdout']) !== '') {
            $this->error("Username {$spec['username']} already exists on this server.");

            return self::FAILURE;
        }

        $db = MySql::identifier($spec['name']);
        $userQuoted = MySql::literal($spec['username']);
        $password = MySql::literal($spec['password']);

        $sql = "CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
            ."CREATE USER {$userQuoted}@'localhost' IDENTIFIED BY {$password}; "
            ."CREATE USER {$userQuoted}@'127.0.0.1' IDENTIFIED BY {$password}; "
            ."GRANT ALL PRIVILEGES ON {$db}.* TO {$userQuoted}@'localhost', {$userQuoted}@'127.0.0.1';";

        $create = MySql::query($this->command, $sql, 60.0);

        if ($create['exit'] !== 0) {
            $this->reportMysqlFailure($create);

            return self::FAILURE;
        }

        $this->info("Created {$spec['name']} with user {$spec['username']}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function reportMysqlFailure(array $result): void
    {
        $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : "mysql exited with code {$result['exit']}.";

        $this->error(MySql::unavailable($result)
            ? "MySQL is not reachable: {$detail}"
            : $detail);
    }
}
