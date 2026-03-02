<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\Concerns\UploadsToGoogleDrive;
use App\Models\Document;
use App\Support\GoogleDriveHelper;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EditDocument extends EditRecord
{
    use UploadsToGoogleDrive;

    protected static string $resource = DocumentResource::class;
    protected ?string $previousDriveIdToDelete = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Archivar'),
            Actions\ForceDeleteAction::make()
                ->label('Eliminar definitivamente')
                ->modalHeading('Eliminar documento definitivamente')
                ->modalDescription('Esta acción eliminará el registro y el archivo en Google Drive. No se puede deshacer.')
                ->modalSubmitActionLabel('Eliminar definitivamente')
                ->action(function (Actions\ForceDeleteAction $action, Document $record): void {
                    try {
                        DocumentResource::deleteFromGoogleDrive($record);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('No se pudo eliminar definitivamente')
                            ->body('No pudimos eliminar el archivo en Google Drive. Intenta nuevamente.')
                            ->persistent()
                            ->send();

                        throw new Halt;
                    }

                    $result = $action->process(static fn (Document $record): ?bool => $record->forceDelete());

                    if (! $result) {
                        $action->failure();

                        return;
                    }

                    $action->success();
                }),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->previousDriveIdToDelete = null;
        $currentDriveId = (string) ($this->record->gdrive_id ?? '');
        $creationMode = $data['creation_mode'] ?? 'upload';
        $driveNativeType = $data['drive_native_type'] ?? 'document';
        $attachment = $data['attachment'] ?? null;

        // Remove non-persistent fields from data.
        unset($data['creation_mode'], $data['drive_native_type']);
        unset($data['attachment']);

        $storageScope = $this->getStorageScope($data['storage_scope'] ?? $this->record->storage_scope ?? null);
        $year = $data['year'] ?? $this->record->year ?? now()->year;
        $categorySlug = $this->getCategorySlug($data['category_id'] ?? $this->record->category_id ?? null);
        $entityFolder = $this->getEntityFolder($data['entity_id'] ?? $this->record->entity_id ?? null);
        $destination = $this->buildDriveDestination($storageScope, $year, $categorySlug, $entityFolder);
        $data['storage_scope'] = $storageScope;

        if ($creationMode === 'drive_native') {
            try {
                $result = $this->createNativeDocumentInGoogleDrive(
                    $data['title'] ?? $this->record->title ?? 'Documento sin titulo',
                    $driveNativeType,
                    $destination,
                );

                if ($result) {
                    $data['gdrive_id'] = $result['id'];
                    $data['gdrive_url'] = $result['webViewLink'];
                    $data['file_name'] = $result['fileName'];

                    if (! blank($currentDriveId) && $currentDriveId !== (string) $data['gdrive_id']) {
                        $this->previousDriveIdToDelete = $currentDriveId;
                    }
                }

                Log::info('Native document created in Google Drive from edit', [
                    'document_id' => $this->record->id,
                    'title' => $data['title'] ?? $this->record->title ?? 'N/A',
                    'native_type' => $driveNativeType,
                    'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                    'folder' => 'SGI-Doc/' . $this->buildFolderLogPath($destination),
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to create native document in Google Drive from edit', [
                    'document_id' => $this->record->id,
                    'error' => $e->getMessage(),
                    'native_type' => $driveNativeType,
                ]);

                $this->form->fill($this->record->attributesToArray());

                Notification::make()
                    ->danger()
                    ->title('No se pudo actualizar el documento')
                    ->body('No pudimos crear el documento en Google Drive. Intenta nuevamente.')
                    ->persistent()
                    ->send();

                throw new Halt;
            } finally {
                if ($attachment) {
                    $localPath = $this->resolveAttachmentPath($attachment);

                    if ($localPath && Storage::disk('local')->exists($localPath)) {
                        Storage::disk('local')->delete($localPath);
                    }
                }
            }

            return $data;
        }

        if ($attachment) {
            $localPath = $this->resolveAttachmentPath($attachment);

            if ($localPath && Storage::disk('local')->exists($localPath)) {
                $originalName = basename($localPath);
                $data['file_name'] = $originalName;

                try {
                    // Get file contents from local storage
                    $fileContents = Storage::disk('local')->get($localPath);
                    $mimeType = Storage::disk('local')->mimeType($localPath) ?? 'application/octet-stream';

                    // Upload directly using Google API and get the file ID
                    $result = $this->uploadToGoogleDrive(
                        $originalName,
                        $fileContents,
                        $mimeType,
                        $destination,
                    );

                    if ($result) {
                        $data['gdrive_id'] = $result['id'];
                        $data['gdrive_url'] = $result['webViewLink'] ?? "https://drive.google.com/file/d/{$result['id']}/view";

                        if (! blank($currentDriveId) && $currentDriveId !== (string) $data['gdrive_id']) {
                            $this->previousDriveIdToDelete = $currentDriveId;
                        }
                    }

                    Log::info('Document updated on Google Drive', [
                        'file_name' => $originalName,
                        'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                        'folder' => 'SGI-Doc/' . $this->buildFolderLogPath($destination),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to upload document to Google Drive', [
                        'error' => $e->getMessage(),
                        'file' => $localPath,
                    ]);

                    $this->form->fill($this->record->attributesToArray());

                    Notification::make()
                        ->danger()
                        ->title('No se pudo actualizar el documento')
                        ->body('No pudimos completar la carga del archivo. Intenta nuevamente.')
                        ->persistent()
                        ->send();

                    throw new Halt;
                } finally {
                    if (Storage::disk('local')->exists($localPath)) {
                        Storage::disk('local')->delete($localPath);
                    }
                }
            } else {
                Log::warning('Attachment path not found', ['path' => $localPath]);

                $this->form->fill($this->record->attributesToArray());

                Notification::make()
                    ->danger()
                    ->title('No se pudo actualizar el documento')
                    ->body('No pudimos procesar el archivo seleccionado. Intenta adjuntarlo nuevamente.')
                    ->persistent()
                    ->send();

                throw new Halt;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncCurrentDriveFile();

        $oldDriveId = $this->previousDriveIdToDelete;
        $this->previousDriveIdToDelete = null;

        if (blank($oldDriveId)) {
            return;
        }

        try {
            GoogleDriveHelper::deleteOrTrashFile((string) $oldDriveId);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete previous Google Drive file after replacement', [
                'document_id' => $this->record->id,
                'old_gdrive_id' => $oldDriveId,
                'new_gdrive_id' => $this->record->gdrive_id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->warning()
                ->title('Documento actualizado, pero el archivo anterior no se pudo eliminar')
                ->body('El nuevo archivo quedó guardado. Revisa permisos de Google Drive para limpiar el archivo anterior.')
                ->persistent()
                ->send();
        }
    }

    protected function syncCurrentDriveFile(): void
    {
        $currentDriveId = (string) ($this->record->gdrive_id ?? '');

        if ($currentDriveId === '') {
            return;
        }

        try {
            $categorySlug = $this->getCategorySlug($this->record->category_id);
            $entityFolder = $this->getEntityFolder($this->record->entity_id);
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolderForDestination(
                $this->buildDriveDestination(
                    $this->record->storage_scope,
                    $this->record->year ?? now()->year,
                    $categorySlug,
                    $entityFolder
                )
            );

            $driveFileName = $this->resolveDesiredDriveFileName();
            $dbFileName = $this->resolveDesiredDatabaseFileName($driveFileName);

            $renameResult = GoogleDriveHelper::renameFile($currentDriveId, $driveFileName);
            $moveResult = GoogleDriveHelper::moveFileToFolder($currentDriveId, $targetFolderId);

            if ($dbFileName !== (string) $this->record->file_name) {
                $this->record->forceFill(['file_name' => $dbFileName])->saveQuietly();
            }

            Log::info('Google Drive file synchronized after document edit', [
                'document_id' => $this->record->id,
                'gdrive_id' => $currentDriveId,
                'rename_result' => $renameResult,
                'move_result' => $moveResult,
                'target_folder_id' => $targetFolderId,
                'target_file_name' => $driveFileName,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to synchronize Google Drive file after edit', [
                'document_id' => $this->record->id,
                'gdrive_id' => $currentDriveId,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->warning()
                ->title('Documento actualizado parcialmente')
                ->body('Se guardaron los metadatos, pero no fue posible actualizar nombre o ubicación en Google Drive.')
                ->persistent()
                ->send();
        }
    }

    protected function resolveDesiredDriveFileName(): string
    {
        $currentFileName = (string) ($this->record->file_name ?? '');
        $extension = Str::lower((string) pathinfo($currentFileName, PATHINFO_EXTENSION));

        $title = trim((string) ($this->record->title ?? ''));
        if ($title === '') {
            $title = trim((string) pathinfo($currentFileName, PATHINFO_FILENAME));
        }
        if ($title === '') {
            $title = 'documento';
        }

        $title = str_replace(['/', '\\'], '-', $title);

        if ($extension !== '' && Str::endsWith(Str::lower($title), ".{$extension}")) {
            $title = substr($title, 0, -1 * (strlen($extension) + 1));
        }

        if ($extension === '' || in_array($extension, ['gdoc', 'gsheet', 'gslides'], true)) {
            return $title;
        }

        return "{$title}.{$extension}";
    }

    protected function resolveDesiredDatabaseFileName(string $driveFileName): string
    {
        $currentFileName = (string) ($this->record->file_name ?? '');
        $extension = Str::lower((string) pathinfo($currentFileName, PATHINFO_EXTENSION));

        if ($extension === '') {
            return $driveFileName;
        }

        if (in_array($extension, ['gdoc', 'gsheet', 'gslides'], true)) {
            if (Str::endsWith(Str::lower($driveFileName), ".{$extension}")) {
                return $driveFileName;
            }

            return "{$driveFileName}.{$extension}";
        }

        return $driveFileName;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
