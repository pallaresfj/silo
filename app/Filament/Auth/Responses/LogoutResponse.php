<?php

namespace App\Filament\Auth\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        $idpLogoutUrl = trim((string) config('sso.idp_logout_url', ''));
        $fallbackUrl = url('/');
        $continueUrl = $this->normalizeUrl(Filament::getLoginUrl()) ?? $fallbackUrl;

        if ($idpLogoutUrl === '') {
            return redirect()->to($continueUrl);
        }

        $idpUrl = $this->appendQuery($idpLogoutUrl, [
            'continue' => $continueUrl,
            'source' => config('sso.frontchannel_logout_client_key', 'silo'),
        ]);

        return redirect()->away($idpUrl);
    }

    private function normalizeUrl(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return url($trimmed);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
