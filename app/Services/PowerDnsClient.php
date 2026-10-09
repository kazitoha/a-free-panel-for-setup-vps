<?php

namespace App\Services;

use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin typed wrapper over the PowerDNS HTTP API (localhost + X-API-Key).
 */
class PowerDnsClient
{
    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->request('GET', '');
    }

    /**
     * List zones, optionally filtered by exact zone name (?zone=…).
     *
     * @return list<array<string, mixed>>
     */
    public function zones(?string $only = null): array
    {
        $path = '/zones'.($only !== null ? '?zone='.rawurlencode($only) : '');

        return $this->request('GET', $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function zone(string $zone): array
    {
        return $this->request('GET', '/zones/'.$this->encode($zone));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createZone(array $payload): array
    {
        return $this->request('POST', '/zones', $payload);
    }

    /**
     * @param  list<array<string, mixed>>  $changes  rrset change objects
     */
    public function patchZone(string $zone, array $changes): void
    {
        $this->request('PATCH', '/zones/'.$this->encode($zone), ['rrsets' => $changes]);
    }

    /**
     * Modify zone metadata (e.g. soa_edit_api).
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateZone(string $zone, array $payload): void
    {
        $this->request('PUT', '/zones/'.$this->encode($zone), $payload);
    }

    public function deleteZone(string $zone): void
    {
        $this->request('DELETE', '/zones/'.$this->encode($zone));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws PowerDnsUnavailable
     * @throws PowerDnsError
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $base = rtrim((string) config('panel.pdns.url'), '/');
        $server = (string) config('panel.pdns.server', 'localhost');
        $url = $base.'/api/v1/servers/'.$server.$path;

        try {
            $response = match ($method) {
                'GET' => Http::withHeaders($this->headers())->timeout(15)->connectTimeout(3)->get($url),
                'POST' => Http::withHeaders($this->headers())->timeout(15)->connectTimeout(3)->post($url, $payload),
                'PATCH' => Http::withHeaders($this->headers())->timeout(15)->connectTimeout(3)->patch($url, $payload),
                'PUT' => Http::withHeaders($this->headers())->timeout(15)->connectTimeout(3)->put($url, $payload),
                'DELETE' => Http::withHeaders($this->headers())->timeout(15)->connectTimeout(3)->delete($url),
            };
        } catch (ConnectionException $exception) {
            throw PowerDnsUnavailable::from($exception);
        }

        if ($response->failed()) {
            if ($response->status() === 401 || $response->status() === 403) {
                throw new PowerDnsError('PowerDNS rejected the API key — check PDNS_API_KEY in .env.');
            }

            throw new PowerDnsError($this->message($response->status(), $response->json()));
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-API-Key' => (string) config('panel.pdns.key'),
            'Accept' => 'application/json',
        ];
    }

    private function message(int $status, mixed $json): string
    {
        if (is_array($json)) {
            foreach (['errors', 'message', 'error'] as $key) {
                $value = $json[$key] ?? null;

                if (is_array($value) && isset($value[0])) {
                    $value = is_array($value[0]) ? ($value[0]['message'] ?? null) : $value[0];
                }

                if (is_string($value) && $value !== '') {
                    return "PowerDNS said: {$value}";
                }
            }
        }

        return "PowerDNS rejected the request (HTTP {$status}).";
    }

    private function encode(string $zone): string
    {
        return rawurlencode($zone);
    }
}
