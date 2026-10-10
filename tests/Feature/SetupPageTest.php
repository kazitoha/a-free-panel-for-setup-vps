<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetupPageTest extends TestCase
{
    use RefreshDatabase;

    private const string PDNS = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    private const string JMAP = 'http://127.0.0.1:8080';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.domain' => 'panel.example.com',
            'panel.pdns.ns_domain' => 'example.com',
            'panel.pdns.server_ip' => '203.0.113.10',
            'panel.mysql.root_password' => 's3cret-root',
            'panel.caddyfile' => '/nonexistent/Caddyfile',
            'panel.caddy_sites_dir' => '/nonexistent/sites',
        ]);

        Http::fake([
            self::PDNS.'/zones*' => Http::response([
                ['name' => 'example.com.'],
                ['name' => 'panel.example.com.'],
            ], 200),
            self::JMAP.'/*' => Http::response(['methodResponses' => []], 200),
            'api.ipify.org' => Http::response('203.0.113.10', 200),
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/setup')->assertRedirect('/login');
    }

    public function test_setup_page_shows_identity_and_nameserver_records(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('panel.example.com')
            ->assertSee('ns1.example.com.')
            ->assertSee('ns2.example.com.')
            ->assertSee('203.0.113.10')
            ->assertSee('NS')
            ->assertSee(PHP_VERSION);
    }

    public function test_setup_page_shows_database_root_credentials_masked(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Root user')
            ->assertSee('••••••••••')
            ->assertSee('data-value="s3cret-root"', false);
    }

    public function test_setup_page_reports_reachable_powerdns_and_existing_zone(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('PowerDNS')
            ->assertSee('API reachable')
            ->assertSee('Zone panel.example.com exists');
    }

    public function test_setup_page_offers_installing_optional_modules(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Install phpMyAdmin')
            ->assertSee('optional')
            ->assertSee('Open Email module');
    }

    public function test_setup_page_reports_a_missing_caddy_site(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('No Caddy site for panel.example.com yet');
    }

    public function test_dashboard_links_to_setup_while_required_items_are_pending(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Setup incomplete')
            ->assertSee(route('setup.index'), false);
    }

    public function test_unreachable_powerdns_is_reported_without_breaking_the_page(): void
    {
        Http::fake([
            self::PDNS.'/zones*' => fn () => throw new ConnectionException('refused'),
            self::JMAP.'/*' => fn () => throw new ConnectionException('refused'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('did not answer');
    }
}
