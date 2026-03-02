<?php

namespace Tests\Fixtures;

use App\Support\Drive\Contracts\DriveSyncGateway;

class FakeDriveSyncGateway implements DriveSyncGateway
{
    /**
     * @param  array{id: string, name: string, driveId: string|null}  $rootMetadata
     * @param  list<array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null, path: string}>  $recursiveFiles
     * @param  list<array{fileId: string, removed: bool, file: array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null}|null}>  $changes
     * @param  array<string, array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null, createdTime?: string|null}>  $metadataById
     */
    public function __construct(
        public array $rootMetadata,
        public string $startPageToken = 'token-next',
        public array $recursiveFiles = [],
        public array $changes = [],
        public ?string $newStartPageToken = 'token-next',
        public array $metadataById = [],
    ) {
    }

    public function getRootMetadata(string $rootFolderId): array
    {
        return $this->rootMetadata;
    }

    public function getStartPageToken(?string $driveId = null): string
    {
        return $this->startPageToken;
    }

    public function allChangesSince(string $startPageToken, ?string $driveId = null): array
    {
        return [
            'changes' => $this->changes,
            'newStartPageToken' => $this->newStartPageToken,
        ];
    }

    public function allFilesRecursively(string $rootFolderId): array
    {
        return $this->recursiveFiles;
    }

    public function getFileMetadata(string $fileId): ?array
    {
        return $this->metadataById[$fileId] ?? null;
    }
}
