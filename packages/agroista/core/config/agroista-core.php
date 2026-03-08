<?php

return [
    'sso' => [
        'issuer' => env('SSO_ISSUER', ''),
        'discovery_url' => env('SSO_DISCOVERY_URL', ''),
        'client_id' => env('SSO_CLIENT_ID', ''),
        'client_secret' => env('SSO_CLIENT_SECRET', ''),
        'redirect_uri' => env('SSO_REDIRECT_URI', ''),
        'scopes' => preg_split('/\s+/', trim((string) env('SSO_SCOPES', 'openid email profile'))) ?: ['openid', 'email', 'profile'],
        'prompt' => env('SSO_PROMPT', 'login'),
        'http_timeout' => (int) env('SSO_HTTP_TIMEOUT', 10),
        'discovery_cache_seconds' => (int) env('SSO_DISCOVERY_CACHE_SECONDS', 3600),
        'jwks_cache_seconds' => (int) env('SSO_JWKS_CACHE_SECONDS', 3600),
    ],

    'institution' => [
        'api_base' => env('AUTH_API_BASE', ''),
        'api_token' => env('AUTH_API_TOKEN', ''),
        'cache_ttl' => (int) env('AUTH_INSTITUTION_CACHE_TTL', 300),
        'default_code' => env('INSTITUTION_CODE', 'default'),
    ],

    'identity' => [
        'binding' => env('AUTH_IDENTITY_BINDING', 'subject_with_email_fallback'),
    ],
];
