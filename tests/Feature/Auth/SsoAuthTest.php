<?php

use App\Models\User;
use App\Services\Sso\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects legacy google routes to sso login', function () {
    $this->get('/auth/google/redirect')->assertRedirect('/sso/login');
    $this->get('/auth/google/callback')->assertRedirect('/sso/login');
});

it('rejects callback when state does not match', function () {
    $response = $this
        ->withSession([
            'sso.state' => 'expected-state',
            'sso.nonce' => 'nonce-123',
            'sso.code_verifier' => 'verifier-123',
        ])
        ->get('/sso/callback?code=abc&state=bad-state');

    $response->assertRedirect('/admin/login');
    $this->assertGuest();
});

it('creates or updates local user and authenticates with valid sso callback', function () {
    $mock = Mockery::mock(OidcClient::class);
    $this->app->instance(OidcClient::class, $mock);

    $mock->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->with('auth-code', 'verifier-123')
        ->andReturn([
            'id_token' => 'id-token',
            'access_token' => 'access-token',
        ]);

    $mock->shouldReceive('validateIdToken')
        ->once()
        ->with('id-token')
        ->andReturn([
            'nonce' => 'nonce-123',
        ]);

    $mock->shouldReceive('resolveClaims')
        ->once()
        ->andReturn([
            'sub' => 'subject-1',
            'email' => 'Docente@iedagropivijay.edu.co',
            'name' => 'Docente SSO',
            'is_active' => true,
        ]);

    $response = $this
        ->withSession([
            'sso.state' => 'expected-state',
            'sso.nonce' => 'nonce-123',
            'sso.code_verifier' => 'verifier-123',
        ])
        ->get('/sso/callback?code=auth-code&state=expected-state');

    $response->assertRedirect('/admin');
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'docente@iedagropivijay.edu.co')->first();

    expect($user)->not->toBeNull();
    expect($user?->google_subject)->toBe('subject-1');
    expect($user?->last_google_login_at)->not->toBeNull();
});

it('starts silent session check with prompt none', function () {
    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    config()->set('sso.session_check_prompt', 'none');

    $mock = Mockery::mock(OidcClient::class);
    $this->app->instance(OidcClient::class, $mock);

    $mock->shouldReceive('buildAuthorizationUrl')
        ->once()
        ->withArgs(function (string $state, string $challenge, string $nonce, ?string $prompt): bool {
            return $state !== '' && $challenge !== '' && $nonce !== '' && $prompt === 'none';
        })
        ->andReturn('http://localhost:9000/oauth/authorize?prompt=none');

    $response = $this
        ->actingAs($user, 'web')
        ->withSession(['sso.session_check.return_to' => 'http://localhost:8000/admin'])
        ->get('/sso/session-check/start');

    $response->assertRedirect('http://localhost:9000/oauth/authorize?prompt=none');
});

it('logs out local session when silent session check returns login_required', function () {
    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->withSession([
            'sso.session_check.in_progress' => true,
            'sso.session_check.return_to' => 'http://localhost:8000/admin',
        ])
        ->get('/sso/session-check/callback?error=login_required');

    $response->assertRedirect('/admin/login');
    $response->assertSessionHasErrors('sso');
    $this->assertGuest('web');
});
