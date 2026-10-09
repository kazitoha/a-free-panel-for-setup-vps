<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('panel:admin:create
    {email : Email address for the panel administrator}
    {--password= : Password (asked interactively when omitted)}
    {--name=Admin : Display name}')]
#[Description('Create or update the single panel administrator account.')]
class PanelAdminCreate extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) $this->option('name');
        $password = (string) $this->option('password');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email']],
        );

        if ($validator->fails()) {
            $this->error('Invalid email address: '.$email);

            return self::FAILURE;
        }

        if ($password === '') {
            $password = (string) $this->secret('Admin password');
        }

        $passwordValidation = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(8)]],
        );

        if ($passwordValidation->fails()) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info($existing !== null
            ? "Updated administrator {$email}."
            : "Created administrator {$email}.");

        return self::SUCCESS;
    }
}
