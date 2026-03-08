<?php

namespace Agroista\Core\Institution;

use Illuminate\Support\Facades\Cache;

class InstitutionContext
{
    public function __construct(private readonly InstitutionConfigClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function institution(): array
    {
        $ttl = max(30, (int) config('agroista-core.institution.cache_ttl', 300));

        /** @var array<string, mixed> $data */
        $data = Cache::remember('agroista-core.institution', $ttl, fn (): array => $this->client->getInstitution());

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apps(): array
    {
        $ttl = max(30, (int) config('agroista-core.institution.cache_ttl', 300));

        /** @var array<int, array<string, mixed>> $data */
        $data = Cache::remember('agroista-core.institution.apps', $ttl, fn (): array => $this->client->getApps());

        return $data;
    }

    public function clear(): void
    {
        Cache::forget('agroista-core.institution');
        Cache::forget('agroista-core.institution.apps');
    }
}
