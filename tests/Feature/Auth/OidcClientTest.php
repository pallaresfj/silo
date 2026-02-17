<?php

use App\Services\Sso\OidcClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('adds prompt login to authorization url', function () {
    config()->set('sso.discovery_url', 'http://localhost:9000/.well-known/openid-configuration');
    config()->set('sso.issuer', 'http://localhost:9000');
    config()->set('sso.client_id', 'client-123');
    config()->set('sso.redirect_uri', 'http://localhost:8000/sso/callback');
    config()->set('sso.scopes', ['openid', 'email', 'profile']);
    config()->set('sso.prompt', 'login');

    Cache::flush();

    Http::fake([
        'http://localhost:9000/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'http://localhost:9000/oauth/authorize',
            'token_endpoint' => 'http://localhost:9000/oauth/token',
            'jwks_uri' => 'http://localhost:9000/oauth/jwks',
        ]),
    ]);

    $client = app(OidcClient::class);

    $url = $client->buildAuthorizationUrl('state-123', 'challenge-123', 'nonce-123');

    $parts = parse_url($url);

    $origin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '');

    if (isset($parts['port'])) {
        $origin .= ':'.$parts['port'];
    }

    expect($origin.($parts['path'] ?? ''))
        ->toBe('http://localhost:9000/oauth/authorize');

    parse_str((string) ($parts['query'] ?? ''), $query);

    expect($query['prompt'] ?? null)->toBe('login');
    expect($query['code_challenge_method'] ?? null)->toBe('S256');
    expect($query['state'] ?? null)->toBe('state-123');
});

it('allows overriding prompt for silent session checks', function () {
    config()->set('sso.discovery_url', 'http://localhost:9000/.well-known/openid-configuration');
    config()->set('sso.issuer', 'http://localhost:9000');
    config()->set('sso.client_id', 'client-123');
    config()->set('sso.redirect_uri', 'http://localhost:8000/sso/session-check/callback');
    config()->set('sso.scopes', ['openid', 'email', 'profile']);
    config()->set('sso.prompt', 'login');

    Cache::flush();

    Http::fake([
        'http://localhost:9000/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'http://localhost:9000/oauth/authorize',
            'token_endpoint' => 'http://localhost:9000/oauth/token',
            'jwks_uri' => 'http://localhost:9000/oauth/jwks',
        ]),
    ]);

    $client = app(OidcClient::class);

    $url = $client->buildAuthorizationUrl('state-321', 'challenge-321', 'nonce-321', 'none');
    parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);

    expect($query['prompt'] ?? null)->toBe('none');
});
