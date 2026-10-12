<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden_from_dashboard(): void
    {
        $this->actingAs(User::factory()->nonAdmin()->create())
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_non_admin_is_forbidden_from_privileged_module(): void
    {
        $this->actingAs(User::factory()->nonAdmin()->create())
            ->get(route('caddy.index'))
            ->assertForbidden();
    }

    public function test_non_admin_is_forbidden_from_setup(): void
    {
        $this->actingAs(User::factory()->nonAdmin()->create())
            ->get(route('setup.index'))
            ->assertForbidden();
    }

    public function test_admin_can_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();
    }
}
