<?php

namespace App\Support\Drive;

use App\Support\Drive\Contracts\DriveSyncGateway;
use App\Support\GoogleDriveHelper;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveSyncGateway implements DriveSyncGateway
{
    protected ?Drive $service = null;

    public function getRootMetadata(string $rootFolderId): array
    {
        $root = $this->service()->files->get($rootFolderId, [
            'fields' => 'id,name,driveId',
            'supportsAllDrives' => true,
        ]);

        return [
            'id' => (string) $root->getId(),
            'name' => (string) $root->getName(),
            'driveId' => $root->getDriveId() ? (string) $root->getDriveId() : null,
        ];
    }

    public function getStartPageToken(?string $driveId = null): string
    {
        $params = [
            'supportsAllDrives' => true,
        ];

        if ($driveId) {
            $params['driveId'] = $driveId;
        }

        $response = $this->service()->changes->getStartPageToken($params);

        return (string) $response->getStartPageToken();
    }

    public function allChangesSince(string $startPageToken, ?string $driveId = null): array
    {
        $changes = [];
        $nextPageToken = $startPageToken;
        $newStartPageToken = null;

        do {
            $params = [
                'pageToken' => $nextPageToken,
                'fields' => 'newStartPageToken,nextPageToken,changes(fileId,removed,file(id,name,mimeType,parents,trashed,webViewLink,createdTime))',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ];

            if ($driveId) {
                $params['driveId'] = $driveId;
                $params['corpora'] = 'drive';
            }

            $response = $this->service()->changes->listChanges($params);

            foreach ($response->getChanges() as $change) {
                $file = $change->getFile();

                $changes[] = [
                    'fileId' => (string) $change->getFileId(),
                    'removed' => (bool) $change->getRemoved(),
                    'file' => $file instanceof DriveFile ? $this->mapFile($file) : null,
                ];
            }

            $nextPageToken = $response->getNextPageToken();

            if ($response->getNewStartPageToken()) {
                $newStartPageToken = (string) $response->getNewStartPageToken();
            }
        } while ($nextPageToken);

        return [
            'changes' => $changes,
            'newStartPageToken' => $newStartPageToken,
        ];
    }

    public function allFilesRecursively(string $rootFolderId): array
    {
        $files = [];
        $queue = [[$rootFolderId, '']];

        while (! empty($queue)) {
            [$folderId, $relativePath] = array_shift($queue);

            foreach ($this->listChildren($folderId) as $item) {
                $itemPath = '/' . ltrim(trim($relativePath, '/') . '/' . $item['name'], '/');

                if ($item['mimeType'] === 'application/vnd.google-apps.folder') {
                    $queue[] = [$item['id'], $itemPath];
                    continue;
                }

                $item['path'] = $itemPath;
                $files[] = $item;
            }
        }

        return $files;
    }

    public function getFileMetadata(string $fileId): ?array
    {
        try {
            $file = $this->service()->files->get($fileId, [
            'fields' => 'id,name,mimeType,parents,trashed,webViewLink,createdTime',
            'supportsAllDrives' => true,
        ]);
        } catch (\Google\Service\Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? null;

            if ($reason === 'notFound') {
                return null;
            }

            throw $e;
        }

        return $this->mapFile($file);
    }

    /**
     * @return list<array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime: string|null}>
     */
    protected function listChildren(string $parentId): array
    {
        $items = [];
        $pageToken = null;

        do {
            $response = $this->service()->files->listFiles([
                'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $parentId)),
                'fields' => 'nextPageToken,files(id,name,mimeType,parents,trashed,webViewLink,createdTime)',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]);

            foreach ($response->getFiles() as $file) {
                $items[] = $this->mapFile($file);
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    /**
     * @return array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime: string|null}
     */
    protected function mapFile(DriveFile $file): array
    {
        return [
            'id' => (string) $file->getId(),
            'name' => (string) $file->getName(),
            'mimeType' => (string) $file->getMimeType(),
            'parents' => array_values(array_filter(array_map('strval', $file->getParents() ?: []))),
            'trashed' => (bool) $file->getTrashed(),
            'webViewLink' => $file->getWebViewLink() ? (string) $file->getWebViewLink() : null,
            'createdTime' => $file->getCreatedTime() ? (string) $file->getCreatedTime() : null,
        ];
    }

    protected function service(): Drive
    {
        return $this->service ??= GoogleDriveHelper::makeService();
    }
}
