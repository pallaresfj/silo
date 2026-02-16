<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirectUri = config('services.google.redirect');

        if (blank($clientId) || blank($clientSecret) || blank($redirectUri)) {
            Log::error('Google OAuth is not configured. Missing OAuth env vars.', [
                'client_id_set' => filled($clientId),
                'client_secret_set' => filled($clientSecret),
                'redirect_set' => filled($redirectUri),
            ]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'OAuth de Google no está configurado. Contacta al administrador.']);
        }

        /** @var GoogleProvider $googleProvider */
        $googleProvider = Socialite::driver('google');

        return $googleProvider
            ->scopes(['openid', 'profile', 'email'])
            ->with([
                'hd' => config('services.google.allowed_domain'),
                'prompt' => 'select_account',
            ])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $exception) {
            Log::warning('Google OAuth callback failed.', [
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'No pudimos autenticar con Google. Intenta nuevamente.']);
        }

        $raw = is_array($googleUser->user) ? $googleUser->user : [];
        $subject = (string) ($googleUser->getId() ?: ($raw['sub'] ?? ''));
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $isEmailVerified = (bool) ($raw['email_verified'] ?? false);
        $allowedDomain = Str::lower((string) config('services.google.allowed_domain'));
        $allowedDomain = ltrim($allowedDomain, '@');
        $expectedEmailSuffix = "@{$allowedDomain}";

        if (
            blank($allowedDomain) ||
            blank($subject) ||
            blank($email) ||
            (! $isEmailVerified) ||
            (! Str::endsWith($email, $expectedEmailSuffix))
        ) {
            Log::warning('Google OAuth rejected by identity validation.', [
                'email' => $email,
                'subject_present' => filled($subject),
                'email_verified' => $isEmailVerified,
            ]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'La cuenta Google no cumple los requisitos de acceso.']);
        }

        $user = User::query()
            ->where('google_subject', $subject)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            Log::notice('Google OAuth rejected because user is not pre-registered.', ['email' => $email]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'Tu cuenta no está registrada en SILO. Solicita acceso al administrador.']);
        }

        if (! $this->hasAccessRole($user)) {
            Log::notice('Google OAuth rejected because user has no assigned role.', ['user_id' => $user->id, 'email' => $email]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'Tu cuenta no tiene un rol asignado en SILO. Contacta al administrador.']);
        }

        $user->fill([
            'name' => $googleUser->getName() ?: $user->name ?: $email,
            'email' => $email,
            'google_subject' => $subject,
            'google_avatar_url' => $googleUser->getAvatar(),
            'last_google_login_at' => now(),
        ]);

        if ($isEmailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        Auth::guard('web')->login($user, true);
        request()->session()->regenerate();

        Log::info('Google OAuth login successful.', ['user_id' => $user->id, 'email' => $email]);

        return redirect()->intended(Filament::getDefaultPanel()->getUrl());
    }

    public function logout(): RedirectResponse
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->to(Filament::getLoginUrl());
    }

    protected function hasAccessRole(User $user): bool
    {
        return $user->roles()->exists() || filled($user->role);
    }
}
