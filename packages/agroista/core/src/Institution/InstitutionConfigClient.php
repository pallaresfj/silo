<?php

namespace Agroista\Core\Institution;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class InstitutionConfigClient
{
    /**
     * @return array<string, mixed>
     */
    public function getInstitution(): array
    {
        return $this->getJson('/institution');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getApps(): array
    {
        /** @var array<int, array<string, mixed>> $apps */
        $apps = $this->getJson('/apps');

        return $apps;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $url = rtrim((string) config('agroista-core.institution.api_base', ''), '/').$path;

        if ($url === $path) {
            throw new RuntimeException('Missing AUTH_API_BASE / agroista-core.institution.api_base configuration.');
        }

        $request = Http::acceptJson()->timeout((int) config('agroista-core.sso.http_timeout', 10));
        $token = trim((string) config('agroista-core.institution.api_token', ''));

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->get($url);

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->body());
            throw new RuntimeException('Institution API error: '.Str::limit($message, 240));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return $payload;
    }
}
