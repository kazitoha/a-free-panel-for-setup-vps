<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SetupPageTest extends TestCase
{
    use RefreshDatabase;

    private const string PDNS = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    private const string JMAP = 'http://127.0.0.1:8080';

    private string $sitesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitesDir = sys_get_temp_dir().'/panel-setup-'.uniqid();
        mkdir($this->sitesDir, 0755, true);

        config([
            'panel.domain' => 'panel.example.com',
            'panel.pdns.ns_domain' => 'example.com',
            'panel.pdns.server_ip' => '203.0.113.10',
            'panel.mysql.root_password' => 's3cret-root',
            'panel.caddyfile' => '/nonexistent/Caddyfile',
            'panel.caddy_sites_dir' => $this->sitesDir,
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

    protected function tearDown(): void
    {
        foreach (glob($this->sitesDir.'/*.caddy') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->sitesDir);

        parent::tearDown();
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

    public function test_setup_page_never_exposes_the_database_root_password(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Root user')
            ->assertSee('not shown here')
            ->assertDontSee('s3cret-root');
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

    public function test_setup_page_offers_installing_mysql_when_the_server_is_unreachable(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('panel:database:list', $command, true))
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 2002']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('the panel cannot reach it')
            ->assertSee(route('databases.install'), false);
    }

    public function test_setup_page_reports_a_working_mysql(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('panel:database:list', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => '[]', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Answering on 127.0.0.1:3306')
            ->assertSee('Open Databases');
    }

    public function test_setup_page_offers_installing_powerdns_when_the_api_is_down(): void
    {
        Http::fake([
            self::PDNS.'/zones*' => fn () => throw new ConnectionException('refused'),
            self::JMAP.'/*' => fn () => throw new ConnectionException('refused'),
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('panel:database:list', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => '[]', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Install PowerDNS')
            ->assertSee(route('dns.install'), false);
    }

    public function test_setup_page_reports_a_missing_caddy_site(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('No Caddy site for panel.example.com yet')
            ->assertDontSee('nip.io');
    }

    public function test_setup_page_lists_addresses_the_caddy_site_serves(): void
    {
        file_put_contents($this->sitesDir.'/panel.caddy', implode("\n", [
            'panel.example.com {',
            '    root * /var/www/panel/public',
            '}',
            '',
            'panel.203.0.113.10.nip.io {',
            '    root * /var/www/panel/public',
            '}',
            '',
            'http://203.0.113.10 {',
            '    root * /var/www/panel/public',
            '}',
            '',
        ]));

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Opens without DNS')
            ->assertSee('https://panel.203.0.113.10.nip.io')
            ->assertSee('http://203.0.113.10', false);
    }

    public function test_setup_page_lists_only_the_addresses_actually_written(): void
    {
        file_put_contents(
            $this->sitesDir.'/panel.caddy',
            "http://203.0.113.10 {\n    root * /var/www/panel/public\n}\n",
        );

        $this->actingAs(User::factory()->create())
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('http://203.0.113.10', false)
            ->assertDontSee('nip.io');
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
