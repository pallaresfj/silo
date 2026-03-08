<?php

namespace Agroista\Core;

use Agroista\Core\Institution\InstitutionContext;
use Agroista\Core\Institution\InstitutionConfigClient;
use Agroista\Core\Sso\OidcClient;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agroista-core.php', 'agroista-core');

        $this->app->singleton(OidcClient::class, fn (): OidcClient => new OidcClient());
        $this->app->singleton(InstitutionConfigClient::class, fn (): InstitutionConfigClient => new InstitutionConfigClient());
        $this->app->singleton(InstitutionContext::class, fn ($app): InstitutionContext => new InstitutionContext(
            $app->make(InstitutionConfigClient::class)
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agroista-core.php' => config_path('agroista-core.php'),
        ], 'agroista-core-config');
    }
}
