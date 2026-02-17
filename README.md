<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## SILO Deployment

- Production runbook: `docs/DEPLOYMENT_PRODUCTION.md`
- Local release helper: `scripts/deploy/local-release.sh`
- Server first deploy helper: `scripts/deploy/server-first-deploy.sh`
- Server update helper: `scripts/deploy/server-update.sh`

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## SSO con auth (OIDC)

Variables requeridas en `.env`:

```dotenv
SSO_DISCOVERY_URL=http://localhost:8000/.well-known/openid-configuration
SSO_ISSUER=http://localhost:8000
SSO_CLIENT_ID=
SSO_CLIENT_SECRET=
SSO_REDIRECT_URI=http://localhost:8000/sso/callback
SSO_SCOPES="openid email profile"
SSO_HTTP_TIMEOUT=10
SSO_FRONTCHANNEL_LOGOUT_CLIENT_KEY=silo
SSO_FRONTCHANNEL_LOGOUT_SECRET=change-me-local-secret
SSO_FRONTCHANNEL_LOGOUT_TTL_SECONDS=120
SSO_FRONTCHANNEL_LOGOUT_NEXT_HOSTS=localhost,127.0.0.1,auth.iedagropivijay.edu.co,silo.iedagropivijay.edu.co
```

Validar discovery/JWKS:

```bash
curl -fsS "http://localhost:8000/.well-known/openid-configuration"
curl -fsS "http://localhost:8000/oauth/jwks"
```

Rutas:

- `GET /sso/login`
- `GET /sso/callback`
- `GET /sso/frontchannel-logout`

Flujo:

1. `/sso/login` crea `state` + `code_verifier` + `nonce` y redirige a `auth`.
2. `/sso/callback` valida `state`, canjea `code`, valida `id_token` por JWKS y autentica guard local.

Compatibilidad legacy:

- `GET /auth/google/redirect` redirige a `/sso/login`
- `GET /auth/google/callback` redirige a `/sso/login`

Validaciones realizadas en callback:

- `state` y `nonce`
- `id_token` (`iss`, `aud`, `exp` y firma RS256 con JWKS)
- `email`, `name`, `sub`
- `is_active` (si viene desde `auth`)

Provisioning local:

- `User::updateOrCreate(['email' => ...], ['name' => ...])`
- Se actualizan `google_subject`, `google_avatar_url`, `last_google_login_at`.

Troubleshooting rápido:

- `state mismatch`: limpiar sesión/cookies y reintentar.
- `invalid issuer/audience`: revisar `SSO_ISSUER`, `SSO_CLIENT_ID`.
- error de JWKS/discovery: verificar `http://localhost:8000/.well-known/openid-configuration`.
- `missing email`: revisar scopes `openid email profile`.
- vuelve a `/admin/login` después de autenticar: usar `SESSION_COOKIE` distinto al de `auth` (evita colisión en `localhost`).
