<?php

namespace App\Support\Pilot;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class PilotApiClient
{
    private array $cookies = [];

    private string $csrfToken = '';

    private array $calls = [];

    public function __construct(
        private readonly User $user,
        private readonly int $organizationId,
        private readonly int $branchId,
    ) {
        $response = $this->send('GET', '/api/v1/auth/csrf', [], [], false);
        $this->csrfToken = (string) ($this->decode($response)['data']['token'] ?? '');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    public function get(string $path, int $expected = 200): array
    {
        return $this->request('GET', $path, [], null, $expected);
    }

    public function post(string $path, array $payload, ?string $key = null, int $expected = 200): array
    {
        return $this->request('POST', $path, $payload, $key, $expected);
    }

    public function calls(): array
    {
        return $this->calls;
    }

    private function request(string $method, string $path, array $payload, ?string $key, int $expected): array
    {
        $headers = $key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];
        $started = hrtime(true);
        $response = $this->send($method, $path, $payload, $headers);
        $duration = round((hrtime(true) - $started) / 1_000_000, 2);
        $body = $this->decode($response);
        $this->calls[] = [
            'method' => $method,
            'path' => preg_replace('/\/\d+(?=\/|$)/', '/{id}', $path),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'request_id' => $response->headers->get('X-Request-ID'),
        ];
        if ($response->getStatusCode() !== $expected) {
            throw new RuntimeException(sprintf(
                '%s %s devolvió %d; se esperaba %d: %s',
                $method,
                $path,
                $response->getStatusCode(),
                $expected,
                json_encode($body, JSON_UNESCAPED_SLASHES),
            ));
        }

        return [
            'body' => $body,
            'headers' => $response->headers->all(),
        ];
    }

    private function send(
        string $method,
        string $path,
        array $payload = [],
        array $headers = [],
        bool $tenant = true,
    ): Response {
        Auth::guard()->setUser($this->user);
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken,
            ...$headers,
        ];
        if ($tenant) {
            $server['HTTP_X_ORGANIZATION_ID'] = (string) $this->organizationId;
            $server['HTTP_X_BRANCH_ID'] = (string) $this->branchId;
        }
        $content = $method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create($path, $method, [], $this->cookies, [], $server, $content);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie instanceof Cookie) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $kernel->terminate($request, $response);

        return $response;
    }

    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
