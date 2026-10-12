<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    private const string JMAP = 'http://127.0.0.1:8080';

    private const string PDNS = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.domain' => 'panel.example.com',
            'panel.pdns.key' => 'test-key',
            'panel.pdns.ns_domain' => 'panel.example.com',
            'panel.pdns.server_ip' => '203.0.113.10',
        ]);
    }

    /**
     * Fake Stalwart (JMAP, keyed by method name) and PowerDNS (zones that
     * exist according to $zones) in one pass.
     *
     * @param  array<string, mixed>  $jmap  JMAP method → response argument
     * @param  array<string, bool>  $zones  existing zones, name => true
     */
    private function fake(array $jmap, array $zones = []): void
    {
        Http::fake([
            self::JMAP.'/*' => function (Request $request) use ($jmap) {
                $body = $request->data();
                $method = (string) ($body['methodCalls'][0][0] ?? '');

                if (! array_key_exists($method, $jmap)) {
                    return Http::response([
                        'methodResponses' => [
                            ['error', ['type' => 'MethodUnknown', 'description' => "unhandled {$method}"], 'c1'],
                        ],
                    ], 200);
                }

                $result = $jmap[$method];

                if ($result instanceof \Closure) {
                    $result = $result($request);
                }

                return Http::response(['methodResponses' => [[$method, $result, 'c1']]], 200);
            },
            self::PDNS.'/*' => function (Request $request) use ($zones) {
                $url = $request->url();

                if ($request->method() === 'GET' && str_contains($url, 'zone=')) {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $name = rtrim((string) ($query['zone'] ?? ''), '.');

                    return Http::response(isset($zones[$name]) ? [['name' => $name.'.']] : [], 200);
                }

                if ($request->method() === 'POST') {
                    return Http::response([], 201);
                }

                return Http::response([
                    'name' => 'x.', 'kind' => 'Native', 'serial' => 2026100901,
                    'soa_edit_api' => 'DEFAULT', 'rrsets' => [],
                ], 200);
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $jmap
     * @param  array<string, bool>  $zones
     */
    private function fakeOk(array $jmap = [], array $zones = []): void
    {
        $this->fake($jmap + [
            'x:Bootstrap/get' => ['list' => []],
            'x:Domain/query' => ['ids' => []],
        ], $zones);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('mail.index'))->assertRedirect(route('login'));
    }

    public function test_index_reports_stalwart_as_unreachable(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk()
            ->assertSee('stalwart not reachable')
            ->assertSee('Install Stalwart');
    }

    public function test_index_reports_rejected_credentials(): void
    {
        Http::fake([
            self::JMAP.'/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk()
            ->assertSee('credentials rejected');
    }

    public function test_index_reports_an_unfinished_setup(): void
    {
        $this->fake([
            'x:Bootstrap/get' => ['list' => [['id' => 'singleton']]],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk()
            ->assertSee('setup not finished')
            ->assertSee('Complete setup');
    }

    public function test_index_lists_mail_domains(): void
    {
        $this->fakeOk([
            'x:Domain/query' => ['ids' => ['101']],
            'x:Domain/get' => ['list' => [[
                'id' => '101',
                'name' => 'example.com',
                'isEnabled' => true,
                'aliases' => [],
            ]]],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk()
            ->assertSee('running')
            ->assertSee('example.com')
            ->assertSee('mail.panel.example.com');
    }

    public function test_jmap_requests_carry_basic_auth(): void
    {
        config(['panel.stalwart.password' => 'sekret']);

        $this->fakeOk();

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::JMAP)
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('admin:sekret'));
        });
    }

    public function test_a_missing_jmap_endpoint_falls_back_to_api(): void
    {
        Http::fake([
            self::JMAP.'/jmap' => Http::response(['error' => 'Not found'], 404),
            self::JMAP.'/api' => function (Request $request) {
                $method = (string) ($request->data()['methodCalls'][0][0] ?? '');

                $responses = [
                    'x:Bootstrap/get' => ['list' => []],
                    'x:Domain/query' => ['ids' => ['101']],
                    'x:Domain/get' => ['list' => [['id' => '101', 'name' => 'example.com']]],
                ];

                return Http::response([
                    'methodResponses' => [[$method, $responses[$method] ?? [], 'c1']],
                ], 200);
            },
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.index'))
            ->assertOk()
            ->assertSee('example.com');

        // One 404 on /jmap, everything after sticks to /api.
        $jmapCalls = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/jmap'));

        $this->assertSame(1, $jmapCalls->count());
    }

    public function test_creating_a_mail_domain_precreates_the_zone_and_registers_powerdns(): void
    {
        $this->fakeOk([
            'x:AcmeProvider/query' => ['ids' => []],
            'x:DnsServer/query' => ['ids' => []],
            'x:DnsServer/set' => ['created' => ['new1' => ['id' => '3']]],
            'x:Domain/set' => ['created' => ['new1' => ['id' => '101']]],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('mail.create'))
            ->post(route('mail.store'), [
                'domain' => 'Example.COM',
                'ip' => '203.0.113.10',
            ]);

        $response->assertRedirect(route('mail.show', '101'));

        // The zone lands in PowerDNS first (createZone re-checks existence).
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/zones')
                && $request->data()['name'] === 'example.com.'
                && in_array('SOA', array_column($request->data()['rrsets'], 'type'), true)
                && in_array('A', array_column($request->data()['rrsets'], 'type'), true);
        });

        // Stalwart gets the domain with automatic DNS + DKIM.
        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::JMAP)) {
                return false;
            }

            $body = $request->data();

            if (($body['methodCalls'][0][0] ?? '') !== 'x:Domain/set') {
                return false;
            }

            $domain = $body['methodCalls'][0][1]['create']['new1'] ?? [];

            return $domain['name'] === 'example.com'
                && $domain['dnsManagement']['@type'] === 'Automatic'
                && $domain['dnsManagement']['dnsServerId'] === '3'
                && $domain['certificateManagement']['@type'] === 'Manual'
                && $domain['dkimManagement']['@type'] === 'Automatic';
        });
    }

    public function test_creating_a_duplicate_mail_domain_is_rejected(): void
    {
        $this->fakeOk([
            'x:Domain/query' => ['ids' => ['101']],
            'x:Domain/get' => ['list' => [['id' => '101', 'name' => 'example.com']]],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('mail.create'))
            ->post(route('mail.store'), ['domain' => 'example.com'])
            ->assertRedirect(route('mail.create'))
            ->assertSessionHasErrors('domain');
    }

    public function test_creating_a_mail_domain_with_an_invalid_name_fails(): void
    {
        $this->fakeOk();

        $this->actingAs(User::factory()->create())
            ->from(route('mail.create'))
            ->post(route('mail.store'), ['domain' => 'not a domain'])
            ->assertRedirect(route('mail.create'))
            ->assertSessionHasErrors('domain');
    }

    public function test_show_lists_the_mailboxes_of_a_domain(): void
    {
        $this->fakeOk([
            'x:Domain/get' => ['list' => [[
                'id' => '5',
                'name' => 'example.com',
                'isEnabled' => true,
                'aliases' => [],
            ]]],
            'x:Account/query' => ['ids' => ['9']],
            'x:Account/get' => ['list' => [[
                'id' => '9',
                'name' => 'hello',
                'domainId' => '5',
                'description' => 'Sales inbox',
                'isEnabled' => true,
                'roles' => ['@type' => 'User'],
            ]]],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('mail.show', '5'))
            ->assertOk()
            ->assertSee('hello@example.com')
            ->assertSee('Sales inbox')
            ->assertSee('MX → mail.panel.example.com')
            ->assertSee('Incoming · IMAP')
            ->assertSee('mail.panel.example.com:993 · SSL/TLS')
            ->assertSee('mail.panel.example.com:465 · SSL/TLS')
            ->assertSee('mail.panel.example.com:587 · STARTTLS')
            ->assertSee('nameservers to be delegated');
    }

    public function test_creating_a_mailbox_sends_the_password_credential(): void
    {
        $this->fakeOk([
            'x:Domain/get' => ['list' => [['id' => '5', 'name' => 'example.com']]],
            'x:Account/set' => ['created' => ['new1' => ['id' => '9']]],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('mail.show', '5'))
            ->post(route('mail.mailboxes.store', '5'), [
                'name' => '  Hello ',
                'password' => 'correct horse',
                'description' => 'Sales inbox',
            ]);

        $response->assertRedirect(route('mail.show', '5'));
        $response->assertSessionHas('success', 'Mailbox hello@example.com created.');

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::JMAP)) {
                return false;
            }

            $body = $request->data();

            if (($body['methodCalls'][0][0] ?? '') !== 'x:Account/set') {
                return false;
            }

            $account = $body['methodCalls'][0][1]['create']['new1'] ?? [];

            return $account['name'] === 'hello'
                && $account['@type'] === 'User'
                && $account['credentials']['0']['@type'] === 'Password'
                && $account['credentials']['0']['secret'] === 'correct horse'
                && $account['domainId'] === '5';
        });
    }

    public function test_a_mailbox_with_an_invalid_name_is_rejected(): void
    {
        $this->fakeOk([
            'x:Domain/get' => ['list' => [['id' => '5', 'name' => 'example.com']]],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('mail.show', '5'))
            ->post(route('mail.mailboxes.store', '5'), [
                'name' => 'bad name',
                'password' => 'correct horse',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_a_short_mailbox_password_is_rejected(): void
    {
        $this->fakeOk([
            'x:Domain/get' => ['list' => [['id' => '5', 'name' => 'example.com']]],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('mail.show', '5'))
            ->post(route('mail.mailboxes.store', '5'), [
                'name' => 'hello',
                'password' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_resetting_a_mailbox_password_replaces_the_credentials(): void
    {
        $this->fakeOk([
            'x:Account/set' => ['updated' => ['9' => (object) []], 'notUpdated' => []],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('mail.show', '5'))
            ->put(route('mail.mailboxes.update', ['5', '9']), ['password' => 'new secret here'])
            ->assertRedirect(route('mail.show', '5'))
            ->assertSessionHas('success', 'Password reset.');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            if (($body['methodCalls'][0][0] ?? '') !== 'x:Account/set') {
                return false;
            }

            $update = $body['methodCalls'][0][1]['update']['9'] ?? [];

            return $update['credentials']['0']['secret'] === 'new secret here';
        });
    }

    public function test_deleting_a_mail_domain_removes_the_accounts_first(): void
    {
        $this->fakeOk([
            'x:Account/query' => ['ids' => ['9']],
            'x:Account/get' => ['list' => [[
                'id' => '9',
                'name' => 'hello',
                'domainId' => '5',
                'isEnabled' => true,
            ]]],
            'x:Account/set' => ['destroyed' => ['9'], 'notDestroyed' => []],
            'x:Domain/set' => ['destroyed' => ['5'], 'notDestroyed' => []],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('mail.show', '5'))
            ->delete(route('mail.destroy', '5'))
            ->assertRedirect(route('mail.index'))
            ->assertSessionHas('success', 'Mail domain deleted.');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return ($body['methodCalls'][0][0] ?? '') === 'x:Account/set'
                && $body['methodCalls'][0][1]['destroy'] === ['9'];
        });

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return ($body['methodCalls'][0][0] ?? '') === 'x:Domain/set'
                && $body['methodCalls'][0][1]['destroy'] === ['5'];
        });
    }

    public function test_install_spawns_the_background_installer_and_redirects(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    $script = $command[2] ?? '';

                    return $command[0] === 'bash'
                        && str_contains($script, 'sudo -n /usr/local/panel/run panel:stalwart:install')
                        && str_contains($script, '2>&1 &');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('mail.install'));

        $response->assertRedirect();

        $install = Install::ofType('stalwart')->firstOrFail();
        $this->assertSame('panel:stalwart:install', $install->command);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
        $this->assertSame(route('installs.show', $install), $response->headers->get('Location'));
    }

    public function test_a_second_install_uses_the_active_job_instead_of_spawning(): void
    {
        $running = Install::create([
            'type' => 'stalwart',
            'label' => 'Install Stalwart mail',
            'command' => 'panel:stalwart:install',
            'status' => Install::STATUS_RUNNING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('mail.install'))
            ->assertRedirect(route('installs.show', $running));

        $this->assertSame(1, Install::ofType('stalwart')->count());
    }
}
