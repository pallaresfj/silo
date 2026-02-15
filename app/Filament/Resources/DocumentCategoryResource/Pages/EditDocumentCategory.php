<?php

namespace App\Filament\Resources\DocumentCategoryResource\Pages;

use App\Filament\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;

class EditDocumentCategory extends EditRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected ?int $driveRenamedFolders = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (DocumentCategory $record): void {
                    DocumentCategoryResource::guardDeletion($record);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $oldName = (string) $this->record->name;
        $oldSlug = (string) $this->record->slug;
        $newName = (string) ($data['name'] ?? $oldName);
        $newSlug = (string) ($data['slug'] ?? $oldSlug);

        if (blank($newSlug)) {
            $newSlug = Str::slug($newName);
            $data['slug'] = $newSlug;
        }

        if (($newName !== $oldName) && ($newSlug === $oldSlug)) {
            $newSlug = Str::slug($newName);
            $data['slug'] = $newSlug;
        }

        if ($newSlug !== $oldSlug) {
            try {
                $this->driveRenamedFolders = DocumentCategoryResource::syncRenamedCategoryInDrive($oldSlug, $newSlug);
            } catch (\Throwable $e) {
                Notification::make()
                    ->danger()
                    ->title('No se pudo actualizar la categoría')
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
        if ($this->driveRenamedFolders === null) {
            return;
        }

        Notification::make()
            ->success()
            ->title('Categoría actualizada')
            ->body("Google Drive sincronizado en {$this->driveRenamedFolders} carpeta(s).")
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
