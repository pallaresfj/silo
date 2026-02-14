<?php

namespace App\Console\Commands;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDriveOrphans extends Command
{
    protected $signature = 'drive:cleanup-orphans
        {--execute : Ejecuta la limpieza (por defecto es simulación)}
        {--limit=0 : Límite de archivos a procesar en modo --execute (0 = sin límite)}';

    protected $description = 'Detecta archivos huérfanos en Google Drive (sin registro en documents) y los mueve a papelera o los elimina según permisos.';

    public function handle(): int
    {
        $rootFolderId = config('filesystems.disks.google.folder');

        if (blank($rootFolderId)) {
            $this->error('GOOGLE_DRIVE_FOLDER_ID no está configurado.');

            return self::FAILURE;
        }

        try {
            $service = $this->makeGoogleDriveService();
            $rootMeta = $service->files->get($rootFolderId, [
                'fields' => 'id,name,driveId',
                'supportsAllDrives' => true,
            ]);
        } catch (\Throwable $e) {
            $this->error('No se pudo inicializar Google Drive: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Root folder: ' . $rootMeta->getName() . " ({$rootFolderId})");
        $this->line('Shared Drive ID: ' . ($rootMeta->getDriveId() ?: 'N/A'));

        $dbIds = DB::table('documents')
            ->whereNotNull('gdrive_id')
            ->pluck('gdrive_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $dbIdLookup = array_fill_keys($dbIds, true);
        $this->line('IDs en BD: ' . count($dbIds));

        $files = $this->listFilesRecursively($service, $rootFolderId, '/' . $rootMeta->getName());
        $this->line('Archivos activos en Drive bajo root: ' . count($files));

        $orphans = array_values(array_filter($files, static function (array $file) use ($dbIdLookup): bool {
            return ! isset($dbIdLookup[$file['id']]);
        }));

        $this->newLine();
        $this->info('Huérfanos detectados: ' . count($orphans));

        if (empty($orphans)) {
            $this->info('No hay huérfanos. Nada por limpiar.');

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $file): array => [
            'id' => $file['id'],
            'name' => $file['name'],
            'path' => $file['path'],
        ], $orphans);

        $this->table(['id', 'name', 'path'], $rows);

        if (! $this->option('execute')) {
            $this->warn('Simulación completada. Ejecuta con --execute para aplicar cambios.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $orphans = array_slice($orphans, 0, $limit);
            $this->warn("Aplicando límite: se procesarán {$limit} archivos como máximo.");
        }

        $deleted = 0;
        $trashed = 0;
        $failed = 0;

        foreach ($orphans as $file) {
            $result = $this->cleanupFile($service, $file['id']);

            if ($result === 'deleted') {
                $deleted++;
                $this->line("DELETE  {$file['id']}  {$file['name']}");
                continue;
            }

            if ($result === 'trashed') {
                $trashed++;
                $this->line("TRASH   {$file['id']}  {$file['name']}");
                continue;
            }

            if ($result === 'missing') {
                $this->line("MISSING {$file['id']}  {$file['name']}");
                continue;
            }

            $failed++;
            $this->error("FAILED  {$file['id']}  {$file['name']}");
        }

        $this->newLine();
        $this->info("Resumen: deleted={$deleted}, trashed={$trashed}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array{id: string, name: string, path: string}>
     */
    protected function listFilesRecursively(Drive $service, string $rootId, string $rootPath): array
    {
        $files = [];
        $queue = [[$rootId, $rootPath]];

        while (! empty($queue)) {
            [$folderId, $folderPath] = array_shift($queue);

            foreach ($this->listChildren($service, $folderId) as $item) {
                $itemPath = $folderPath . '/' . $item['name'];

                if ($item['mimeType'] === 'application/vnd.google-apps.folder') {
                    $queue[] = [$item['id'], $itemPath];
                    continue;
                }

                $files[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'path' => $itemPath,
                ];
            }
        }

        return $files;
    }

    /**
     * @return array<int, array{id: string, name: string, mimeType: string}>
     */
    protected function listChildren(Drive $service, string $parentId): array
    {
        $items = [];
        $pageToken = null;

        do {
            $result = $service->files->listFiles([
                'q' => "'{$parentId}' in parents and trashed = false",
                'fields' => 'nextPageToken,files(id,name,mimeType)',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]);

            foreach ($result->getFiles() as $file) {
                $items[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                ];
            }

            $pageToken = $result->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    /**
     * @return 'deleted'|'trashed'|'missing'|'failed'
     */
    protected function cleanupFile(Drive $service, string $fileId): string
    {
        try {
            $file = $service->files->get($fileId, [
                'fields' => 'id,trashed,capabilities(canDelete,canTrash)',
                'supportsAllDrives' => true,
            ]);
        } catch (\Google\Service\Exception $e) {
            $reason = $e->getErrors()[0]['reason'] ?? null;

            return $reason === 'notFound' ? 'missing' : 'failed';
        } catch (\Throwable) {
            return 'failed';
        }

        if ($file->getTrashed()) {
            return 'trashed';
        }

        $capabilities = $file->getCapabilities();
        $canDelete = (bool) $capabilities?->getCanDelete();
        $canTrash = (bool) $capabilities?->getCanTrash();

        if ($canDelete) {
            try {
                $service->files->delete($fileId, ['supportsAllDrives' => true]);

                return 'deleted';
            } catch (\Throwable) {
                return 'failed';
            }
        }

        if ($canTrash) {
            try {
                $service->files->update(
                    $fileId,
                    new DriveFile(['trashed' => true]),
                    [
                        'fields' => 'id,trashed',
                        'supportsAllDrives' => true,
                    ]
                );

                return 'trashed';
            } catch (\Throwable) {
                return 'failed';
            }
        }

        return 'failed';
    }

    protected function makeGoogleDriveService(): Drive
    {
        $config = config('filesystems.disks.google');
        $privateKey = $config['private_key'] ?? null;

        if (! $privateKey) {
            throw new \RuntimeException('Google Drive Service Account not configured.');
        }

        $client = new \Google\Client();
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
}

