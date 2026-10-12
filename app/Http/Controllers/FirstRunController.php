<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SetupState;
use App\Services\SudoRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Collects the credentials install-panel.sh had to leave unset — a MySQL
 * root password it could not unlock and/or the administrator account —
 * applies them through the privileged runner, then hands over to /login.
 */
class FirstRunController extends Controller
{
    public function __construct(
        private readonly SetupState $setup,
        private readonly SudoRunner $sudo,
    ) {}

    public function show(): View
    {
        $steps = $this->setup->pending();

        return view('first-run', [
            'needsMysql' => in_array(SetupState::STEP_MYSQL, $steps, true),
            'needsAdmin' => in_array(SetupState::STEP_ADMIN, $steps, true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $steps = $this->setup->pending();
        $needsMysql = in_array(SetupState::STEP_MYSQL, $steps, true);
        $needsAdmin = in_array(SetupState::STEP_ADMIN, $steps, true);

        $rules = [];
        $messages = [];
        $attributes = [];

        if ($needsMysql) {
            $rules['mysql_password'] = ['required', 'string', 'max:256'];
            $messages['mysql_password.required'] = 'Enter the MySQL root password.';
            $attributes['mysql_password'] = 'MySQL root password';
        }

        if ($needsAdmin) {
            $rules += [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', Password::min(8), 'confirmed'],
            ];
            $attributes += [
                'email' => 'administrator email',
                'password' => 'administrator password',
                'password_confirmation' => 'password confirmation',
            ];
        }

        $validated = $request->validate($rules, $messages, $attributes);

        if ($needsMysql) {
            $error = $this->applyMysql($validated);

            if ($error !== null) {
                return back()->withErrors(['mysql_password' => $error])->onlyInput('email');
            }
        }

        if ($needsAdmin) {
            $this->createAdministrator($validated);
        }

        $this->setup->complete();

        return redirect()->route('login')->with(
            'success',
            $needsAdmin
                ? 'Setup complete — sign in with the administrator account you just created.'
                : 'Setup complete — sign in to the panel.',
        );
    }

    /**
     * Hand the MySQL root password to the privileged setup command, which
     * unlocks MySQL, records the password in .env and /root/.panel-secrets
     * and seeds the PowerDNS database while it has root.
     *
     * @param  array<string, mixed>  $validated
     * @return string|null An error for the password field, or null on success.
     */
    private function applyMysql(array $validated): ?string
    {
        $payload = json_encode([
            'mysql_password' => (string) ($validated['mysql_password'] ?? ''),
            'admin_email' => (string) ($validated['email'] ?? ''),
            'admin_password' => (string) ($validated['password'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($payload) || $payload === '') {
            return 'The setup payload could not be built — check the characters you used.';
        }

        $result = $this->sudo->run(['panel:setup:finish', '--json-stdin'], 120.0, $payload);

        if ($result['exit'] === 0) {
            return null;
        }

        if ($result['denied']) {
            return SudoRunner::denialMessage();
        }

        return 'Could not finish the MySQL setup: '.$this->detail($result);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createAdministrator(array $validated): void
    {
        $email = strtolower(trim((string) $validated['email']));

        $user = User::query()->where('email', $email)->first() ?? new User;
        $user->name = $user->name ?: 'Admin';
        $user->email = $email;
        $user->password = Hash::make((string) $validated['password']);
        $user->is_admin = true;
        $user->save();
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     */
    private function detail(array $result): string
    {
        $detail = trim($result['stderr'].$result['stdout']);

        if ($detail === '') {
            return "the command exited with code {$result['exit']}.";
        }

        $detail = (string) preg_replace('/\s+/', ' ', $detail);

        return mb_substr($detail, 0, 300).'.';
    }
}
