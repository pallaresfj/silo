<?php

namespace App\Support\Drive\Contracts;

interface DriveSyncGateway
{
    /**
     * @return array{id: string, name: string, driveId: string|null}
     */
    public function getRootMetadata(string $rootFolderId): array;

    public function getStartPageToken(?string $driveId = null): string;

    /**
     * @return array{changes: list<array{fileId: string, removed: bool, file: array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null}|null}>, newStartPageToken: string|null}
     */
    public function allChangesSince(string $startPageToken, ?string $driveId = null): array;

    /**
     * @return list<array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null, path: string}>
     */
    public function allFilesRecursively(string $rootFolderId): array;

    /**
     * @return array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null}|null
     */
    public function getFileMetadata(string $fileId): ?array;
}
