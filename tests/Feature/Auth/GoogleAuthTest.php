<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Mockery::close();
});

it('allows login for a pre-registered user with role', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    User::create([
        'name' => 'Secretaría',
        'email' => 'secretaria@iedagropivijay.edu.co',
        'password' => null,
        'role' => 'editor',
    ]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-777',
        email: 'secretaria@iedagropivijay.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin');
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'secretaria@iedagropivijay.edu.co')->first();
    expect($user)->not->toBeNull();
    expect($user?->google_subject)->toBe('google-sub-777');
    expect($user?->last_google_login_at)->not->toBeNull();
});

it('allows login for a pre-registered user with RBAC role', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    $role = Role::create([
        'name' => 'Editor',
        'slug' => 'editor',
        'is_system' => true,
    ]);

    $user = User::create([
        'name' => 'Docente',
        'email' => 'docente@iedagropivijay.edu.co',
        'password' => null,
        'role' => '',
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-123',
        email: 'docente@iedagropivijay.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin');
    $this->assertAuthenticated();
});

it('rejects login when user is not registered in silo', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-999',
        email: 'sinacceso@iedagropivijay.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('rejects login when registered user has no assigned role', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    User::create([
        'name' => 'Sin Rol',
        'email' => 'sinrol@iedagropivijay.edu.co',
        'password' => null,
        'role' => '',
    ]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-444',
        email: 'sinrol@iedagropivijay.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
    $this->assertGuest();
});

it('rejects login when google email is not verified', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    User::create([
        'name' => 'Inválido',
        'email' => 'invalido@iedagropivijay.edu.co',
        'password' => null,
        'role' => 'lector',
    ]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-555',
        email: 'invalido@iedagropivijay.edu.co',
        verified: false,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
    $this->assertGuest();
});

it('rejects login when google email is outside allowed domain', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    User::create([
        'name' => 'Externo',
        'email' => 'externo@externo.edu.co',
        'password' => null,
        'role' => 'lector',
    ]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-666',
        email: 'externo@externo.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
    $this->assertGuest();
});

function fakeGoogleUserResponse(SocialiteUser $user): void
{
    $provider = Mockery::mock(Provider::class);
    $provider
        ->shouldReceive('user')
        ->once()
        ->andReturn($user);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);
}

function makeGoogleUser(string $id, string $email, bool $verified): SocialiteUser
{
    return (new SocialiteUser())
        ->setRaw([
            'sub' => $id,
            'email' => $email,
            'email_verified' => $verified,
            'hd' => 'iedagropivijay.edu.co',
        ])
        ->map([
            'id' => $id,
            'name' => 'Usuario Prueba',
            'email' => $email,
            'avatar' => 'https://example.com/avatar.png',
        ]);
}
