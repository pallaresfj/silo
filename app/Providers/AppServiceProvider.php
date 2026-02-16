<?php

namespace App\Providers;

use App\Filament\Auth\Responses\LogoutResponse as AppLogoutResponse;
use App\Support\GoogleWorkspace\Contracts\WorkspaceUserDirectory;
use App\Support\GoogleWorkspace\GoogleWorkspaceUserDirectory;
use App\Support\Drive\Contracts\DriveSyncGateway;
use App\Support\Drive\GoogleDriveSyncGateway;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DriveSyncGateway::class, GoogleDriveSyncGateway::class);
        $this->app->bind(WorkspaceUserDirectory::class, GoogleWorkspaceUserDirectory::class);
        $this->app->bind(LogoutResponseContract::class, AppLogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGoogleDriveDriver();
    }

    /**
     * Register the 'google' filesystem driver for Google Drive.
     *
     * Requires: masbug/flysystem-google-drive-ext
     * Install:  composer require masbug/flysystem-google-drive-ext
     *
     * Uses Google Service Account credentials from env vars
     * (GOOGLE_DRIVE_TYPE, _PROJECT_ID, _PRIVATE_KEY, _CLIENT_EMAIL, etc.)
     */
    protected function registerGoogleDriveDriver(): void
    {
        Storage::extend('google', function ($app, $config) {
            // Check if the masbug adapter is installed
            if (!class_exists(\Masbug\Flysystem\GoogleDriveAdapter::class)) {
                \Illuminate\Support\Facades\Log::warning(
                    'Google Drive adapter not installed. Run: composer require masbug/flysystem-google-drive-ext'
                );

                return Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
            }

            $client = new \Google\Client();
            $client->setScopes([\Google\Service\Drive::DRIVE]);

            $privateKey = $config['private_key'] ?? null;

            if ($privateKey) {
                $client->setAuthConfig([
                    'type' => $config['type'] ?? 'service_account',
                    'project_id' => $config['project_id'] ?? '',
                    'private_key_id' => $config['private_key_id'] ?? '',
                    'private_key' => str_replace('\\n', "\n", $privateKey),
                    'client_email' => $config['client_email'] ?? '',
                    'client_id' => $config['client_id'] ?? '',
                    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning(
                    'Google Drive Service Account not configured. Set GOOGLE_DRIVE_PRIVATE_KEY in .env.'
                );

                return Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
            }

            $service = new \Google\Service\Drive($client);
            $adapter = new \Masbug\Flysystem\GoogleDriveAdapter($service, $config['folder'] ?? '');

            $flysystem = new Filesystem($adapter);

            return new FilesystemAdapter($flysystem, $adapter, $config);
        });
    }
}
