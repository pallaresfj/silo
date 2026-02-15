<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
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
