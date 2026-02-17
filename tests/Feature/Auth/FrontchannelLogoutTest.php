<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs out local session on valid frontchannel request', function () {
    config()->set('sso.frontchannel_logout_client_key', 'silo');
    config()->set('sso.frontchannel_logout_secret', 'shared-secret');
    config()->set('sso.frontchannel_logout_ttl_seconds', 120);
    config()->set('sso.frontchannel_logout_next_hosts', ['localhost', '127.0.0.1']);

    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    $next = 'http://localhost:9000/?logged_out=1';
    $ts = now()->timestamp;
    $sig = hash_hmac('sha256', 'silo|'.$ts.'|'.$next, 'shared-secret');

    $response = $this
        ->actingAs($user, 'web')
        ->get('/sso/frontchannel-logout?'.http_build_query([
            'client' => 'silo',
            'ts' => $ts,
            'next' => $next,
            'sig' => $sig,
        ], '', '&', PHP_QUERY_RFC3986));

    $response->assertRedirect($next);
    $this->assertGuest('web');
});

it('keeps local session on invalid frontchannel signature', function () {
    config()->set('sso.frontchannel_logout_client_key', 'silo');
    config()->set('sso.frontchannel_logout_secret', 'shared-secret');
    config()->set('sso.frontchannel_logout_ttl_seconds', 120);
    config()->set('sso.frontchannel_logout_next_hosts', ['localhost', '127.0.0.1']);

    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    $next = 'http://localhost:9000/?logged_out=1';
    $ts = now()->timestamp;

    $response = $this
        ->actingAs($user, 'web')
        ->get('/sso/frontchannel-logout?'.http_build_query([
            'client' => 'silo',
            'ts' => $ts,
            'next' => $next,
            'sig' => 'invalid-signature',
        ], '', '&', PHP_QUERY_RFC3986));

    $response->assertRedirect($next);
    $this->assertAuthenticatedAs($user, 'web');
});

it('keeps local session when frontchannel timestamp is expired', function () {
    config()->set('sso.frontchannel_logout_client_key', 'silo');
    config()->set('sso.frontchannel_logout_secret', 'shared-secret');
    config()->set('sso.frontchannel_logout_ttl_seconds', 60);
    config()->set('sso.frontchannel_logout_next_hosts', ['localhost', '127.0.0.1']);

    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    $next = 'http://localhost:9000/?logged_out=1';
    $ts = now()->subMinutes(10)->timestamp;
    $sig = hash_hmac('sha256', 'silo|'.$ts.'|'.$next, 'shared-secret');

    $response = $this
        ->actingAs($user, 'web')
        ->get('/sso/frontchannel-logout?'.http_build_query([
            'client' => 'silo',
            'ts' => $ts,
            'next' => $next,
            'sig' => $sig,
        ], '', '&', PHP_QUERY_RFC3986));

    $response->assertRedirect($next);
    $this->assertAuthenticatedAs($user, 'web');
});

it('uses safe fallback when next host is not allowed', function () {
    config()->set('sso.frontchannel_logout_client_key', 'silo');
    config()->set('sso.frontchannel_logout_secret', 'shared-secret');
    config()->set('sso.frontchannel_logout_ttl_seconds', 120);
    config()->set('sso.frontchannel_logout_next_hosts', ['localhost', '127.0.0.1']);

    $user = User::factory()->create([
        'email' => 'docente@iedagropivijay.edu.co',
        'role' => 'docente',
    ]);

    $next = 'https://evil.example/logout';
    $ts = now()->timestamp;
    $sig = hash_hmac('sha256', 'silo|'.$ts.'|'.$next, 'shared-secret');

    $response = $this
        ->actingAs($user, 'web')
        ->get('/sso/frontchannel-logout?'.http_build_query([
            'client' => 'silo',
            'ts' => $ts,
            'next' => $next,
            'sig' => $sig,
        ], '', '&', PHP_QUERY_RFC3986));

    $response->assertRedirect('/admin/login');
    $this->assertGuest('web');
});
