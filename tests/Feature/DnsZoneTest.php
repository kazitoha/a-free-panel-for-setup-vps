<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsZoneTest extends TestCase
{
    use RefreshDatabase;

    private const string API = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.pdns.key' => 'test-key',
            'panel.pdns.ns_domain' => 'panel.example.com',
            'panel.domain' => 'panel.example.com',
            'panel.pdns.server_ip' => '203.0.113.10',
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS', 'version' => '4.8.0'], 200),
            self::API.'/zones*' => Http::response([], 200),
        ]);
    }

    public function test_dns_requires_authentication(): void
    {
        $this->get(route('dns.index'))->assertRedirect(route('login'));
    }

    public function test_index_shows_setup_hint_when_powerdns_is_unreachable(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dns.index'))
            ->assertOk()
            ->assertSee('powerdns not reachable')
            ->assertSee('install-panel.sh');
    }

    public function test_index_reports_a_rejected_api_key(): void
    {
        Http::fake([
            self::API => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dns.index'))
            ->assertOk()
            ->assertSee('api key rejected');
    }

    public function test_index_lists_zones(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones*' => Http::response([
                ['name' => 'example.com.', 'kind' => 'Native', 'serial' => 2026100901],
                ['name' => 'panel.example.com.', 'kind' => 'Native', 'serial' => 2026100701],
            ], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dns.index'))
            ->assertOk()
            ->assertSee('example.com')
            ->assertSee('protected');
    }

    public function test_creating_a_zone_sends_soa_ns_and_a_rrsets(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=example.com.' => Http::response([], 200),
            self::API.'/zones' => Http::response(['name' => 'example.com.'], 201),
            self::API.'/zones/example.com.' => Http::response([
                'name' => 'example.com.',
                'kind' => 'Native',
                'serial' => 2026100901,
                'soa_edit_api' => 'DEFAULT',
                'rrsets' => [],
            ], 200),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('dns.create'))
            ->post(route('dns.store'), [
                'domain' => 'example.com',
                'ip' => '203.0.113.10',
                'wildcard' => '1',
            ]);

        $response->assertRedirect(route('dns.show', 'example.com'));

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/zones')) {
                return false;
            }

            $payload = $request->data();

            $types = array_column($payload['rrsets'], 'type');

            return $payload['name'] === 'example.com.'
                && $payload['kind'] === 'Native'
                && in_array('SOA', $types, true)
                && in_array('NS', $types, true)
                && in_array('A', $types, true)
                && str_contains(json_encode($payload), '*.example.com.');
        });
    }

    public function test_creating_a_duplicate_zone_is_rejected(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=example.com.' => Http::response([
                ['name' => 'example.com.'],
            ], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.create'))
            ->post(route('dns.store'), ['domain' => 'example.com'])
            ->assertRedirect(route('dns.create'))
            ->assertSessionHasErrors('domain');
    }

    public function test_creating_a_zone_with_an_invalid_ip_fails(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.create'))
            ->post(route('dns.store'), ['domain' => 'example.com', 'ip' => 'not-an-ip'])
            ->assertSessionHasErrors('ip');
    }

    public function test_the_protected_nameserver_zone_cannot_be_deleted(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.index'))
            ->delete(route('dns.destroy', 'panel.example.com'))
            ->assertRedirect(route('dns.index'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    public function test_batch_import_reports_created_and_failed(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=one.test*' => Http::response([], 200),
            self::API.'/zones?zone=two.test*' => Http::response([], 200),
            self::API.'/zones*' => Http::response(['name' => 'x.'], 201),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.create'))
            ->post(route('dns.import'), [
                'domains' => "one.test, two.test\none.test",
                'ip' => '203.0.113.10',
            ])
            ->assertRedirect(route('dns.index'))
            ->assertSessionHas('success', '2 zone(s) created.');

        // status probe + (zone lookup + create) per domain.
        Http::assertSentCount(5);
    }

    public function test_import_rejects_a_huge_list(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
        ]);

        $domains = collect(range(1, 201))->map(fn (int $i): string => "d{$i}.com")->implode(',');

        $this->actingAs(User::factory()->create())
            ->from(route('dns.create'))
            ->post(route('dns.import'), ['domains' => $domains])
            ->assertSessionHasErrors('domains');
    }
}
