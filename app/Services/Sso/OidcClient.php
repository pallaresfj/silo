<?php

namespace App\Services\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OidcClient
{
    public function buildAuthorizationUrl(
        string $state,
        string $codeChallenge,
        string $nonce,
        ?string $prompt = null,
        ?string $redirectUri = null
    ): string
    {
        $redirectUri = $this->resolveRedirectUri($redirectUri);

        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ];

        $prompt = trim((string) ($prompt ?? config('sso.prompt', 'login')));

        if ($prompt !== '') {
            $params['prompt'] = $prompt;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $this->authorizationEndpoint().'?'.$query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCodeForTokens(string $code, string $codeVerifier, ?string $redirectUri = null): array
    {
        $redirectUri = $this->resolveRedirectUri($redirectUri);

        $response = Http::asForm()
            ->timeout($this->httpTimeout())
            ->post($this->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $redirectUri,
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ]);

        if ($response->failed()) {
            $message = (string) ($response->json('error_description') ?? $response->json('error') ?? $response->body());
            throw new RuntimeException('Token endpoint error: '.Str::limit($message, 240));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateIdToken(string $idToken): array
    {
        $jwks = $this->jwks();
        $keys = JWK::parseKeySet($jwks);

        if ($keys === []) {
            throw new RuntimeException('No signing keys available from JWKS.');
        }

        $decoded = JWT::decode($idToken, $keys);

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $claims['iss'] = (string) ($claims['iss'] ?? '');
        $claims['aud'] = $claims['aud'] ?? null;
        $claims['exp'] = (int) ($claims['exp'] ?? 0);

        if ($claims['iss'] !== $this->issuer()) {
            throw new RuntimeException('Invalid token issuer.');
        }

        if (! $this->audienceContainsClientId($claims['aud'])) {
            throw new RuntimeException('Invalid token audience.');
        }

        if ($claims['exp'] <= now()->timestamp) {
            throw new RuntimeException('ID token is expired.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $idTokenClaims
     * @return array<string, mixed>
     */
    public function resolveClaims(array $tokens, array $idTokenClaims): array
    {
        $claims = $idTokenClaims;
        $accessToken = (string) ($tokens['access_token'] ?? '');

        if ($accessToken !== '' && $this->missingIdentityClaims($claims)) {
            $userinfo = $this->userinfo($accessToken);
            $claims = array_merge($userinfo, $claims);
        }

        if (isset($claims['email'])) {
            $claims['email'] = Str::lower(trim((string) $claims['email']));
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function discovery(): array
    {
        $cacheKey = 'sso.discovery.'.sha1($this->discoveryUrl());

        /** @var array<string, mixed> $discovery */
        $discovery = Cache::remember($cacheKey, $this->discoveryCacheSeconds(), function (): array {
            $response = Http::timeout($this->httpTimeout())->get($this->discoveryUrl());

            if ($response->failed()) {
                throw new RuntimeException('Unable to fetch OIDC discovery document.');
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json();

            return $payload;
        });

        return $discovery;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        $jwksUrl = (string) ($this->discovery()['jwks_uri'] ?? '');
        $cacheKey = 'sso.jwks.'.sha1($jwksUrl);

        /** @var array<string, mixed> $jwks */
        $jwks = Cache::remember($cacheKey, $this->jwksCacheSeconds(), function () use ($jwksUrl): array {
            if ($jwksUrl === '') {
                throw new RuntimeException('Missing jwks_uri in discovery document.');
            }

            $response = Http::timeout($this->httpTimeout())->get($jwksUrl);

            if ($response->failed()) {
                throw new RuntimeException('Unable to fetch JWKS.');
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json();

            return $payload;
        });

        return $jwks;
    }

    /**
     * @return array<string, mixed>
     */
    private function userinfo(string $accessToken): array
    {
        $endpoint = (string) ($this->discovery()['userinfo_endpoint'] ?? '');

        if ($endpoint === '') {
            $endpoint = rtrim($this->issuer(), '/').'/oauth/userinfo';
        }

        $response = Http::timeout($this->httpTimeout())
            ->acceptJson()
            ->withToken($accessToken)
            ->get($endpoint);

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch userinfo.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function missingIdentityClaims(array $claims): bool
    {
        return blank($claims['email'] ?? null) || blank($claims['name'] ?? null) || blank($claims['sub'] ?? null);
    }

    private function audienceContainsClientId(mixed $audience): bool
    {
        $clientId = $this->clientId();

        if (is_string($audience)) {
            return hash_equals($audience, $clientId);
        }

        if (is_array($audience)) {
            return in_array($clientId, array_map(static fn (mixed $item): string => (string) $item, $audience), true);
        }

        return false;
    }

    private function authorizationEndpoint(): string
    {
        return (string) ($this->discovery()['authorization_endpoint'] ?? rtrim($this->issuer(), '/').'/oauth/authorize');
    }

    private function tokenEndpoint(): string
    {
        return (string) ($this->discovery()['token_endpoint'] ?? rtrim($this->issuer(), '/').'/oauth/token');
    }

    private function discoveryUrl(): string
    {
        return (string) config('sso.discovery_url', '');
    }

    private function issuer(): string
    {
        return (string) config('sso.issuer', '');
    }

    private function clientId(): string
    {
        return (string) config('sso.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('sso.client_secret', '');
    }

    private function redirectUri(): string
    {
        return (string) config('sso.redirect_uri', '');
    }

    private function resolveRedirectUri(?string $redirectUri): string
    {
        $resolved = trim((string) ($redirectUri ?? ''));

        return $resolved !== '' ? $resolved : $this->redirectUri();
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        /** @var array<int, string> $scopes */
        $scopes = config('sso.scopes', ['openid', 'email', 'profile']);

        return $scopes;
    }

    private function httpTimeout(): int
    {
        return (int) config('sso.http_timeout', 10);
    }

    private function discoveryCacheSeconds(): int
    {
        return (int) config('sso.discovery_cache_seconds', 3600);
    }

    private function jwksCacheSeconds(): int
    {
        return (int) config('sso.jwks_cache_seconds', 3600);
    }
}
