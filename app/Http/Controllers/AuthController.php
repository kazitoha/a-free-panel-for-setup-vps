<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login', [
            'googleEnabled' => $this->googleConfigured(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'email address',
            'password' => 'password',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Those credentials do not match our records.'], 'login')
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function redirectToGoogle(): RedirectResponse
    {
        if (! $this->googleConfigured()) {
            return redirect()->route('login')->with('error', 'Google sign-in is not configured.');
        }

        $state = Str::random(40);
        session()->put('oauth_state', $state);

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('panel.google.client_id'),
            'redirect_uri' => route('auth.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect()->away($url);
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $detail = (string) ($request->input('error_description') ?: $request->input('error'));

            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: '.mb_substr($detail, 0, 200));
        }

        $expected = (string) $request->session()->pull('oauth_state');
        $state = (string) $request->input('state');

        if ($expected === '' || $state === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: invalid state — please try again.');
        }

        if (! $this->googleConfigured()) {
            return redirect()->route('login')->with('error', 'Google sign-in is not configured.');
        }

        $code = (string) $request->input('code');

        if ($code === '') {
            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: no authorization code.');
        }

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) config('panel.google.client_id'),
            'client_secret' => (string) config('panel.google.client_secret'),
            'redirect_uri' => route('auth.google.callback'),
        ]);

        $accessToken = (string) $token->json('access_token');

        if ($token->failed() || $accessToken === '') {
            $detail = (string) ($token->json('error_description') ?: $token->json('error'));
            $detail = $detail !== '' ? $detail : 'token exchange rejected (HTTP '.$token->status().')';

            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: '.mb_substr($detail, 0, 200));
        }

        $profile = Http::withToken($accessToken)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if ($profile->failed()) {
            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: profile request rejected (HTTP '.$profile->status().').');
        }

        $sub = (string) $profile->json('sub');
        $email = strtolower((string) $profile->json('email'));
        $name = (string) ($profile->json('name') ?: $email);
        $verified = (bool) $profile->json('email_verified');

        if ($sub === '' || $email === '') {
            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: the Google account has no email address.');
        }

        if (! $verified) {
            return redirect()->route('login')
                ->with('error', 'Google sign-in failed: Google has not verified this email address.');
        }

        $user = User::where('google_id', $sub)->first()
            ?? User::where('email', $email)->first();

        if ($user === null) {
            return redirect()->route('login')
                ->with('error', "Google account {$email} is not authorized for this panel — sign in with the admin password once to link it.");
        }

        if (! $user->is_admin) {
            return redirect()->route('login')
                ->with('error', "Google account {$email} is not a panel administrator.");
        }

        $user->forceFill(['google_id' => $sub])->save();

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function googleConfigured(): bool
    {
        return (string) config('panel.google.client_id') !== ''
            && (string) config('panel.google.client_secret') !== '';
    }
}
