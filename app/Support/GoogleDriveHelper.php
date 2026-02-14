<?php

namespace App\Support;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Str;

class GoogleDriveHelper
{
    public static function makeService(): Drive
    {
        $config = config('filesystems.disks.google');
        $privateKey = $config['private_key'] ?? null;

        if (! $privateKey) {
            throw new \RuntimeException('Google Drive Service Account not configured.');
        }

        $client = new Client();
        $client->setScopes([Drive::DRIVE]);
        $client->setAuthConfig([
            'type' => $config['type'] ?? 'service_account',
            'project_id' => $config['project_id'] ?? '',
            'private_key_id' => $config['private_key_id'] ?? '',
            'private_key' => str_replace('\\n', "\n", $privateKey),
            'client_email' => $config['client_email'] ?? '',
            'client_id' => $config['client_id'] ?? '',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);

        return new Drive($client);
    }

    public static function getRootFolderId(): string
    {
        $folderId = (string) (config('filesystems.disks.google.folder') ?? '');

        if (blank($folderId)) {
            throw new \RuntimeException('GOOGLE_DRIVE_FOLDER_ID is not configured.');
        }

        return $folderId;
    }

    public static function renameCategoryFoldersAcrossYears(string $oldSlug, string $newSlug): int
    {
        $oldSlug = static::normalizeCategorySlug($oldSlug);
        $newSlug = static::normalizeCategorySlug($newSlug);

        if ($oldSlug === $newSlug) {
            return 0;
        }

        $service = static::makeService();
        $rootId = static::getRootFolderId();
        $yearFolders = static::listFolders($service, $rootId);
        $renamed = 0;

        foreach ($yearFolders as $yearFolder) {
            $categoryFolder = static::findFolderByName($service, $yearFolder['id'], $oldSlug);

            if (! $categoryFolder) {
                continue;
            }

            $service->files->update(
                $categoryFolder['id'],
                new DriveFile(['name' => $newSlug]),
                [
                    'fields' => 'id,name',
                    'supportsAllDrives' => true,
                ]
            );

            $renamed++;
        }

        return $renamed;
    }

    public static function renameEntityFoldersAcrossTree(string $oldEntityName, string $newEntityName): int
    {
        $oldFolder = static::normalizeEntityFolderName($oldEntityName);
        $newFolder = static::normalizeEntityFolderName($newEntityName);

        if ($oldFolder === $newFolder) {
            return 0;
        }

        $service = static::makeService();
        $rootId = static::getRootFolderId();
        $yearFolders = static::listFolders($service, $rootId);
        $renamed = 0;

        foreach ($yearFolders as $yearFolder) {
            $categoryFolders = static::listFolders($service, $yearFolder['id']);

            foreach ($categoryFolders as $categoryFolder) {
                $entityFolder = static::findFolderByName($service, $categoryFolder['id'], $oldFolder);

                if (! $entityFolder) {
                    continue;
                }

                $service->files->update(
                    $entityFolder['id'],
                    new DriveFile(['name' => $newFolder]),
                    [
                        'fields' => 'id,name',
                        'supportsAllDrives' => true,
                    ]
                );

                $renamed++;
            }
        }

        return $renamed;
    }

    /**
     * @param  list<string>  $fileIds
     */
    public static function syncEntityNameOnFiles(array $fileIds, string $entityName): int
    {
        if (empty($fileIds)) {
            return 0;
        }

        $service = static::makeService();
        $updated = 0;

        foreach ($fileIds as $fileId) {
            try {
                $file = $service->files->get($fileId, [
                    'fields' => 'id,description,capabilities(canEdit)',
                    'supportsAllDrives' => true,
                ]);
            } catch (\Google\Service\Exception $e) {
                $reason = $e->getErrors()[0]['reason'] ?? null;

                if ($reason === 'notFound') {
                    continue;
                }

                throw $e;
            }

            if (! $file->getCapabilities()?->getCanEdit()) {
                continue;
            }

            $description = static::buildEntityDescription($file->getDescription(), $entityName);

            $service->files->update(
                $fileId,
                new DriveFile(['description' => $description]),
                [
                    'fields' => 'id',
                    'supportsAllDrives' => true,
                ]
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * @return 'deleted'|'trashed'|'missing'
     */
    public static function deleteOrTrashFile(string $fileId): string
    {
        $service = static::makeService();

        try {
            $file = $service->files->get($fileId, [
                'fields' => 'id,trashed,capabilities(canDelete,canTrash)',
                'supportsAllDrives' => true,
            ]);
        } catch (\Google\Service\Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? null;

            if ($reason === 'notFound') {
                return 'missing';
            }

            throw $e;
        }

        if ($file->getTrashed()) {
            return 'trashed';
        }

        $canDelete = (bool) $file->getCapabilities()?->getCanDelete();
        $canTrash = (bool) $file->getCapabilities()?->getCanTrash();

        if ($canDelete) {
            $service->files->delete($fileId, ['supportsAllDrives' => true]);

            return 'deleted';
        }

        if ($canTrash) {
            $service->files->update(
                $fileId,
                new DriveFile(['trashed' => true]),
                [
                    'fields' => 'id,trashed',
                    'supportsAllDrives' => true,
                ]
            );

            return 'trashed';
        }

        throw new \RuntimeException('The service account cannot delete or trash this Drive file.');
    }

    public static function ensureDocumentFolder(int|string $year, ?string $categorySlug, ?string $entityName): string
    {
        $service = static::makeService();
        $rootId = static::getRootFolderId();

        $yearFolderId = static::findOrCreateFolder($service, (string) $year, $rootId);
        $categoryFolderId = static::findOrCreateFolder($service, static::normalizeCategorySlug($categorySlug), $yearFolderId);
        $entityFolderId = static::findOrCreateFolder($service, static::normalizeEntityFolderName($entityName), $categoryFolderId);

        return $entityFolderId;
    }

    /**
     * @return 'moved'|'already'|'missing'
     */
    public static function moveFileToFolder(string $fileId, string $targetFolderId): string
    {
        $service = static::makeService();

        try {
            $file = $service->files->get($fileId, [
                'fields' => 'id,parents,trashed',
                'supportsAllDrives' => true,
            ]);
        } catch (\Google\Service\Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? null;

            if ($reason === 'notFound') {
                return 'missing';
            }

            throw $e;
        }

        $parents = $file->getParents() ?: [];
        $hasTargetParent = in_array($targetFolderId, $parents, true);
        $parentsToRemove = array_values(array_filter($parents, static fn (string $parentId): bool => $parentId !== $targetFolderId));

        if ($hasTargetParent && empty($parentsToRemove)) {
            return 'already';
        }

        $service->files->update(
            $fileId,
            new DriveFile(),
            [
                'addParents' => $targetFolderId,
                'removeParents' => empty($parentsToRemove) ? null : implode(',', $parentsToRemove),
                'fields' => 'id,parents',
                'supportsAllDrives' => true,
            ]
        );

        return 'moved';
    }

    /**
     * @return 'already'|'needs_move'|'missing'
     */
    public static function getFilePlacementStatus(string $fileId, string $targetFolderId): string
    {
        $service = static::makeService();

        try {
            $file = $service->files->get($fileId, [
                'fields' => 'id,parents',
                'supportsAllDrives' => true,
            ]);
        } catch (\Google\Service\Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? null;

            return $reason === 'notFound' ? 'missing' : throw $e;
        }

        $parents = $file->getParents() ?: [];

        if (! in_array($targetFolderId, $parents, true)) {
            return 'needs_move';
        }

        if (count($parents) > 1) {
            return 'needs_move';
        }

        return 'already';
    }

    public static function normalizeEntityFolderName(?string $entityName): string
    {
        $value = Str::slug((string) $entityName);

        return $value !== '' ? $value : 'sin-entidad';
    }

    public static function normalizeCategorySlug(?string $categorySlug): string
    {
        $value = Str::slug((string) $categorySlug);

        return $value !== '' ? $value : 'sin-clasificar';
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected static function listFolders(Drive $service, string $parentId): array
    {
        $query = sprintf(
            "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            static::escapeQueryValue($parentId)
        );

        $result = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id,name)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
            'pageSize' => 1000,
        ]);

        return array_map(static fn ($folder): array => [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
        ], $result->getFiles());
    }

    /**
     * @return array{id: string, name: string}|null
     */
    protected static function findFolderByName(Drive $service, string $parentId, string $name): ?array
    {
        $query = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and '%s' in parents",
            static::escapeQueryValue($name),
            static::escapeQueryValue($parentId)
        );

        $result = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id,name)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
            'pageSize' => 1,
        ]);

        $folder = $result->getFiles()[0] ?? null;

        if (! $folder) {
            return null;
        }

        return [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
        ];
    }

    protected static function escapeQueryValue(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    protected static function buildEntityDescription(?string $currentDescription, string $entityName): string
    {
        $lines = preg_split('/\R/u', trim((string) $currentDescription)) ?: [];

        $lines = array_values(array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '[SILO_ENTITY]: ')));
        $lines[] = '[SILO_ENTITY]: ' . $entityName;

        return trim(implode("\n", $lines));
    }

    protected static function findOrCreateFolder(Drive $service, string $name, ?string $parentId = null): string
    {
        $folder = static::findFolderByName($service, (string) $parentId, $name);

        if ($folder) {
            return $folder['id'];
        }

        $metadata = new DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => $parentId ? [$parentId] : null,
        ]);

        $created = $service->files->create($metadata, [
            'fields' => 'id',
            'supportsAllDrives' => true,
        ]);

        return $created->getId();
    }
}
