<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to the panel');
    }

    public function test_admin_can_log_in_with_valid_credentials(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'super-secret-123',
        ]);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'super-secret-123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'super-secret-123',
        ]);

        $this->from('/login')
            ->post('/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrorsIn('login', 'email');

        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_away_from_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
