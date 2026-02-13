<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateDocument extends CreateRecord
{
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

                // Build the Drive destination path
                $year = $data['year'] ?? now()->year;
                $drivePath = "SGI-Doc/{$year}/{$originalName}";

                try {
                    // Read file from local temp storage
                    $fileContents = Storage::disk('local')->readStream($localPath);

                    // Upload to Google Drive
                    Storage::disk('google')->writeStream($drivePath, $fileContents);

                    // Retrieve Drive file ID using the Google API directly
                    $driveId = $this->findDriveFileId($originalName, $year);

                    if ($driveId) {
                        $data['gdrive_id'] = $driveId;
                        $data['gdrive_url'] = "https://drive.google.com/file/d/{$driveId}/view";
                    }

                    // Clean up local temp file
                    Storage::disk('local')->delete($localPath);

                    Log::info('Document uploaded to Google Drive', [
                        'file_name' => $originalName,
                        'gdrive_id' => $data['gdrive_id'] ?? 'N/A',
                        'drive_path' => $drivePath,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to upload document to Google Drive', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $localPath,
                    ]);

                    $data['file_name'] = $data['file_name'] ?? $originalName;
                }
            } else {
                $data['file_name'] = $data['file_name'] ?? 'sin-archivo';
            }
        } else {
            $data['file_name'] = $data['file_name'] ?? 'sin-archivo';
        }

        return $data;
    }

    /**
     * Find a file's Google Drive ID by searching with the Google API.
     */
    protected function findDriveFileId(string $fileName, int|string $year): ?string
    {
        try {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('google');
            $adapter = $disk->getAdapter();

            // Use the Google Service to search for the file
            /** @var \Google\Service\Drive $service */
            $service = $adapter->getService();

            // Search for the file by name (most recently created)
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
