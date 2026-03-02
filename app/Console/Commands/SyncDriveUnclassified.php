<?php

namespace App\Console\Commands;

use App\Support\Drive\DriveUnclassifiedSyncService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SyncDriveUnclassified extends Command
{
    protected $signature = 'drive:sync-unclassified
        {--bootstrap : Ejecuta un escaneo completo recursivo antes de iniciar el modo incremental}
        {--run-id= : Identificador de ejecucion en segundo plano}
        {--triggered-by= : Usuario que disparo la sincronizacion}';

    protected $description = 'Sincroniza archivos creados fuera de SILO y los importa para clasificacion.';

    public function __construct(protected DriveUnclassifiedSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (! (bool) config('drive_sync.enabled', true)) {
            $this->info('DRIVE_SYNC_ENABLED=false. Sincronizacion omitida.');

            return self::SUCCESS;
        }

        $bootstrap = (bool) $this->option('bootstrap');
        $lock = Cache::lock('drive:sync-unclassified:lock', 3600);

        if (! $lock->get()) {
            $this->warn('Otra sincronizacion ya se encuentra en ejecucion.');

            return self::SUCCESS;
        }

        try {
            $summary = $this->syncService->sync(
                forceBootstrap: $bootstrap,
                runId: $this->option('run-id') ?: null,
                triggeredBy: $this->option('triggered-by') ?: null,
            );
        } catch (LockTimeoutException $e) {
            $this->error('No se pudo obtener lock de sincronizacion: ' . $e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Sincronizacion fallida: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }

        $this->info('Sincronizacion de Drive completada.');
        $this->line('mode=' . $summary['mode']);
        $this->line('imported_total=' . $summary['imported_total']);
        $this->line('imported_unclassified=' . $summary['imported_unclassified']);
        $this->line('skipped_existing=' . $summary['skipped_existing']);
        $this->line('skipped_outside_root=' . $summary['skipped_outside_root']);
        $this->line('errors=' . $summary['errors']);
        $this->line('notified_recipients=' . $summary['notified_recipients']);

        return (int) $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
