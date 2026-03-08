<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Agroista\Core\Sso\OidcClient;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SsoController extends Controller
{
    private const LOGIN_STATE = 'sso.state';

    private const LOGIN_NONCE = 'sso.nonce';

    private const LOGIN_VERIFIER = 'sso.code_verifier';

    private const SESSION_CHECK_STATE = 'sso.session_check.state';

    private const SESSION_CHECK_NONCE = 'sso.session_check.nonce';

    private const SESSION_CHECK_VERIFIER = 'sso.session_check.code_verifier';

    private const SESSION_CHECK_IN_PROGRESS = 'sso.session_check.in_progress';

    private const SESSION_CHECK_STARTED_AT = 'sso.session_check.started_at';

    private const SESSION_CHECK_RETURN_TO = 'sso.session_check.return_to';

    private const SESSION_CHECK_LAST_AT = 'sso.session_check.last_checked_at';

    private const SESSION_CHECK_REDIRECT_URI = 'sso.session_check.redirect_uri';

    public function __construct(private readonly OidcClient $oidcClient)
    {
    }

    public function login(Request $request): RedirectResponse
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $codeVerifier = Str::random(96);
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

        $request->session()->put([
            self::LOGIN_STATE => $state,
            self::LOGIN_NONCE => $nonce,
            self::LOGIN_VERIFIER => $codeVerifier,
        ]);

        return redirect()->away($this->oidcClient->buildAuthorizationUrl($state, $codeChallenge, $nonce));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check() && ! $request->session()->has(self::LOGIN_STATE)) {
            return redirect()->to(Filament::getUrl());
        }

        if ($request->filled('error')) {
            return $this->fail('No se pudo completar la autenticación institucional.');
        }

        $expectedState = (string) $request->session()->pull(self::LOGIN_STATE, '');
        $expectedNonce = (string) $request->session()->pull(self::LOGIN_NONCE, '');
        $codeVerifier = (string) $request->session()->pull(self::LOGIN_VERIFIER, '');
        $receivedState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($expectedState === '' || $receivedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return $this->fail('Error de seguridad (state mismatch).');
        }

        if ($code === '' || $codeVerifier === '') {
            return $this->fail('No se recibió un código de autorización válido.');
        }

        try {
            $tokens = $this->oidcClient->exchangeCodeForTokens($code, $codeVerifier);
            $idToken = (string) ($tokens['id_token'] ?? '');

            if ($idToken === '') {
                return $this->fail('No se recibió id_token desde auth.');
            }

            $idTokenClaims = $this->oidcClient->validateIdToken($idToken);

            if ($expectedNonce === '' || (string) ($idTokenClaims['nonce'] ?? '') !== $expectedNonce) {
                return $this->fail('Error de seguridad (nonce inválido).');
            }

            $claims = $this->oidcClient->resolveClaims($tokens, $idTokenClaims);
        } catch (Throwable $exception) {
            Log::warning('SSO callback failed in silo.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->fail('Error al validar el inicio de sesión institucional.');
        }

        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        $name = trim((string) ($claims['name'] ?? $email));
        $subject = trim((string) ($claims['sub'] ?? ''));
        $institutionCode = trim((string) ($claims['institution_code'] ?? config('sso.institution_code', 'default')));
        $isActive = array_key_exists('is_active', $claims) ? (bool) $claims['is_active'] : true;
        $avatarCandidate = trim((string) ($claims['picture'] ?? $claims['avatar'] ?? $claims['google_avatar_url'] ?? ''));
        $googleAvatarUrl = filter_var($avatarCandidate, FILTER_VALIDATE_URL) ? $avatarCandidate : null;

        if ($email === '') {
            return $this->fail('No se recibió email en los claims.');
        }

        if ($subject === '') {
            return $this->fail('No se recibió sub en los claims.');
        }

        if (! $isActive) {
            return $this->fail('Tu cuenta institucional está inactiva.');
        }

        $alreadyExists = User::query()->where('email', $email)->exists();

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            array_filter([
                'name' => $name === '' ? $email : $name,
                'auth_subject' => $subject,
                'institution_code' => $institutionCode !== '' ? $institutionCode : null,
                'google_subject' => $subject,
                'google_avatar_url' => $googleAvatarUrl,
                'last_google_login_at' => now(),
                'last_sso_login_at' => now(),
                'password' => $alreadyExists ? null : Hash::make(Str::password(40)),
                'email_verified_at' => $alreadyExists ? null : now(),
            ], static fn (mixed $value): bool => $value !== null),
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_CHECK_LAST_AT, now()->timestamp);

        return redirect()->intended(Filament::getUrl());
    }

    public function startSessionCheck(Request $request): RedirectResponse
    {
        if (! config('sso.session_check_enabled', true)) {
            return redirect()->to($this->pullSessionCheckReturnTo($request) ?? Filament::getUrl());
        }

        if (! Auth::guard('web')->check()) {
            return redirect()->to(Filament::getLoginUrl());
        }

        $sessionCheckRedirectUri = $this->resolveSessionCheckRedirectUri();
        $state = Str::random(64);
        $nonce = Str::random(64);
        $codeVerifier = Str::random(96);
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

        $request->session()->put([
            self::SESSION_CHECK_STATE => $state,
            self::SESSION_CHECK_NONCE => $nonce,
            self::SESSION_CHECK_VERIFIER => $codeVerifier,
            self::SESSION_CHECK_IN_PROGRESS => true,
            self::SESSION_CHECK_STARTED_AT => now()->timestamp,
            self::SESSION_CHECK_REDIRECT_URI => $sessionCheckRedirectUri,
        ]);

        $prompt = trim((string) config('sso.session_check_prompt', 'none'));

        return redirect()->away($this->oidcClient->buildAuthorizationUrl(
            $state,
            $codeChallenge,
            $nonce,
            $prompt,
            $sessionCheckRedirectUri
        ));
    }

    public function completeSessionCheck(Request $request): RedirectResponse
    {
        if (! $request->session()->get(self::SESSION_CHECK_IN_PROGRESS, false)) {
            return redirect()->to(Filament::getUrl());
        }

        $expectedState = (string) $request->session()->pull(self::SESSION_CHECK_STATE, '');
        $expectedNonce = (string) $request->session()->pull(self::SESSION_CHECK_NONCE, '');
        $codeVerifier = (string) $request->session()->pull(self::SESSION_CHECK_VERIFIER, '');
        $receivedState = (string) $request->query('state', '');
        $errorCode = trim((string) $request->query('error', ''));

        if ($errorCode !== '') {
            if (in_array($errorCode, ['login_required', 'interaction_required', 'access_denied'], true)) {
                return $this->logoutForExpiredIdpSession($request);
            }

            $returnTo = $this->pullSessionCheckReturnTo($request) ?? Filament::getUrl();
            $this->clearSessionCheckState($request);

            return redirect()->to($returnTo);
        }

        if ($expectedState === '' || $receivedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return $this->logoutForExpiredIdpSession($request);
        }

        $code = (string) $request->query('code', '');

        if ($code === '' || $codeVerifier === '') {
            return $this->logoutForExpiredIdpSession($request);
        }

        $sessionCheckRedirectUri = trim((string) $request->session()->get(self::SESSION_CHECK_REDIRECT_URI, ''));

        if ($sessionCheckRedirectUri === '') {
            $sessionCheckRedirectUri = $this->resolveSessionCheckRedirectUri();
        }

        try {
            $tokens = $this->oidcClient->exchangeCodeForTokens($code, $codeVerifier, $sessionCheckRedirectUri);
            $idToken = (string) ($tokens['id_token'] ?? '');

            if ($idToken === '') {
                return $this->logoutForExpiredIdpSession($request);
            }

            $idTokenClaims = $this->oidcClient->validateIdToken($idToken);

            if ($expectedNonce === '' || (string) ($idTokenClaims['nonce'] ?? '') !== $expectedNonce) {
                return $this->logoutForExpiredIdpSession($request);
            }

            $claims = $this->oidcClient->resolveClaims($tokens, $idTokenClaims);
        } catch (Throwable $exception) {
            Log::warning('Silent SSO session check failed in silo.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->logoutForExpiredIdpSession($request);
        }

        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if (! $user) {
            return $this->logoutForExpiredIdpSession($request);
        }

        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        $subject = trim((string) ($claims['sub'] ?? ''));
        $institutionCode = trim((string) ($claims['institution_code'] ?? config('sso.institution_code', 'default')));
        $isActive = array_key_exists('is_active', $claims) ? (bool) $claims['is_active'] : true;

        if ($email === '' || $subject === '' || ! $isActive) {
            return $this->logoutForExpiredIdpSession($request);
        }

        if (! hash_equals(Str::lower($user->email), $email)) {
            return $this->logoutForExpiredIdpSession($request);
        }

        if (filled($user->google_subject) && ! hash_equals((string) $user->google_subject, $subject)) {
            return $this->logoutForExpiredIdpSession($request);
        }

        if (filled($user->auth_subject) && ! hash_equals((string) $user->auth_subject, $subject)) {
            return $this->logoutForExpiredIdpSession($request);
        }

        $name = trim((string) ($claims['name'] ?? ''));
        $avatarCandidate = trim((string) ($claims['picture'] ?? $claims['avatar'] ?? $claims['google_avatar_url'] ?? ''));
        $googleAvatarUrl = filter_var($avatarCandidate, FILTER_VALIDATE_URL) ? $avatarCandidate : null;

        if ($name !== '') {
            $user->name = $name;
        }

        if ($googleAvatarUrl !== null) {
            $user->google_avatar_url = $googleAvatarUrl;
        }

        $user->auth_subject = $subject;
        $user->institution_code = $institutionCode !== '' ? $institutionCode : $user->institution_code;
        $user->last_google_login_at = now();
        $user->last_sso_login_at = now();
        $user->save();

        $request->session()->put(self::SESSION_CHECK_LAST_AT, now()->timestamp);
        $returnTo = $this->pullSessionCheckReturnTo($request) ?? Filament::getUrl();
        $this->clearSessionCheckState($request);

        return redirect()->to($returnTo);
    }

    public function frontchannelLogout(Request $request): RedirectResponse
    {
        $rawNext = trim((string) $request->query('next', ''));
        $safeNext = $this->resolveFrontchannelNextUrl($rawNext) ?? Filament::getLoginUrl();
        $client = mb_strtolower(trim((string) $request->query('client', '')));
        $timestamp = (int) $request->query('ts', 0);
        $signature = trim((string) $request->query('sig', ''));
        $expectedClient = mb_strtolower(trim((string) config('sso.frontchannel_logout_client_key', 'silo')));
        $secret = trim((string) config('sso.frontchannel_logout_secret', ''));
        $ttl = max(10, (int) config('sso.frontchannel_logout_ttl_seconds', 120));
        $now = now()->timestamp;
        $signatureIsValid = false;

        if (
            $rawNext !== ''
            && $client !== ''
            && $expectedClient !== ''
            && $secret !== ''
            && hash_equals($expectedClient, $client)
            && $timestamp > 0
            && abs($now - $timestamp) <= $ttl
            && $signature !== ''
        ) {
            $expectedSignature = hash_hmac('sha256', $client.'|'.$timestamp.'|'.$rawNext, $secret);
            $signatureIsValid = hash_equals($expectedSignature, $signature);
        }

        if ($signatureIsValid) {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $response = redirect()->to($safeNext);

            $response->withCookie(Cookie::forget(
                config('session.cookie'),
                config('session.path', '/'),
                config('session.domain')
            ));

            return $response;
        }

        Log::warning('Invalid frontchannel logout request in silo.', [
            'client' => $client !== '' ? $client : null,
            'expected_client' => $expectedClient !== '' ? $expectedClient : null,
            'timestamp' => $timestamp,
            'raw_next' => $rawNext !== '' ? $rawNext : null,
        ]);

        return redirect()->to($safeNext);
    }

    private function logoutForExpiredIdpSession(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->to(Filament::getLoginUrl())
            ->withErrors(['sso' => 'Se cerró tu sesión local porque la sesión institucional ya no está activa.']);
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()
            ->to(Filament::getLoginUrl())
            ->withErrors(['sso' => $message]);
    }

    private function pullSessionCheckReturnTo(Request $request): ?string
    {
        $returnTo = trim((string) $request->session()->pull(self::SESSION_CHECK_RETURN_TO, ''));

        return $returnTo !== '' ? $returnTo : null;
    }

    private function resolveSessionCheckRedirectUri(): string
    {
        $configured = trim((string) config('sso.session_check_redirect_uri', ''));

        return $configured !== '' ? $configured : url('/sso/session-check/callback');
    }

    private function clearSessionCheckState(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_CHECK_IN_PROGRESS,
            self::SESSION_CHECK_STARTED_AT,
            self::SESSION_CHECK_STATE,
            self::SESSION_CHECK_NONCE,
            self::SESSION_CHECK_VERIFIER,
            self::SESSION_CHECK_RETURN_TO,
            self::SESSION_CHECK_REDIRECT_URI,
        ]);
    }

    private function resolveFrontchannelNextUrl(string $next): ?string
    {
        if ($next === '') {
            return null;
        }

        if (str_starts_with($next, '/')) {
            $next = url($next);
        }

        $parts = parse_url($next);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower((string) $parts['scheme']);
        $host = mb_strtolower((string) $parts['host']);
        $allowedHosts = config('sso.frontchannel_logout_next_hosts', []);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (! in_array($host, $allowedHosts, true)) {
            return null;
        }

        return $next;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
