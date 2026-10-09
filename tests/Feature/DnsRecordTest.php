<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsRecordTest extends TestCase
{
    use RefreshDatabase;

    private const string API = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    /**
     * Zone payload as PowerDNS returns it (soa_edit_api already DEFAULT).
     *
     * @param  list<array<string, mixed>>  $extra
     */
    private function zonePayload(array $extra = [], string $soaEditApi = 'DEFAULT'): array
    {
        return [
            'name' => 'example.com.',
            'kind' => 'Native',
            'serial' => 2026100901,
            'soa_edit_api' => $soaEditApi,
            'rrsets' => [[
                'name' => 'example.com.',
                'type' => 'SOA',
                'ttl' => 86400,
                'records' => [['content' => 'ns1.panel.example.com. admin.panel.example.com. 2026100901 10800 3600 604800 3600', 'disabled' => false]],
            ], [
                'name' => 'example.com.',
                'type' => 'A',
                'ttl' => 3600,
                'records' => [['content' => '203.0.113.10', 'disabled' => false]],
            ], ...$extra],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.pdns.key' => 'test-key',
            'panel.pdns.ns_domain' => 'panel.example.com',
            'panel.domain' => 'panel.example.com',
        ]);
    }

    /**
     * Status probe + zone lookup + zone detail, then 204 for every mutation.
     */
    private function fakeOk(array $extra = []): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=example.com.' => Http::response([['name' => 'example.com.']], 200),
            self::API.'/zones/example.com.' => Http::sequence()
                ->push($this->zonePayload($extra), 200)
                ->push(['result' => 'ok'], 204),
        ]);
    }

    public function test_adding_a_record_patches_the_rrset(): void
    {
        $this->fakeOk();

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->post(route('dns.records.store', 'example.com'), [
                'name' => 'www',
                'type' => 'A',
                'content' => '203.0.113.20',
                'ttl' => '300',
            ])
            ->assertRedirect(route('dns.show', 'example.com'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $rrsets = $request->data()['rrsets'] ?? [];

            return $rrsets !== []
                && $rrsets[0]['name'] === 'www.example.com.'
                && $rrsets[0]['type'] === 'A'
                && $rrsets[0]['ttl'] === 300
                && $rrsets[0]['changetype'] === 'REPLACE'
                && array_column($rrsets[0]['records'], 'content') === ['203.0.113.20'];
        });
    }

    public function test_adding_a_duplicate_record_is_rejected_without_a_patch(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=example.com.' => Http::response([['name' => 'example.com.']], 200),
            self::API.'/zones/example.com.' => Http::response($this->zonePayload(), 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->post(route('dns.records.store', 'example.com'), [
                'name' => '@',
                'type' => 'A',
                'content' => '203.0.113.10',
            ])
            ->assertRedirect(route('dns.show', 'example.com'))
            ->assertSessionHasErrors('content');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
    }

    public function test_invalid_record_content_never_reaches_the_api(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->post(route('dns.records.store', 'example.com'), [
                'name' => '@',
                'type' => 'A',
                'content' => 'not-an-ip',
            ])
            ->assertSessionHasErrors('content');

        // Pure input validation — the API must not be touched at all.
        Http::assertSentCount(0);
    }

    public function test_the_soa_record_cannot_be_deleted(): void
    {
        $this->fakeOk();

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->delete(route('dns.records.destroy', 'example.com'), [
                'name' => '@',
                'type' => 'SOA',
                'content' => 'ns1.panel.example.com. admin.panel.example.com. 2026100901 10800 3600 604800 3600',
            ])
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
    }

    public function test_updating_a_record_replaces_the_content(): void
    {
        $this->fakeOk();

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->put(route('dns.records.update', 'example.com'), [
                'old_name' => '@',
                'old_type' => 'A',
                'old_content' => '203.0.113.10',
                'name' => '@',
                'type' => 'A',
                'content' => '203.0.113.99',
                'ttl' => '3600',
            ])
            ->assertRedirect(route('dns.show', 'example.com'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $rrsets = $request->data()['rrsets'] ?? [];

            return $rrsets !== []
                && $rrsets[0]['name'] === 'example.com.'
                && array_column($rrsets[0]['records'], 'content') === ['203.0.113.99'];
        });
    }

    public function test_deleting_the_last_record_of_a_set_uses_changetype_delete(): void
    {
        $this->fakeOk([
            [
                'name' => 'www.example.com.',
                'type' => 'A',
                'ttl' => 300,
                'records' => [['content' => '203.0.113.50', 'disabled' => false]],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->delete(route('dns.records.destroy', 'example.com'), [
                'name' => 'www',
                'type' => 'A',
                'content' => '203.0.113.50',
            ])
            ->assertSessionHas('success');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $rrsets = $request->data()['rrsets'] ?? [];

            return $rrsets !== []
                && $rrsets[0]['name'] === 'www.example.com.'
                && $rrsets[0]['changetype'] === 'DELETE';
        });
    }

    public function test_legacy_zones_get_so_a_edit_api_normalized_first(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=example.com.' => Http::response([['name' => 'example.com.']], 200),
            self::API.'/zones/example.com.' => Http::sequence()
                ->push($this->zonePayload([], ''), 200)
                ->push([], 204)
                ->push([], 204),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.show', 'example.com'))
            ->post(route('dns.records.store', 'example.com'), [
                'name' => 'mail',
                'type' => 'A',
                'content' => '203.0.113.25',
            ])
            ->assertSessionHas('success');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && ($request->data()['soa_edit_api'] ?? null) === 'DEFAULT';
        });
    }

    public function test_records_for_a_missing_zone_redirect_with_an_error(): void
    {
        Http::fake([
            self::API => Http::response(['vendor' => 'PowerDNS'], 200),
            self::API.'/zones?zone=ghost.example.*' => Http::response([], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('dns.index'))
            ->post(route('dns.records.store', 'ghost.example'), [
                'name' => '@',
                'type' => 'A',
                'content' => '203.0.113.1',
            ])
            ->assertRedirect(route('dns.index'))
            ->assertSessionHas('error');
    }
}
