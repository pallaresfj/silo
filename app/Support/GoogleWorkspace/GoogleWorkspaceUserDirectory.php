<?php

namespace App\Support\GoogleWorkspace;

use App\Support\GoogleWorkspace\Contracts\WorkspaceUserDirectory;
use Google\Client;
use Google\Service\Directory;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleWorkspaceUserDirectory implements WorkspaceUserDirectory
{
    public function userExists(string $email): bool
    {
        $email = Str::lower(trim($email));

        if ($email === '') {
            return false;
        }

        $service = $this->makeDirectoryService();

        try {
            $user = $service->users->get($email, [
                'projection' => 'basic',
            ]);
        } catch (GoogleServiceException $exception) {
            if ($this->isNotFoundError($exception)) {
                return $this->findUserWithQueryFallback($service, $email);
            }

            throw $this->toWorkspaceRuntimeException($exception);
        } catch (\Throwable $exception) {
            throw new RuntimeException('No fue posible validar el usuario en Google Workspace. Revisa la integración.', previous: $exception);
        }

        return filled((string) $user->getPrimaryEmail());
    }

    protected function makeDirectoryService(): Directory
    {
        $adminEmail = Str::lower(trim((string) config('services.google.workspace_admin_email')));

        if ($adminEmail === '') {
            throw new RuntimeException('Falta configurar GOOGLE_WORKSPACE_ADMIN_EMAIL.');
        }

        $privateKey = config('services.google.workspace_private_key');

        if (! $privateKey) {
            throw new RuntimeException('Falta configurar GOOGLE_DRIVE_PRIVATE_KEY para validar usuarios de Workspace.');
        }

        $client = new Client();
        $client->setScopes([Directory::ADMIN_DIRECTORY_USER_READONLY]);
        $client->setAuthConfig([
            'type' => config('services.google.workspace_type', 'service_account'),
            'project_id' => config('services.google.workspace_project_id', ''),
            'private_key_id' => config('services.google.workspace_private_key_id', ''),
            'private_key' => str_replace('\\n', "\n", $privateKey),
            'client_email' => config('services.google.workspace_client_email', ''),
            'client_id' => config('services.google.workspace_client_id', ''),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
        $client->setSubject($adminEmail);

        return new Directory($client);
    }

    protected function findUserWithQueryFallback(Directory $service, string $email): bool
    {
        $customer = (string) config('services.google.workspace_customer', 'my_customer');
        $escapedEmail = str_replace("'", "\\'", $email);

        try {
            $users = $service->users->listUsers([
                'customer' => $customer !== '' ? $customer : 'my_customer',
                'query' => "email='{$escapedEmail}'",
                'maxResults' => 1,
                'projection' => 'basic',
            ]);
        } catch (GoogleServiceException $exception) {
            if ($this->isNotFoundError($exception)) {
                return false;
            }

            throw $this->toWorkspaceRuntimeException($exception);
        } catch (\Throwable $exception) {
            throw new RuntimeException('No fue posible validar el usuario en Google Workspace. Revisa la integración.', previous: $exception);
        }

        return count($users->getUsers() ?? []) > 0;
    }

    protected function isNotFoundError(GoogleServiceException $exception): bool
    {
        $reason = $exception->getErrors()[0]['reason'] ?? null;

        return $exception->getCode() === 404 || in_array($reason, ['notFound', 'resourceNotFound', 'invalid'], true);
    }

    protected function toWorkspaceRuntimeException(GoogleServiceException $exception): RuntimeException
    {
        $reason = (string) ($exception->getErrors()[0]['reason'] ?? '');
        $rawMessage = Str::lower($exception->getMessage());
        $base = 'No fue posible validar el usuario en Google Workspace.';

        $message = "{$base} Revisa la integración.";

        if ($reason === 'unauthorized_client' || str_contains($rawMessage, 'unauthorized_client')) {
            $message = "{$base} La cuenta de servicio no está autorizada para Admin SDK (falta delegación de dominio o scopes autorizados).";
        } elseif (in_array($reason, ['forbidden', 'insufficientPermissions', 'notAuthorizedToAccessThisResource'], true)) {
            $message = "{$base} La cuenta de servicio no tiene permisos para Directory API.";
        } elseif ($reason === 'accessNotConfigured' || str_contains($rawMessage, 'access not configured')) {
            $message = "{$base} Directory API no está habilitada en el proyecto de Google Cloud.";
        }

        return new RuntimeException($message, previous: $exception);
    }
}
