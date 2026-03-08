# agroista/core

Shared core package for Agroista ecosystem applications.

## Features

- OIDC client for SSO against central `auth`
- Local user provisioning strategy (`sub` with email fallback)
- Institution configuration client and context cache
- Shared package config (`agroista-core.php`)

## Install

```bash
composer require agroista/core
```

For local development as path repository:

```json
{
  "repositories": [
    { "type": "path", "url": "packages/agroista/core" }
  ]
}
```

Then publish config:

```bash
php artisan vendor:publish --tag=agroista-core-config
```
