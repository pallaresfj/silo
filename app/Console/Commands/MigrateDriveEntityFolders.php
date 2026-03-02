<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Support\Drive\DocumentDriveDestination;
use App\Support\GoogleDriveHelper;
use Illuminate\Console\Command;

class MigrateDriveEntityFolders extends Command
{
    protected $signature = 'drive:migrate-entity-folders
        {--execute : Ejecuta la migración (por defecto es simulación)}
        {--limit=0 : Límite de documentos a procesar (0 = sin límite)}';

    protected $description = 'Migra documentos existentes en Drive a su estructura destino anual o institucional';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');

        $query = Document::withoutGlobalScopes()
            ->withTrashed()
            ->with([
                'category:id,slug',
                'entity:id,name',
            ])
            ->whereNotNull('gdrive_id')
            ->orderBy('created_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->info('No hay documentos con gdrive_id para migrar.');

            return self::SUCCESS;
        }

        $this->info('Documentos a evaluar: ' . $documents->count());
        $this->line($execute ? 'Modo: EJECUCIÓN' : 'Modo: SIMULACIÓN');

        $already = 0;
        $moved = 0;
        $missing = 0;
        $failed = 0;

        foreach ($documents as $document) {
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolderForDestination(
                new DocumentDriveDestination(
                    storageScope: $document->storage_scope ?: Document::STORAGE_SCOPE_YEARLY,
                    year: (int) ($document->year ?? now()->year),
                    categorySlug: GoogleDriveHelper::normalizeCategorySlug($document->category?->slug),
                    entityFolder: $document->entity?->name,
                )
            );

            if (! $execute) {
                try {
                    $status = GoogleDriveHelper::getFilePlacementStatus((string) $document->gdrive_id, $targetFolderId);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("FAILED {$document->id} :: {$e->getMessage()}");
                    continue;
                }

                if ($status === 'already') {
                    $already++;
                    continue;
                }

                if ($status === 'missing') {
                    $missing++;
                    $this->warn("MISSING {$document->id} gdrive_id={$document->gdrive_id}");
                    continue;
                }

                $moved++;
                $this->line("MOVE {$document->id} gdrive_id={$document->gdrive_id}");

                continue;
            }

            try {
                $result = GoogleDriveHelper::moveFileToFolder((string) $document->gdrive_id, $targetFolderId);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("FAILED {$document->id} :: {$e->getMessage()}");
                continue;
            }

            if ($result === 'already') {
                $already++;
                continue;
            }

            if ($result === 'missing') {
                $missing++;
                $this->warn("MISSING {$document->id} gdrive_id={$document->gdrive_id}");
                continue;
            }

            $moved++;
            $this->line("MOVED {$document->id} gdrive_id={$document->gdrive_id}");
        }

        $this->newLine();
        $this->info("Resumen -> moved={$moved}, already={$already}, missing={$missing}, failed={$failed}");

        if (! $execute) {
            $this->warn('Simulación completada. Ejecuta con --execute para aplicar cambios.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
