<?php

use App\Models\AllowedGoogleAccount;
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

it('allows login with a verified whitelisted institutional google account', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    $role = Role::create([
        'name' => 'Editor',
        'slug' => 'editor',
        'is_system' => true,
    ]);

    AllowedGoogleAccount::create([
        'email' => 'docente@iedagropivijay.edu.co',
        'default_role_slug' => 'editor',
        'is_active' => true,
    ]);

    fakeGoogleUserResponse(makeGoogleUser(
        id: 'google-sub-123',
        email: 'docente@iedagropivijay.edu.co',
        verified: true,
    ));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin');
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'docente@iedagropivijay.edu.co')->first();

    expect($user)->not->toBeNull();
    expect($user?->google_subject)->toBe('google-sub-123');
    expect($user?->roles()->pluck('slug')->all())->toContain('editor');
    expect($user?->role)->toBe('editor');
    expect($user?->last_google_login_at)->not->toBeNull();
    expect($role->users()->pluck('users.id')->all())->toContain($user?->id);
});

it('rejects login when email is not in whitelist', function () {
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

it('allows login for pre-provisioned users even without whitelist', function () {
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

it('rejects login when google email is not verified', function () {
    config()->set('services.google.allowed_domain', 'iedagropivijay.edu.co');

    Role::create([
        'name' => 'Lector',
        'slug' => 'lector',
        'is_system' => true,
    ]);

    AllowedGoogleAccount::create([
        'email' => 'invalido@iedagropivijay.edu.co',
        'default_role_slug' => 'lector',
        'is_active' => true,
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
    expect(User::count())->toBe(0);
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
