<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page requires authentication and sends guests to the login form.
     */
    public function test_the_home_page_requires_authentication(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
