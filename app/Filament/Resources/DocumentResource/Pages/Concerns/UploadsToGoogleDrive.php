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
     * Normalize Filament/Livewire attachment payloads to a local storage path.
     */
    protected function resolveAttachmentPath(mixed $attachment): ?string
    {
        if (is_string($attachment)) {
            return $attachment;
        }

        if (is_array($attachment)) {
            foreach ($attachment as $value) {
                $resolvedPath = $this->resolveAttachmentPath($value);

                if (filled($resolvedPath)) {
                    return $resolvedPath;
                }
            }
        }

        return null;
    }

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
        ?string $entityFolder
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
                'folder' => $this->buildFolderLogPath($year, $categorySlug, $entityFolder),
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
     * Create a native Google document in Drive (Docs/Sheets/Slides).
     *
     * @return array{id: string, webViewLink: string, fileName: string}|null
     */
    protected function createNativeDocumentInGoogleDrive(
        string $title,
        string $nativeType,
        int|string $year,
        string $categorySlug,
        ?string $entityFolder
    ): ?array {
        $rootFolderId = config('filesystems.disks.google.folder');

        try {
            $service = GoogleDriveHelper::makeService();
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolder($year, $categorySlug, $entityFolder);

            [
                'mimeType' => $mimeType,
                'extension' => $extension,
                'fallbackUrl' => $fallbackUrl,
            ] = $this->resolveNativeDriveDocumentType($nativeType);

            $normalizedTitle = trim($title) !== '' ? trim($title) : 'Documento sin titulo';

            $fileMetadata = new DriveFile([
                'name' => $normalizedTitle,
                'mimeType' => $mimeType,
                'parents' => [$targetFolderId],
            ]);

            $file = $service->files->create($fileMetadata, [
                'fields' => 'id,name,webViewLink',
                'supportsAllDrives' => true,
            ]);

            Log::info('Native Drive document created', [
                'title' => $normalizedTitle,
                'nativeType' => $nativeType,
                'driveId' => $file->getId(),
                'folder' => $this->buildFolderLogPath($year, $categorySlug, $entityFolder),
            ]);

            return [
                'id' => $file->getId(),
                'webViewLink' => $file->getWebViewLink() ?? sprintf($fallbackUrl, $file->getId()),
                'fileName' => "{$normalizedTitle}.{$extension}",
            ];
        } catch (\Throwable $e) {
            $errorContext = $this->extractGoogleDriveErrorContext($e);

            Log::error('Google Drive native document creation failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'nativeType' => $nativeType,
                'reason' => $errorContext['reason'] ?? null,
                'apiMessage' => $errorContext['api_message'] ?? null,
                'rootFolderId' => $rootFolderId,
            ]);

            if (($errorContext['reason'] ?? null) === 'storageQuotaExceeded') {
                throw new \RuntimeException(
                    'Google Drive rechazo la creacion: la Service Account no puede escribir en carpetas fuera de Shared Drive. '
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
    protected function getEntityFolder(int|string|null $entityId): ?string
    {
        if (! $entityId) {
            return null;
        }

        $entity = Entity::find($entityId);

        if (blank($entity?->name)) {
            return null;
        }

        return GoogleDriveHelper::normalizeEntityFolderName($entity->name);
    }

    protected function buildFolderLogPath(int|string $year, string $categorySlug, ?string $entityFolder): string
    {
        return blank($entityFolder)
            ? "{$year}/{$categorySlug}"
            : "{$year}/{$categorySlug}/{$entityFolder}";
    }

    /**
     * @return array{mimeType: string, extension: string, fallbackUrl: string}
     */
    protected function resolveNativeDriveDocumentType(string $nativeType): array
    {
        return match ($nativeType) {
            'spreadsheet' => [
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'extension' => 'gsheet',
                'fallbackUrl' => 'https://docs.google.com/spreadsheets/d/%s/edit',
            ],
            'presentation' => [
                'mimeType' => 'application/vnd.google-apps.presentation',
                'extension' => 'gslides',
                'fallbackUrl' => 'https://docs.google.com/presentation/d/%s/edit',
            ],
            default => [
                'mimeType' => 'application/vnd.google-apps.document',
                'extension' => 'gdoc',
                'fallbackUrl' => 'https://docs.google.com/document/d/%s/edit',
            ],
        };
    }
}
