<?php

namespace App\Filament\Resources\DocumentResource\Pages\Concerns;

use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Support\GoogleDriveHelper;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

trait UploadsToGoogleDrive
{
    /**
     * Upload a file to Google Drive using the API with Shared Drive support.
     * Creates folder structure {Year}/{Category} automatically.
     *
     * @return array{id: string, webViewLink: string}|null
     */
    protected function uploadToGoogleDrive(
        string $fileName,
        string $fileContents,
        string $mimeType,
        int|string $year,
        string $categorySlug,
        string $entityFolder
    ): ?array {
        $rootFolderId = config('filesystems.disks.google.folder');

        try {
            $service = GoogleDriveHelper::makeService();
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolder($year, $categorySlug, $entityFolder);

            // Create file metadata
            $fileMetadata = new DriveFile([
                'name' => $fileName,
                'parents' => [$targetFolderId],
            ]);

            // Upload file with Shared Drive support
            $file = $service->files->create($fileMetadata, [
                'data' => $fileContents,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink',
                'supportsAllDrives' => true,
            ]);

            Log::info('File uploaded to Google Drive', [
                'fileName' => $fileName,
                'driveId' => $file->getId(),
                'folder' => "{$year}/{$categorySlug}/{$entityFolder}",
            ]);

            return [
                'id' => $file->getId(),
                'webViewLink' => $file->getWebViewLink() ?? "https://drive.google.com/file/d/{$file->getId()}/view",
            ];
        } catch (\Throwable $e) {
            $errorContext = $this->extractGoogleDriveErrorContext($e);

            Log::error('Google Drive upload failed', [
                'error' => $e->getMessage(),
                'fileName' => $fileName,
                'reason' => $errorContext['reason'] ?? null,
                'apiMessage' => $errorContext['api_message'] ?? null,
                'rootFolderId' => $rootFolderId,
            ]);

            if (($errorContext['reason'] ?? null) === 'storageQuotaExceeded') {
                throw new \RuntimeException(
                    'Google Drive rechazó la subida: la Service Account no puede escribir en carpetas fuera de Shared Drive. '
                    .'Configura GOOGLE_DRIVE_FOLDER_ID con una carpeta dentro de un Shared Drive.',
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Normalize Google API error data for logging and diagnostics.
     *
     * @return array{reason: string|null, api_message: string|null}
     */
    protected function extractGoogleDriveErrorContext(\Throwable $e): array
    {
        $reason = null;
        $apiMessage = null;

        if (method_exists($e, 'getErrors')) {
            $errors = $e->getErrors();
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                $reason = $errors[0]['reason'] ?? null;
                $apiMessage = $errors[0]['message'] ?? null;
            }
        }

        if (!$reason || !$apiMessage) {
            $decoded = json_decode($e->getMessage(), true);
            if (is_array($decoded)) {
                $reason ??= $decoded['error']['errors'][0]['reason'] ?? null;
                $apiMessage ??= $decoded['error']['errors'][0]['message'] ?? ($decoded['error']['message'] ?? null);
            }
        }

        return [
            'reason' => $reason,
            'api_message' => $apiMessage,
        ];
    }

    /**
     * Get category slug from category_id.
     */
    protected function getCategorySlug(int|string|null $categoryId): string
    {
        if (!$categoryId) {
            return 'sin-clasificar';
        }

        $category = DocumentCategory::find($categoryId);

        return GoogleDriveHelper::normalizeCategorySlug($category?->slug);
    }

    /**
     * Get entity folder name from entity_id.
     */
    protected function getEntityFolder(int|string|null $entityId): string
    {
        if (! $entityId) {
            return GoogleDriveHelper::normalizeEntityFolderName(null);
        }

        $entity = Entity::find($entityId);

        return GoogleDriveHelper::normalizeEntityFolderName($entity?->name);
    }
}
