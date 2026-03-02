<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\DriveSyncState;
use App\Models\User;
use App\Support\Drive\DriveCommandFeedback;
use App\Support\Drive\DriveSyncLauncher;
use App\Support\Drive\DriveUnclassifiedSyncService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public int $lastObservedDriveSyncImportedTotal = 0;

    public int $lastObservedDriveSyncProcessedTotal = 0;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('driveSyncStatus')
                ->label(fn (): string => $this->getDriveSyncStatusLabel())
                ->icon(fn (): string => $this->getDriveSyncStatusIcon())
                ->color(fn (): string => $this->getDriveSyncStatusColor())
                ->modalHeading('Estado de sincronización de externos')
                ->modalDescription(fn (): string => $this->getDriveSyncStatusBody())
                ->modalSubmitAction(false)
                ->visible(fn (): bool => $this->canUseDriveTools()),
            Actions\ActionGroup::make([
                Actions\Action::make('syncDriveUnclassified')
                    ->label('Sincronizar externos')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Sincronizar archivos externos de Drive')
                    ->modalDescription('Importa archivos creados fuera de la app para su clasificación.')
                    ->form([
                        Toggle::make('bootstrap')
                            ->label('Forzar bootstrap completo')
                            ->default(false)
                            ->helperText('Si está activo, hace un barrido recursivo completo antes de sincronizar.'),
                    ])
                    ->action(function (array $data): void {
                        $bootstrap = (bool) ($data['bootstrap'] ?? false);

                        try {
                            $result = app(DriveSyncLauncher::class)->launch(
                                bootstrap: $bootstrap,
                                triggeredBy: auth()->user() instanceof User ? auth()->user() : null,
                            );
                        } catch (Throwable) {
                            Notification::make()
                                ->danger()
                                ->title('No fue posible iniciar la sincronización')
                                ->body('La sincronización de externos no pudo lanzarse en segundo plano. Intenta nuevamente en unos minutos.')
                                ->persistent()
                                ->send();

                            return;
                        }

                        if ($result['already_running']) {
                            Notification::make()
                                ->warning()
                                ->title('Ya hay una sincronización en ejecución')
                                ->body('El proceso sigue activo. La página mostrará el avance automáticamente mientras recorre los archivos.')
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Sincronización iniciada')
                            ->body('La sincronización de externos quedó en segundo plano. Puedes seguir trabajando y ver el avance desde este listado.')
                            ->persistent()
                            ->send();
                    }),
                Actions\Action::make('detectDriveOrphans')
                    ->label('Detectar huérfanos')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Detectar archivos huérfanos en Drive')
                    ->modalDescription('Ejecuta una simulación del comando de huérfanos. No elimina archivos.')
                    ->action(function (): void {
                        $this->runCommandAndNotify(
                            command: 'drive:cleanup-orphans',
                            successTitle: 'Detección de huérfanos finalizada',
                            failureTitle: 'Detección de huérfanos con errores'
                        );
                    }),
                Actions\Action::make('cleanupDriveOrphans')
                    ->label('Limpiar huérfanos')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Limpiar archivos huérfanos en Drive')
                    ->modalDescription('Ejecuta drive:cleanup-orphans --execute. Esta acción puede mover archivos a papelera o eliminarlos según permisos.')
                    ->form([
                        TextInput::make('limit')
                            ->label('Límite de archivos')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('0 = sin límite.'),
                    ])
                    ->action(function (array $data): void {
                        $options = ['--execute' => true];
                        $limit = (int) ($data['limit'] ?? 0);

                        if ($limit > 0) {
                            $options['--limit'] = $limit;
                        }

                        $this->runCommandAndNotify(
                            command: 'drive:cleanup-orphans',
                            options: $options,
                            successTitle: 'Limpieza de huérfanos finalizada',
                            failureTitle: 'Limpieza de huérfanos con errores'
                        );
                    }),
            ])
                ->label('Herramientas Drive')
                ->icon('heroicon-o-cloud')
                ->button()
                ->visible(fn (): bool => $this->canUseDriveTools()),
        ];
    }

    protected function canUseDriveTools(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasAnyRole(['rector', 'administrador']);
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->poll(fn (): ?string => $this->canUseDriveTools() && $this->isDriveSyncActive() ? '2s' : null)
            ->description(fn (): ?string => $this->getDriveSyncBanner());
    }

    public function refreshDriveSyncProgress(): void
    {
        $execution = $this->getDriveSyncExecution();
        $summary = is_array($execution['summary'] ?? null) ? $execution['summary'] : [];
        $importedTotal = (int) ($summary['imported_total'] ?? 0);
        $processedTotal = (int) ($execution['items_processed'] ?? 0);

        if ($importedTotal > $this->lastObservedDriveSyncImportedTotal || $processedTotal > $this->lastObservedDriveSyncProcessedTotal) {
            $this->lastObservedDriveSyncImportedTotal = $importedTotal;
            $this->lastObservedDriveSyncProcessedTotal = $processedTotal;
            $this->resetPage();
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function runCommandAndNotify(
        string $command,
        array $options = [],
        string $successTitle = 'Comando ejecutado',
        string $failureTitle = 'El comando finalizó con errores',
    ): void {
        try {
            $exitCode = Artisan::call($command, $options);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title($failureTitle)
                ->body('No fue posible ejecutar la acción en este momento. Intenta nuevamente en unos minutos.')
                ->persistent()
                ->send();

            return;
        }

        $feedback = DriveCommandFeedback::build(
            command: $command,
            options: $options,
            exitCode: $exitCode,
            output: $output,
            successTitle: $successTitle,
            failureTitle: $failureTitle,
        );

        $notification = Notification::make()
            ->title($feedback['title'])
            ->body($feedback['body'])
            ->persistent();

        if ($feedback['success']) {
            $notification->success();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    protected function getDriveSyncState(): ?DriveSyncState
    {
        return DriveSyncState::query()
            ->where('key', DriveUnclassifiedSyncService::STATE_KEY)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDriveSyncExecution(): array
    {
        return $this->getDriveSyncState()?->getExecutionMetadata() ?? [];
    }

    protected function isDriveSyncActive(): bool
    {
        $execution = $this->getDriveSyncExecution();
        $status = $this->getDriveSyncEffectiveStatus($execution);

        if (! in_array($status, [
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING,
        ], true)) {
            return false;
        }

        $heartbeat = $this->parseTimestamp($execution['heartbeat_at'] ?? null)
            ?? $this->parseTimestamp($execution['started_at'] ?? null)
            ?? $this->parseTimestamp($execution['requested_at'] ?? null);

        if (! $heartbeat instanceof Carbon) {
            return true;
        }

        $timeout = $status === DriveSyncState::EXECUTION_STATUS_QUEUED
            ? DriveSyncLauncher::QUEUED_STALE_AFTER_SECONDS
            : DriveSyncLauncher::RUNNING_STALE_AFTER_SECONDS;

        return $heartbeat->diffInSeconds(now()) <= $timeout;
    }

    protected function getDriveSyncStatusLabel(): string
    {
        $execution = $this->getDriveSyncExecution();

        if ($execution === []) {
            return 'Estado sync: sin ejecuciones';
        }

        $status = $this->getDriveSyncEffectiveStatus($execution);
        $summary = is_array($execution['summary'] ?? null) ? $execution['summary'] : [];
        $progress = $this->formatProgress(
            $execution['items_processed'] ?? null,
            $execution['items_total'] ?? null,
        );

        return match ($status) {
            DriveSyncState::EXECUTION_STATUS_QUEUED => 'Estado sync: en cola',
            DriveSyncState::EXECUTION_STATUS_RUNNING => $this->formatRunningStatusLabel($summary, $progress),
            DriveSyncState::EXECUTION_STATUS_FAILED => 'Estado sync: fallida',
            DriveSyncState::EXECUTION_STATUS_COMPLETED => 'Estado sync: completada',
            default => 'Estado sync: disponible',
        };
    }

    protected function getDriveSyncStatusIcon(): string
    {
        $status = $this->getDriveSyncEffectiveStatus($this->getDriveSyncExecution());

        return match ($status) {
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING => 'heroicon-o-arrow-path',
            DriveSyncState::EXECUTION_STATUS_FAILED => 'heroicon-o-exclamation-triangle',
            DriveSyncState::EXECUTION_STATUS_COMPLETED => 'heroicon-o-check-circle',
            default => 'heroicon-o-information-circle',
        };
    }

    protected function getDriveSyncStatusColor(): string
    {
        $status = $this->getDriveSyncEffectiveStatus($this->getDriveSyncExecution());

        return match ($status) {
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING => 'warning',
            DriveSyncState::EXECUTION_STATUS_FAILED => 'danger',
            DriveSyncState::EXECUTION_STATUS_COMPLETED => 'success',
            default => 'gray',
        };
    }

    protected function getDriveSyncBanner(): string|HtmlString|null
    {
        $execution = $this->getDriveSyncExecution();

        if ($execution === []) {
            return null;
        }

        $status = $this->getDriveSyncEffectiveStatus($execution);
        $summary = is_array($execution['summary'] ?? null) ? $execution['summary'] : [];
        $progress = $this->formatProgress(
            $execution['items_processed'] ?? null,
            $execution['items_total'] ?? null,
        );

        if ($status === DriveSyncState::EXECUTION_STATUS_QUEUED) {
            return $this->renderDriveSyncBanner('Sincronización de externos en cola. El proceso quedó programado en segundo plano.');
        }

        if ($status === DriveSyncState::EXECUTION_STATUS_RUNNING) {
            $parts = ['Sincronización de externos en curso.'];
            $imported = (int) ($summary['imported_total'] ?? 0);

            $parts[] = 'Importados hasta ahora: ' . number_format($imported, 0, ',', '.') . '.';

            if ($progress !== null) {
                $parts[] = 'Progreso: ' . $progress . '.';
            }

            $parts[] = 'Modo: ' . $this->formatModeLabel($execution['mode'] ?? null) . '.';

            return $this->renderDriveSyncBanner(implode(' ', $parts));
        }

        if ($status === DriveSyncState::EXECUTION_STATUS_FAILED) {
            $message = trim((string) ($execution['last_error'] ?? 'La ejecución dejó de reportar progreso. Puedes reintentar la sincronización.'));

            return $this->renderDriveSyncBanner('La última sincronización externa falló. ' . $message);
        }

        if ($status === DriveSyncState::EXECUTION_STATUS_COMPLETED) {
            return $this->renderDriveSyncBanner(sprintf(
                'Última sincronización externa completada. Importados: %d. Sin clasificar: %d. Errores: %d.',
                (int) ($summary['imported_total'] ?? 0),
                (int) ($summary['imported_unclassified'] ?? 0),
                (int) ($summary['errors'] ?? 0),
            ));
        }

        return null;
    }

    protected function renderDriveSyncBanner(string $message): string|HtmlString
    {
        $escapedMessage = e($message);

        if (! $this->isDriveSyncActive()) {
            return $escapedMessage;
        }

        return new HtmlString(
            $escapedMessage
            . '<div class="hidden" wire:poll.2s="refreshDriveSyncProgress" aria-hidden="true"></div>'
        );
    }

    protected function getDriveSyncStatusBody(): string
    {
        $execution = $this->getDriveSyncExecution();

        if ($execution === []) {
            return 'Todavía no hay ejecuciones registradas para la sincronización de externos.';
        }

        $status = $this->getDriveSyncEffectiveStatus($execution);
        $summary = is_array($execution['summary'] ?? null) ? $execution['summary'] : [];

        return implode("\n", array_filter([
            'Estado: ' . $this->formatStatusLabel($status),
            'Modo: ' . $this->formatModeLabel($execution['mode'] ?? null),
            'Solicitada por: ' . ((string) ($execution['requested_by'] ?? 'system')),
            'Solicitada: ' . $this->formatTimestamp($execution['requested_at'] ?? null),
            'Inicio: ' . $this->formatTimestamp($execution['started_at'] ?? null),
            'Último pulso: ' . $this->formatTimestamp($execution['heartbeat_at'] ?? null),
            'Fin: ' . $this->formatTimestamp($execution['finished_at'] ?? null),
            'Progreso: ' . ($this->formatProgress($execution['items_processed'] ?? null, $execution['items_total'] ?? null) ?? 'Sin datos'),
            filled($execution['process_id'] ?? null) ? 'PID: ' . $execution['process_id'] : null,
            filled($execution['background_log'] ?? null) ? 'Log: ' . $execution['background_log'] : null,
            'Importados: ' . (int) ($summary['imported_total'] ?? 0),
            'Sin clasificar: ' . (int) ($summary['imported_unclassified'] ?? 0),
            'Omitidos por duplicado: ' . (int) ($summary['skipped_existing'] ?? 0),
            'Fuera de la raíz: ' . (int) ($summary['skipped_outside_root'] ?? 0),
            'Errores: ' . (int) ($summary['errors'] ?? 0),
            filled($execution['last_error'] ?? null) ? 'Último error: ' . $execution['last_error'] : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    protected function getDriveSyncEffectiveStatus(array $execution): ?string
    {
        $status = $execution['status'] ?? null;

        if (! in_array($status, [
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING,
        ], true)) {
            return is_string($status) ? $status : null;
        }

        return $this->hasFreshDriveSyncHeartbeat($execution)
            ? $status
            : DriveSyncState::EXECUTION_STATUS_FAILED;
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    protected function hasFreshDriveSyncHeartbeat(array $execution): bool
    {
        $status = $execution['status'] ?? null;

        if (! in_array($status, [
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING,
        ], true)) {
            return false;
        }

        $heartbeat = $this->parseTimestamp($execution['heartbeat_at'] ?? null)
            ?? $this->parseTimestamp($execution['started_at'] ?? null)
            ?? $this->parseTimestamp($execution['requested_at'] ?? null);

        if (! $heartbeat instanceof Carbon) {
            return true;
        }

        $timeout = $status === DriveSyncState::EXECUTION_STATUS_QUEUED
            ? DriveSyncLauncher::QUEUED_STALE_AFTER_SECONDS
            : DriveSyncLauncher::RUNNING_STALE_AFTER_SECONDS;

        return $heartbeat->diffInSeconds(now()) <= $timeout;
    }

    protected function formatProgress(mixed $processed, mixed $total): ?string
    {
        if (! is_numeric($processed) && ! is_numeric($total)) {
            return null;
        }

        if (is_numeric($processed) && is_numeric($total)) {
            return number_format((int) $processed, 0, ',', '.') . '/' . number_format((int) $total, 0, ',', '.');
        }

        if (is_numeric($processed)) {
            return number_format((int) $processed, 0, ',', '.') . ' revisados';
        }

        return '0/' . number_format((int) $total, 0, ',', '.');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function formatRunningStatusLabel(array $summary, ?string $progress): string
    {
        $imported = (int) ($summary['imported_total'] ?? 0);
        $label = 'Estado sync: ' . number_format($imported, 0, ',', '.') . ' importados';

        if ($progress !== null) {
            $label .= ' (' . $progress . ')';
        }

        return $label;
    }

    protected function formatModeLabel(mixed $mode): string
    {
        return match ((string) $mode) {
            'bootstrap' => 'Escaneo completo',
            'token-initialized' => 'Inicialización del token',
            default => 'Revisión incremental',
        };
    }

    protected function formatStatusLabel(mixed $status): string
    {
        return match ((string) $status) {
            DriveSyncState::EXECUTION_STATUS_QUEUED => 'En cola',
            DriveSyncState::EXECUTION_STATUS_RUNNING => 'En curso',
            DriveSyncState::EXECUTION_STATUS_COMPLETED => 'Completada',
            DriveSyncState::EXECUTION_STATUS_FAILED => 'Fallida',
            default => 'Sin datos',
        };
    }

    protected function formatTimestamp(mixed $timestamp): string
    {
        $parsed = $this->parseTimestamp($timestamp);

        if (! $parsed instanceof Carbon) {
            return 'Sin datos';
        }

        return $parsed->timezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    protected function parseTimestamp(mixed $timestamp): ?Carbon
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
