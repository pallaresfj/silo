<?php

namespace App\Support\Drive;

use App\Models\Document;
use App\Models\DriveSyncState;
use App\Models\User;
use App\Notifications\DriveUnclassifiedDetected;
use App\Support\Drive\Contracts\DriveSyncGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class DriveUnclassifiedSyncService
{
    public const STATE_KEY = 'documents_root';

    /**
     * @var array<string, string|null>
     */
    protected array $parentPathCache = [];

    /**
     * @var array<string, array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null}|null>
     */
    protected array $metadataCache = [];

    public function __construct(
        protected DriveSyncGateway $gateway,
        protected DriveImportClassifier $classifier,
    ) {
    }

    /**
     * @return array{
     *  mode: string,
     *  imported_total: int,
     *  imported_unclassified: int,
     *  skipped_existing: int,
     *  skipped_outside_root: int,
     *  errors: int,
     *  notified_recipients: int,
     *  sample_items: list<array{title: string, path: string, status: string, url: string|null}>
     * }
     */
    public function sync(bool $forceBootstrap = false): array
    {
        $summary = [
            'mode' => 'incremental',
            'imported_total' => 0,
            'imported_unclassified' => 0,
            'skipped_existing' => 0,
            'skipped_outside_root' => 0,
            'errors' => 0,
            'notified_recipients' => 0,
            'sample_items' => [],
        ];

        $rootFolderId = (string) config('filesystems.disks.google.folder');

        if (trim($rootFolderId) === '') {
            throw new \RuntimeException('GOOGLE_DRIVE_FOLDER_ID is not configured.');
        }

        $rootMeta = $this->gateway->getRootMetadata($rootFolderId);
        $driveId = $rootMeta['driveId'];

        $state = DriveSyncState::query()->firstOrNew(['key' => static::STATE_KEY]);
        $state->root_folder_id = $rootFolderId;
        $state->shared_drive_id = $driveId;

        $lastStartToken = trim((string) $state->last_start_page_token);
        $bootstrapOnEmpty = (bool) config('drive_sync.bootstrap_on_empty_state', true);

        $shouldBootstrap = $forceBootstrap || ($lastStartToken === '' && $bootstrapOnEmpty);

        if ($shouldBootstrap) {
            $summary['mode'] = 'bootstrap';
            $files = $this->gateway->allFilesRecursively($rootFolderId);

            foreach ($files as $file) {
                try {
                    $this->importFile($file, (string) ($file['path'] ?? '/'), $summary);
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Log::warning('Drive bootstrap import failed', [
                        'file_id' => $file['id'] ?? null,
                        'path' => $file['path'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $state->last_start_page_token = $this->gateway->getStartPageToken($driveId);
        } elseif ($lastStartToken === '') {
            $summary['mode'] = 'token-initialized';
            $state->last_start_page_token = $this->gateway->getStartPageToken($driveId);
        } else {
            $changesPayload = $this->gateway->allChangesSince($lastStartToken, $driveId);
            $changes = $changesPayload['changes'];
            $summary['mode'] = 'incremental';

            foreach ($changes as $change) {
                if (($change['removed'] ?? false) === true) {
                    continue;
                }

                $file = $change['file'] ?? null;

                if (! is_array($file)) {
                    continue;
                }

                if (! $this->isImportableFile($file)) {
                    continue;
                }

                try {
                    $relativePath = $this->resolveRelativePathFromFile($file, $rootFolderId);

                    if ($relativePath === null) {
                        $summary['skipped_outside_root']++;
                        continue;
                    }

                    $this->importFile($file, $relativePath, $summary);
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Log::warning('Drive incremental import failed', [
                        'file_id' => $file['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $state->last_start_page_token = $changesPayload['newStartPageToken'] ?: $lastStartToken;
        }

        $state->last_synced_at = now();
        $state->metadata = [
            'last_mode' => $summary['mode'],
            'imported_total' => $summary['imported_total'],
            'imported_unclassified' => $summary['imported_unclassified'],
            'errors' => $summary['errors'],
        ];
        $state->save();

        if ((bool) config('drive_sync.notify', true) && $summary['imported_unclassified'] > 0) {
            $summary['notified_recipients'] = $this->notifyUnclassifiedDetected($summary);
        }

        return $summary;
    }

    /**
     * @param  array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null}  $file
     * @param  array{imported_total: int, imported_unclassified: int, skipped_existing: int, sample_items: list<array{title: string, path: string, status: string, url: string|null}>}  $summary
     */
    protected function importFile(array $file, string $relativePath, array &$summary): void
    {
        $gdriveId = trim((string) ($file['id'] ?? ''));

        if ($gdriveId === '') {
            return;
        }

        $alreadyExists = Document::withoutGlobalScopes()
            ->withTrashed()
            ->where('gdrive_id', $gdriveId)
            ->exists();

        if ($alreadyExists) {
            $summary['skipped_existing']++;
            return;
        }

        $classification = $this->classifier->classify($relativePath, $file);

        Document::query()->create([
            'gdrive_id' => $gdriveId,
            'gdrive_url' => $this->resolveFileUrl($file),
            'file_name' => $classification->fileName,
            'title' => $classification->title,
            'year' => $classification->year,
            'storage_scope' => $classification->storageScope,
            'category_id' => $classification->categoryId,
            'entity_id' => $classification->entityId,
            'status' => $classification->status,
            'metadata' => array_merge($classification->metadata, [
                'import_source' => 'drive_changes_api',
                'import_scope' => $classification->storageScope,
                'import_confidence' => $classification->confidence,
                'import_path' => $relativePath,
                'path_root_segment' => explode('/', trim($relativePath, '/'))[0] ?? null,
                'imported_by' => 'system_sync',
            ]),
        ]);

        $summary['imported_total']++;

        if ($classification->status === 'Importado_Sin_Clasificar') {
            $summary['imported_unclassified']++;
        }

        if ($classification->status === 'Importado_Sin_Clasificar') {
            $summary['sample_items'][] = [
                'title' => $classification->title,
                'path' => $relativePath,
                'status' => $classification->status,
                'url' => $this->resolveFileUrl($file),
            ];
        }
    }

    /**
     * @param  array{id: string, mimeType: string, trashed: bool}  $file
     */
    protected function isImportableFile(array $file): bool
    {
        if (($file['trashed'] ?? false) === true) {
            return false;
        }

        $mimeType = (string) ($file['mimeType'] ?? '');

        return $mimeType !== 'application/vnd.google-apps.folder'
            && $mimeType !== 'application/vnd.google-apps.shortcut';
    }

    /**
     * @param  array{id: string, name: string, parents: list<string>}  $file
     */
    protected function resolveRelativePathFromFile(array $file, string $rootFolderId): ?string
    {
        $parents = $file['parents'] ?? [];

        if (! is_array($parents) || empty($parents)) {
            return null;
        }

        foreach ($parents as $parentId) {
            $parentPath = $this->resolvePathToRoot((string) $parentId, $rootFolderId, []);

            if ($parentPath === null) {
                continue;
            }

            return '/' . trim(trim($parentPath, '/') . '/' . (string) $file['name'], '/');
        }

        return null;
    }

    /**
     * Returns a relative path inside root for a folder id. Empty string means root itself.
     */
    protected function resolvePathToRoot(string $folderId, string $rootFolderId, array $visiting): ?string
    {
        if ($folderId === $rootFolderId) {
            return '';
        }

        if (array_key_exists($folderId, $this->parentPathCache)) {
            return $this->parentPathCache[$folderId];
        }

        if (isset($visiting[$folderId])) {
            return null;
        }

        $visiting[$folderId] = true;
        $meta = $this->getMetadata((string) $folderId);

        if ($meta === null || ($meta['trashed'] ?? false) === true) {
            $this->parentPathCache[$folderId] = null;

            return null;
        }

        foreach ($meta['parents'] as $parentId) {
            $parentPath = $this->resolvePathToRoot((string) $parentId, $rootFolderId, $visiting);

            if ($parentPath === null) {
                continue;
            }

            $resolved = '/' . trim(trim($parentPath, '/') . '/' . (string) $meta['name'], '/');
            $this->parentPathCache[$folderId] = $resolved;

            return $resolved;
        }

        $this->parentPathCache[$folderId] = null;

        return null;
    }

    /**
     * @return array{id: string, name: string, mimeType: string, parents: list<string>, trashed: bool, webViewLink: string|null}|null
     */
    protected function getMetadata(string $fileId): ?array
    {
        if (array_key_exists($fileId, $this->metadataCache)) {
            return $this->metadataCache[$fileId];
        }

        $this->metadataCache[$fileId] = $this->gateway->getFileMetadata($fileId);

        return $this->metadataCache[$fileId];
    }

    /**
     * @param  array{id: string, webViewLink: string|null}  $file
     */
    protected function resolveFileUrl(array $file): ?string
    {
        $url = trim((string) ($file['webViewLink'] ?? ''));

        if ($url !== '') {
            return $url;
        }

        $id = trim((string) ($file['id'] ?? ''));

        return $id !== '' ? "https://drive.google.com/file/d/{$id}/view" : null;
    }

    /**
     * @param  array{imported_total: int, imported_unclassified: int, sample_items: list<array{title: string, path: string, status: string, url: string|null}>}  $summary
     */
    protected function notifyUnclassifiedDetected(array $summary): int
    {
        $roles = collect(config('drive_sync.notify_roles', ['administrador', 'rector']))
            ->map(static fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter()
            ->values()
            ->all();

        $recipients = $this->resolveRecipients($roles);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $limit = max(1, (int) config('drive_sync.mail_top_items', 10));
        $items = array_slice($summary['sample_items'], 0, $limit);
        $reviewUrl = $this->buildUnclassifiedFilterUrl();

        Notification::send($recipients, new DriveUnclassifiedDetected(
            importedTotal: $summary['imported_total'],
            importedUnclassified: $summary['imported_unclassified'],
            topItems: $items,
            reviewUrl: $reviewUrl,
        ));

        return $recipients->count();
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    protected function resolveRecipients(array $roles): Collection
    {
        return User::query()
            ->get()
            ->filter(static function (User $user) use ($roles): bool {
                $legacyRole = strtolower(trim((string) $user->role));

                return in_array($legacyRole, $roles, true)
                    || $user->hasAnyRole($roles);
            })
            ->filter(static fn (User $user): bool => filled($user->email))
            ->unique(static fn (User $user): string => strtolower((string) $user->email))
            ->values();
    }

    protected function buildUnclassifiedFilterUrl(): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/') . '/admin/documents';
        $query = http_build_query([
            'filters' => [
                'status' => [
                    'value' => 'Importado_Sin_Clasificar',
                ],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }
}
