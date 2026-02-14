<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\Concerns\UploadsToGoogleDrive;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateDocument extends CreateRecord
{
    use UploadsToGoogleDrive;

    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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
                $year = $data['year'] ?? now()->year;
                $categorySlug = $this->getCategorySlug($data['category_id'] ?? null);
                $entityFolder = $this->getEntityFolder($data['entity_id'] ?? null);

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

                    Log::info('Document uploaded to Google Drive', [
                        'file_name' => $originalName,
                        'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                        'folder' => "SGI-Doc/{$year}/{$categorySlug}/{$entityFolder}",
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to upload document to Google Drive', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $localPath,
                    ]);

                    $this->form->fill([]);

                    Notification::make()
                        ->danger()
                        ->title('No se pudo crear el documento')
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

                $this->form->fill([]);

                Notification::make()
                    ->danger()
                    ->title('No se pudo crear el documento')
                    ->body('No pudimos procesar el archivo seleccionado. Intenta adjuntarlo nuevamente.')
                    ->persistent()
                    ->send();

                throw new Halt;
            }
        } else {
            $data['file_name'] = $data['file_name'] ?? 'sin-archivo';
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
