<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
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

                // Build the Drive destination path
                $year = $data['year'] ?? $this->record->year ?? now()->year;
                $drivePath = "SGI-Doc/{$year}/{$originalName}";

                try {
                    // Read file from local temp storage
                    $fileContents = Storage::disk('local')->readStream($localPath);

                    // Upload to Google Drive
                    Storage::disk('google')->writeStream($drivePath, $fileContents);

                    // Retrieve Drive file ID using the Google API directly
                    $driveId = $this->findDriveFileId($originalName);

                    if ($driveId) {
                        $data['gdrive_id'] = $driveId;
                        $data['gdrive_url'] = "https://drive.google.com/file/d/{$driveId}/view";
                    }

                    // Clean up local temp file
                    Storage::disk('local')->delete($localPath);

                    Log::info('Document updated on Google Drive', [
                        'file_name' => $originalName,
                        'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                        'drive_path' => $drivePath,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to upload document to Google Drive', [
                        'error' => $e->getMessage(),
                        'file' => $localPath,
                    ]);
                }
            }
        }

        return $data;
    }

    /**
     * Find a file's Google Drive ID by searching with the Google API.
     */
    protected function findDriveFileId(string $fileName): ?string
    {
        try {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('google');
            $adapter = $disk->getAdapter();

            /** @var \Google\Service\Drive $service */
            $service = $adapter->getService();

            $results = $service->files->listFiles([
                'q' => "name = '{$fileName}' and trashed = false",
                'fields' => 'files(id, name, createdTime)',
                'orderBy' => 'createdTime desc',
                'pageSize' => 1,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ]);

            $files = $results->getFiles();
            if (!empty($files)) {
                return $files[0]->getId();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not retrieve Drive file ID', [
                'error' => $e->getMessage(),
                'fileName' => $fileName,
            ]);
        }

        return null;
    }
}
