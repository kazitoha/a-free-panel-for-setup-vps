<?php

namespace App\Services;

use App\Exceptions\StalwartError;
use App\Exceptions\StalwartUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Stalwart's JMAP management API.
 *
 * One method call per request (`methodCalls` with a single entry), HTTP Basic
 * auth from config('panel.stalwart.*'). The endpoint is POST /jmap — object
 * documentation historically showed /api for the same bodies, so a 404 on
 * /jmap falls back to /api once and the choice sticks for the instance.
 */
class StalwartClient
{
    private string $url;

    private ?string $user;

    private ?string $password;

    private ?string $endpoint = null;

    public function __construct()
    {
        $this->url = rtrim((string) config('panel.stalwart.url', 'http://127.0.0.1:8080'), '/');
        $this->user = (string) config('panel.stalwart.user', 'admin');
        $this->password = (string) config('panel.stalwart.password', '');
    }

    /**
     * A clone authenticated as another principal (e.g. the pinned recovery
     * admin during install, before .env credentials exist).
     */
    public function as(string $user, string $password): self
    {
        $clone = clone $this;
        $clone->user = $user;
        $clone->password = $password;

        return $clone;
    }

    /**
     * Execute one JMAP method and return its response argument.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws StalwartUnavailable when the server cannot be reached
     * @throws StalwartError on HTTP or JMAP-level errors
     */
    public function call(string $method, array $args = []): array
    {
        $payload = [
            'methodCalls' => [[$method, $args, 'c1']],
            'using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
        ];

        $endpoints = $this->endpoint !== null ? [$this->endpoint] : ['/jmap', '/api'];

        foreach ($endpoints as $endpoint) {
            $response = $this->post($endpoint, $payload);

            if ($response->status() === 404 && count($endpoints) > 1) {
                continue;
            }

            $this->endpoint = $endpoint;

            return $this->interpret($method, $response);
        }

        throw new StalwartError("Stalwart exposes no JMAP endpoint on {$this->url}.");
    }

    private function post(string $endpoint, array $payload): Response
    {
        try {
            $request = Http::timeout(30)->connectTimeout(3)->acceptJson();

            if ($this->password !== '') {
                $request = $request->withBasicAuth($this->user, $this->password);
            }

            return $request->post($this->url.$endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw StalwartUnavailable::from($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(string $method, Response $response): array
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new StalwartError(
                'Stalwart rejected the credentials — check STALWART_USER and STALWART_PASSWORD in .env.',
                unauthorized: true,
            );
        }

        if ($status >= 400) {
            throw new StalwartError($this->httpMessage($status, $response));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new StalwartError('Stalwart returned an unreadable response.');
        }

        $first = $json['methodResponses'][0] ?? null;

        if (! is_array($first) || count($first) < 2) {
            throw new StalwartError("Stalwart returned no response for {$method}.");
        }

        if (($first[0] ?? '') === 'error') {
            $error = is_array($first[1]) ? $first[1] : [];
            $type = (string) ($error['type'] ?? 'error');
            $description = trim((string) ($error['description'] ?? ''));

            if ($description === '') {
                $description = "Stalwart rejected {$method} ({$type}).";
            }

            throw new StalwartError(
                $description,
                unauthorized: str_contains($type, 'Authentication') || str_contains($type, 'Unauthorized'),
            );
        }

        return is_array($first[1]) ? $first[1] : [];
    }

    private function httpMessage(int $status, Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            foreach (['detail', 'message', 'description', 'title', 'error'] as $key) {
                $value = $json[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    return "Stalwart said: {$value}";
                }
            }
        }

        return "Stalwart rejected the request (HTTP {$status}).";
    }
}
