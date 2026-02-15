<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AllowedGoogleAccount;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

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

        return Socialite::driver('google')
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

        $allowedAccount = AllowedGoogleAccount::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ((! $allowedAccount) && (! $this->isPreProvisionedUser($user))) {
            Log::notice('Google OAuth rejected by whitelist.', ['email' => $email]);

            return redirect()
                ->to(Filament::getLoginUrl())
                ->withErrors(['auth' => 'Tu cuenta no está autorizada para ingresar a SILO.']);
        }

        if (! $user) {
            $user = new User();
            $user->password = null;
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

        $this->assignInitialRole($user, $allowedAccount);

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

    protected function assignInitialRole(User $user, ?AllowedGoogleAccount $allowedAccount): void
    {
        if ($user->roles()->exists()) {
            if (blank($user->role)) {
                $user->role = $user->roles()->value('slug');
                $user->saveQuietly();
            }

            return;
        }

        if ($allowedAccount === null) {
            return;
        }

        $targetRoleSlug = $allowedAccount->default_role_slug ?: $this->mapLegacyRoleToSlug($user->role);

        if (blank($targetRoleSlug)) {
            return;
        }

        $role = Role::query()
            ->where('slug', Str::lower((string) $targetRoleSlug))
            ->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->role = $role->slug;
            $user->saveQuietly();
        }
    }

    protected function isPreProvisionedUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->roles()->exists() || filled($user->role);
    }

    protected function mapLegacyRoleToSlug(?string $legacyRole): ?string
    {
        return match (Str::lower((string) $legacyRole)) {
            'rector' => 'rector',
            'administrador' => 'administrador',
            'editor' => 'editor',
            'docente', 'lector' => 'lector',
            default => null,
        };
    }
}
