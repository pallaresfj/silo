<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\Concerns\UploadsToGoogleDrive;
use App\Models\Document;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    use UploadsToGoogleDrive;

    protected static string $resource = DocumentResource::class;

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
        $attachment = $data['attachment'] ?? null;

        // Remove attachment from data - it's not a DB column
        unset($data['attachment']);

        if ($attachment) {
            $localPath = is_array($attachment) ? collect($attachment)->first() : $attachment;

            if ($localPath && Storage::disk('local')->exists($localPath)) {
                $originalName = basename($localPath);
                $data['file_name'] = $originalName;

                // Build the Drive folder path: SGI-Doc/{Year}/{CategorySlug}
                $year = $data['year'] ?? $this->record->year ?? now()->year;
                $categorySlug = $this->getCategorySlug($data['category_id'] ?? $this->record->category_id ?? null);
                $entityFolder = $this->getEntityFolder($data['entity_id'] ?? $this->record->entity_id ?? null);

                try {
                    // Get file contents from local storage
                    $fileContents = Storage::disk('local')->get($localPath);
                    $mimeType = Storage::disk('local')->mimeType($localPath) ?? 'application/octet-stream';

                    // Upload directly using Google API and get the file ID
                    $result = $this->uploadToGoogleDrive(
                        $originalName,
                        $fileContents,
                        $mimeType,
                        $year,
                        $categorySlug,
                        $entityFolder
                    );

                    if ($result) {
                        $data['gdrive_id'] = $result['id'];
                        $data['gdrive_url'] = $result['webViewLink'] ?? "https://drive.google.com/file/d/{$result['id']}/view";
                    }

                    Log::info('Document updated on Google Drive', [
                        'file_name' => $originalName,
                        'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                        'folder' => "SGI-Doc/{$year}/{$categorySlug}/{$entityFolder}",
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

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
