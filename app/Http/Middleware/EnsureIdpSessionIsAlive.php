<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdpSessionIsAlive
{
    private const SESSION_CHECK_IN_PROGRESS = 'sso.session_check.in_progress';

    private const SESSION_CHECK_STARTED_AT = 'sso.session_check.started_at';

    private const SESSION_CHECK_RETURN_TO = 'sso.session_check.return_to';

    private const SESSION_CHECK_LAST_AT = 'sso.session_check.last_checked_at';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        if (! config('sso.session_check_enabled', true)) {
            return $next($request);
        }

        if (! Auth::guard('web')->check()) {
            return $next($request);
        }

        if (! $this->mustCheckRequest($request)) {
            return $next($request);
        }

        if ($request->session()->get(self::SESSION_CHECK_IN_PROGRESS, false)) {
            return $this->handleInProgressState($request, $next);
        }

        $lastCheckedAt = (int) $request->session()->get(self::SESSION_CHECK_LAST_AT, 0);
        $interval = max(10, (int) config('sso.session_check_interval_seconds', 60));

        if ($lastCheckedAt > 0 && (now()->timestamp - $lastCheckedAt) < $interval) {
            return $next($request);
        }

        $request->session()->put(self::SESSION_CHECK_IN_PROGRESS, true);
        $request->session()->put(self::SESSION_CHECK_STARTED_AT, now()->timestamp);
        $request->session()->put(self::SESSION_CHECK_RETURN_TO, $request->fullUrl());

        return redirect()->route('sso.session-check.start');
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function handleInProgressState(Request $request, Closure $next): Response
    {
        $startedAt = (int) $request->session()->get(self::SESSION_CHECK_STARTED_AT, 0);
        $timeout = 12;

        if ($startedAt > 0 && (now()->timestamp - $startedAt) <= $timeout) {
            return $next($request);
        }

        $request->session()->forget([
            self::SESSION_CHECK_IN_PROGRESS,
            self::SESSION_CHECK_STARTED_AT,
            self::SESSION_CHECK_RETURN_TO,
        ]);
        $request->session()->put(self::SESSION_CHECK_LAST_AT, now()->timestamp);

        return $next($request);
    }

    private function mustCheckRequest(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->expectsJson() || $request->ajax()) {
            return false;
        }

        if ($request->is('sso/login') || $request->is('sso/callback')) {
            return false;
        }

        if ($request->is('sso/session-check/start') || $request->is('sso/session-check/callback')) {
            return false;
        }

        if ($request->is('auth/logout') || $request->is('auth/google/redirect') || $request->is('auth/google/callback')) {
            return false;
        }

        return $request->is('admin') || $request->is('admin/*');
    }
}
