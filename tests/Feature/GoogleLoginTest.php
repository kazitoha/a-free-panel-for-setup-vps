<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(): void
    {
        config([
            'panel.google.client_id' => 'client-123.apps.googleusercontent.com',
            'panel.google.client_secret' => 'secret-456',
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function fakeGoogle(array $profile = [], int $tokenStatus = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(
                $tokenStatus === 200
                    ? ['access_token' => 'tok-abc', 'token_type' => 'Bearer']
                    : ['error' => 'invalid_grant', 'error_description' => 'Bad Request'],
                $tokenStatus,
            ),
            'openidconnect.googleapis.com/*' => Http::response(array_merge([
                'sub' => 'google-sub-1',
                'email' => 'admin@example.com',
                'name' => 'Admin',
                'email_verified' => true,
            ], $profile), 200),
        ]);
    }

    public function test_login_page_hides_the_google_button_when_not_configured(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign in with Google');
    }

    public function test_login_page_shows_the_google_button_when_configured(): void
    {
        $this->configureGoogle();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in with Google');
    }

    public function test_redirect_to_google_carries_a_state(): void
    {
        $this->configureGoogle();

        $response = $this->get(route('auth.google'));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=client-123', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertNotEmpty(session('oauth_state'));
    }

    public function test_redirect_to_google_requires_configuration(): void
    {
        $this->get(route('auth.google'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_callback_rejects_a_state_mismatch(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle();

        $this->withSession(['oauth_state' => 'correct-state'])
            ->from(route('login'))
            ->get(route('auth.google.callback').'?state=wrong-state&code=abc123')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_reports_a_provider_denial(): void
    {
        $this->configureGoogle();

        $this->get(route('auth.google.callback').'?error=access_denied&error_description=User+denied+access')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google sign-in failed: User denied access');

        $this->assertGuest();
    }

    public function test_callback_reports_a_token_exchange_failure(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle([], 401);

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_callback_rejects_an_unverified_email(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle(['email_verified' => false]);

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google sign-in failed: Google has not verified this email address.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_rejects_a_google_account_that_is_not_a_panel_user(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle(['email' => 'stranger@gmail.com']);

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_links_and_signs_in_an_existing_user(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle(['email' => 'admin@example.com']);

        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-sub-1', $user->fresh()->google_id);
    }

    public function test_callback_signs_in_a_previously_linked_user(): void
    {
        $this->configureGoogle();
        // Google returns a different email than the panel account — the link
        // on google_id still wins.
        $this->fakeGoogle(['email' => 'renamed@gmail.com']);

        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->forceFill(['google_id' => 'google-sub-1'])->save();

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_sends_the_token_request_with_client_credentials(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle();
        User::factory()->create(['email' => 'admin@example.com']);

        $this->withSession(['oauth_state' => 'good'])
            ->get(route('auth.google.callback').'?state=good&code=abc123')
            ->assertRedirect(route('dashboard'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['client_id'] === 'client-123.apps.googleusercontent.com'
                && $request['client_secret'] === 'secret-456'
                && $request['code'] === 'abc123';
        });
    }
}
