<?php

namespace App\Filament\Resources\EntityResource\Pages;

use App\Filament\Resources\EntityResource;
use App\Models\Entity;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditEntity extends EditRecord
{
    protected static string $resource = EntityResource::class;

    protected ?int $syncedFilesCount = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Entity $record): void {
                    EntityResource::guardDeletion($record);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $oldName = (string) $this->record->name;
        $newName = (string) ($data['name'] ?? $oldName);

        if ($newName !== $oldName) {
            try {
                $this->syncedFilesCount = EntityResource::syncRenamedEntityInDrive($this->record, $newName);
            } catch (\Throwable $e) {
                Notification::make()
                    ->danger()
                    ->title('No se pudo actualizar la entidad')
                    ->body('No pudimos sincronizar el cambio de nombre en Google Drive. Intenta nuevamente.')
                    ->persistent()
                    ->send();

                throw new Halt;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->syncedFilesCount === null) {
            return;
        }

        Notification::make()
            ->success()
            ->title('Entidad actualizada')
            ->body("Google Drive sincronizado en {$this->syncedFilesCount} carpeta(s).")
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
