<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\User;
use App\Support\Drive\DriveCommandFeedback;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
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
                        $options = [];

                        if ((bool) ($data['bootstrap'] ?? false)) {
                            $options['--bootstrap'] = true;
                        }

                        $this->runCommandAndNotify(
                            command: 'drive:sync-unclassified',
                            options: $options,
                            successTitle: 'Sincronización de Drive finalizada',
                            failureTitle: 'Sincronización de Drive con errores'
                        );
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
}
