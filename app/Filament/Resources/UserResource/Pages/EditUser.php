<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['role'] = $this->resolveLegacyRoleFromSelection($data['roles'] ?? []);

        return $data;
    }

    protected function afterSave(): void
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
}
