<?php

namespace App\Support\Drive;

use Illuminate\Support\Str;

class DriveCommandFeedback
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{title: string, body: string, success: bool}
     */
    public static function build(
        string $command,
        array $options,
        int $exitCode,
        string $output,
        string $successTitle,
        string $failureTitle,
    ): array {
        $success = $exitCode === 0;
        $title = $success ? $successTitle : $failureTitle;

        $body = match ($command) {
            'drive:sync-unclassified' => static::formatSyncSummary($output),
            'drive:cleanup-orphans' => static::formatCleanupSummary($options, $output),
            default => static::formatFallback($output),
        };

        return [
            'title' => $title,
            'body' => $body,
            'success' => $success,
        ];
    }

    protected static function formatSyncSummary(string $output): string
    {
        $mode = static::extractMetric($output, 'mode') ?? 'incremental';
        $importedTotal = static::extractMetric($output, 'imported_total') ?? '0';
        $importedUnclassified = static::extractMetric($output, 'imported_unclassified') ?? '0';
        $skippedExisting = static::extractMetric($output, 'skipped_existing') ?? '0';
        $outsideRoot = static::extractMetric($output, 'skipped_outside_root') ?? '0';
        $errors = static::extractMetric($output, 'errors') ?? '0';

        $modeLabel = $mode === 'bootstrap' ? 'Escaneo completo' : 'Revisión incremental';

        return implode("\n", [
            "{$modeLabel} finalizado.",
            "Importados: {$importedTotal}",
            "Pendientes por clasificar: {$importedUnclassified}",
            "Ya registrados (omitidos): {$skippedExisting}",
            "Fuera de la carpeta raíz (omitidos): {$outsideRoot}",
            "Errores: {$errors}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected static function formatCleanupSummary(array $options, string $output): string
    {
        $execute = (bool) ($options['--execute'] ?? false);
        $orphans = static::extractFromPattern($output, '/Hu[eé]rfanos detectados:\s*(\d+)/u') ?? '0';

        if (! $execute) {
            return implode("\n", [
                'Detección finalizada (modo simulación).',
                "Archivos huérfanos detectados: {$orphans}",
                'No se realizaron eliminaciones ni movimientos.',
            ]);
        }

        $deleted = static::extractFromPattern($output, '/deleted=(\d+)/') ?? '0';
        $trashed = static::extractFromPattern($output, '/trashed=(\d+)/') ?? '0';
        $failed = static::extractFromPattern($output, '/failed=(\d+)/') ?? '0';

        return implode("\n", [
            'Limpieza de huérfanos finalizada.',
            "Detectados: {$orphans}",
            "Eliminados definitivamente: {$deleted}",
            "Movidos a papelera: {$trashed}",
            "Con error: {$failed}",
        ]);
    }

    protected static function formatFallback(string $output): string
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return 'Proceso finalizado sin mensajes adicionales.';
        }

        return Str::limit($trimmed, 1400);
    }

    protected static function extractMetric(string $output, string $key): ?string
    {
        return static::extractFromPattern($output, '/^' . preg_quote($key, '/') . '=(.+)$/m');
    }

    protected static function extractFromPattern(string $output, string $pattern): ?string
    {
        if (preg_match($pattern, $output, $matches) !== 1) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }
}
