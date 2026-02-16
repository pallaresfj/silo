<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use App\Support\GoogleWorkspace\Contracts\WorkspaceUserDirectory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
    protected bool $workspaceValidationEnabled = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workspaceValidationEnabled = (bool) config('services.google.validate_users_on_create', true);

        if ($this->workspaceValidationEnabled) {
            $this->assertUserExistsInWorkspace((string) ($data['email'] ?? ''));
        }

        $data['password'] = null;
        $data['role'] = $this->resolveLegacyRoleFromSelection($data['roles'] ?? []);

        return $data;
    }

    protected function afterCreate(): void
    {
        $legacyRole = $this->record->roles()->value('slug');

        if (filled($legacyRole)) {
            $this->record->role = $legacyRole;
            $this->record->saveQuietly();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        $notification = Notification::make()
            ->success()
            ->title('Usuario creado correctamente');

        if ($this->workspaceValidationEnabled) {
            $notification->body('El correo fue validado en Google Workspace.');
        } else {
            $notification->body('La validación de Google Workspace está desactivada.');
        }

        return $notification;
    }

    /**
     * @param  array<int, int|string>  $selectedRoleIds
     */
    protected function resolveLegacyRoleFromSelection(array $selectedRoleIds): string
    {
        if (empty($selectedRoleIds)) {
            return 'docente';
        }

        $slug = Role::query()
            ->whereIn('id', $selectedRoleIds)
            ->orderByRaw('FIELD(slug, "rector", "administrador", "editor", "lector")')
            ->value('slug');

        return filled($slug) ? (string) $slug : 'docente';
    }

    protected function assertUserExistsInWorkspace(string $email): void
    {
        try {
            $exists = app(WorkspaceUserDirectory::class)->userExists($email);
        } catch (\RuntimeException $exception) {
            $this->failWithEmailValidation($exception->getMessage());
        }

        if (! $exists) {
            $this->failWithEmailValidation('El correo no existe en Google Workspace.');
        }
    }

    protected function failWithEmailValidation(string $message): void
    {
        Notification::make()
            ->danger()
            ->title('No se pudo crear el usuario')
            ->body($message)
            ->persistent()
            ->send();

        throw ValidationException::withMessages([
            'email' => $message,
        ]);
    }
}
