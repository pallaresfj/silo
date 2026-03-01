<?php

use App\Filament\Auth\Responses\LogoutResponse;
use Illuminate\Http\Request;

it('redirects filament logout to auth logout with continue and source', function () {
    config()->set('sso.idp_logout_url', 'http://localhost:9000/logout');

    $response = (new LogoutResponse())->toResponse(Request::create('/admin/logout', 'POST'));

    $targetUrl = $response->getTargetUrl();
    $parts = parse_url($targetUrl);

    $origin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '');

    if (isset($parts['port'])) {
        $origin .= ':'.$parts['port'];
    }

    expect($origin.($parts['path'] ?? ''))
        ->toBe('http://localhost:9000/logout');

    parse_str((string) ($parts['query'] ?? ''), $query);

    expect($query['source'] ?? null)->toBe('silo');
    expect($query['continue'] ?? null)->toContain('/admin/login');
});
