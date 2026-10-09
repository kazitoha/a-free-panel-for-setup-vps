<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PanelAdminCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_administrator_account(): void
    {
        $this->artisan('panel:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'secure-password-123',
            '--name' => 'Owner',
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Owner', $admin->name);
        $this->assertTrue(Hash::check('secure-password-123', $admin->password));
    }

    public function test_it_updates_an_existing_account_without_duplicates(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'old-password-1234',
        ]);

        $this->artisan('panel:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'new-password-1234',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'admin@example.com')->count());

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('new-password-1234', $admin->password));
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('panel:admin:create', [
            'email' => 'not-an-email',
            '--password' => 'secure-password-123',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('panel:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
