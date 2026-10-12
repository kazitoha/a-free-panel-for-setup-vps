<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SetupState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Collects the administrator account install-panel.sh was not asked to
 * create, then hands over to /login. Credentials the installer cannot
 * generate (MySQL, PowerDNS, mail) are never collected here — each module
 * installs and configures its own server after sign-in.
 */
class FirstRunController extends Controller
{
    public function __construct(private readonly SetupState $setup) {}

    public function show(): View
    {
        return view('first-run');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ], [], [
            'email' => 'administrator email',
            'password' => 'administrator password',
            'password_confirmation' => 'password confirmation',
        ]);

        $this->createAdministrator($validated);

        $this->setup->complete();

        return redirect()->route('login')->with(
            'success',
            'Setup complete — sign in with the administrator account you just created.',
        );
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
}
