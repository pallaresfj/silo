<?php

namespace App\Filament\Auth\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        $homeUrl = url('/');

        if (! (bool) config('services.google.logout_from_browser', false)) {
            return redirect()->to($homeUrl);
        }

        $host = (string) parse_url($homeUrl, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($homeUrl, PHP_URL_SCHEME));
        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        // Avoid Google/AppEngine redirect warnings in local or non-HTTPS environments.
        if ($isLocalHost || $scheme !== 'https') {
            return redirect()->to($homeUrl);
        }

        // Best-effort Google account logout. This signs out Google account(s) in the browser.
        $appEngineLogoutUrl = 'https://appengine.google.com/_ah/logout?continue=' . urlencode($homeUrl);
        $googleLogoutUrl = 'https://accounts.google.com/Logout?continue=' . urlencode($appEngineLogoutUrl);

        return redirect()->away($googleLogoutUrl);
    }
}
