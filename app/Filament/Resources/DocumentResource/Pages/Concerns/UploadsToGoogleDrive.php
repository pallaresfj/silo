<?php

namespace App\Filament\Resources\DocumentResource\Pages\Concerns;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Support\Drive\DocumentDriveDestination;
use App\Support\GoogleDriveHelper;
use Google\Service\Exception as GoogleServiceException;
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
     * Creates the configured destination structure automatically.
     *
     * @return array{id: string, webViewLink: string}|null
     */
    protected function uploadToGoogleDrive(
        string $fileName,
        string $fileContents,
        string $mimeType,
        DocumentDriveDestination $destination,
    ): ?array {
        $rootFolderId = config('filesystems.disks.google.folder');

        try {
            $service = GoogleDriveHelper::makeService();
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolderForDestination($destination);

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
                'folder' => $this->buildFolderLogPath($destination),
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
        DocumentDriveDestination $destination,
    ): ?array {
        $rootFolderId = config('filesystems.disks.google.folder');

        try {
            $service = GoogleDriveHelper::makeService();
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolderForDestination($destination);

            [
                'mimeType' => $mimeType,
                'extension' => $extension,
                'fallbackUrl' => $fallbackUrl,
            ] = $this->resolveNativeDriveDocumentType($nativeType);

            $normalizedTitle = trim($title) !== '' ? trim($title) : 'Documento sin titulo';
            $templateId = $this->resolveNativeDriveTemplateId($nativeType);

            if (filled($templateId)) {
                $file = $service->files->copy(
                    $templateId,
                    new DriveFile([
                        'name' => $normalizedTitle,
                        'parents' => [$targetFolderId],
                    ]),
                    [
                        'fields' => 'id,name,webViewLink',
                        'supportsAllDrives' => true,
                    ]
                );

                Log::info('Native Drive document created from template', [
                    'title' => $normalizedTitle,
                    'nativeType' => $nativeType,
                    'templateId' => $templateId,
                    'driveId' => $file->getId(),
                    'folder' => $this->buildFolderLogPath($destination),
                ]);
            } else {
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
                    'folder' => $this->buildFolderLogPath($destination),
                ]);
            }

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

        if ($e instanceof GoogleServiceException) {
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

    protected function getStorageScope(?string $storageScope): string
    {
        return match ($storageScope) {
            Document::STORAGE_SCOPE_INSTITUTIONAL => Document::STORAGE_SCOPE_INSTITUTIONAL,
            default => Document::STORAGE_SCOPE_YEARLY,
        };
    }

    protected function buildDriveDestination(
        ?string $storageScope,
        int|string|null $year,
        string $categorySlug,
        ?string $entityFolder,
    ): DocumentDriveDestination {
        return new DocumentDriveDestination(
            storageScope: $this->getStorageScope($storageScope),
            year: max(1900, (int) ($year ?? now()->year)),
            categorySlug: $categorySlug,
            entityFolder: $entityFolder,
        );
    }

    protected function buildFolderLogPath(DocumentDriveDestination $destination): string
    {
        $basePath = $destination->storageScope === Document::STORAGE_SCOPE_INSTITUTIONAL
            ? GoogleDriveHelper::getInstitutionalFolderName()
            : (string) $destination->year;

        return blank($destination->entityFolder)
            ? "{$basePath}/{$destination->categorySlug}"
            : "{$basePath}/{$destination->categorySlug}/{$destination->entityFolder}";
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

    protected function resolveNativeDriveTemplateId(string $nativeType): ?string
    {
        $templateId = match ($nativeType) {
            'spreadsheet' => config('filesystems.disks.google.templates.spreadsheet'),
            'presentation' => config('filesystems.disks.google.templates.presentation'),
            default => config('filesystems.disks.google.templates.document'),
        };

        return filled($templateId) ? (string) $templateId : null;
    }
}
