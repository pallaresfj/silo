<?php

namespace App\Support\Drive;

use App\Models\DriveSyncState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Illuminate\Support\Str;

class DriveSyncLauncher
{
    public const QUEUED_STALE_AFTER_SECONDS = 300;

    public const RUNNING_STALE_AFTER_SECONDS = 900;

    /**
     * @return array{started: bool, already_running: bool, run_id: string, status: string}
     */
    public function launch(bool $bootstrap = false, ?User $triggeredBy = null): array
    {
        $state = DriveSyncState::query()->firstOrNew(['key' => DriveUnclassifiedSyncService::STATE_KEY]);
        $execution = $state->getExecutionMetadata();

        if ($this->hasFreshExecution($execution)) {
            return [
                'started' => false,
                'already_running' => true,
                'run_id' => (string) ($execution['run_id'] ?? ''),
                'status' => (string) ($execution['status'] ?? DriveSyncState::EXECUTION_STATUS_RUNNING),
            ];
        }

        if ($this->isStaleExecution($execution)) {
            $state
                ->putExecutionMetadata([
                    'status' => DriveSyncState::EXECUTION_STATUS_FAILED,
                    'finished_at' => now()->toIso8601String(),
                    'last_error' => 'La ejecución anterior dejó de reportar progreso y fue marcada como detenida.',
                ])
                ->save();
        }

        $runId = (string) Str::uuid();
        $requestedAt = now()->toIso8601String();
        $state->root_folder_id = $state->root_folder_id ?: $this->resolveRootFolderId();
        $logPath = storage_path('logs/drive-sync-unclassified.log');

        $state
            ->putExecutionMetadata([
                'run_id' => $runId,
                'status' => DriveSyncState::EXECUTION_STATUS_QUEUED,
                'bootstrap' => $bootstrap,
                'mode' => $bootstrap ? 'bootstrap' : 'incremental',
                'requested_at' => $requestedAt,
                'requested_by' => $this->resolveTriggeredBy($triggeredBy),
                'started_at' => null,
                'finished_at' => null,
                'heartbeat_at' => $requestedAt,
                'items_total' => null,
                'items_processed' => 0,
                'summary' => null,
                'last_error' => null,
                'process_id' => null,
                'background_log' => $logPath,
            ])
            ->save();

        try {
            $shellCommand = $this->buildDetachedShellCommand($runId, $bootstrap, $triggeredBy, $logPath);
            $result = Process::path(base_path())
                ->run(['sh', '-c', $shellCommand]);

            if ($result->failed()) {
                throw new RuntimeException(trim($result->errorOutput()) ?: 'No se pudo lanzar el proceso de sincronización en segundo plano.');
            }

            $state
                ->putExecutionMetadata([
                    'launcher_output' => trim($result->output()),
                    'launcher_error' => trim($result->errorOutput()),
                ])
                ->save();

            $pid = $this->extractPid($result->output());

            if ($pid === null) {
                usleep(250000);
                $pid = $this->resolvePidFromProcessList($runId);
            }

            if ($pid === null) {
                throw new RuntimeException('El lanzador no devolvió un PID válido para la sincronización en segundo plano.');
            }

            $state
                ->putExecutionMetadata([
                    'process_id' => $pid,
                    'heartbeat_at' => now()->toIso8601String(),
                ])
                ->save();
        } catch (\Throwable $e) {
            $state
                ->putExecutionMetadata([
                    'status' => DriveSyncState::EXECUTION_STATUS_FAILED,
                    'finished_at' => now()->toIso8601String(),
                    'last_error' => $e->getMessage(),
                ])
                ->save();

            throw $e;
        }

        return [
            'started' => true,
            'already_running' => false,
            'run_id' => $runId,
            'status' => DriveSyncState::EXECUTION_STATUS_QUEUED,
        ];
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    protected function hasFreshExecution(array $execution): bool
    {
        if (! in_array($execution['status'] ?? null, [
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING,
        ], true)) {
            return false;
        }

        return ! $this->isStaleExecution($execution);
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    protected function isStaleExecution(array $execution): bool
    {
        $status = $execution['status'] ?? null;

        if (! in_array($status, [
            DriveSyncState::EXECUTION_STATUS_QUEUED,
            DriveSyncState::EXECUTION_STATUS_RUNNING,
        ], true)) {
            return false;
        }

        $reference = $execution['heartbeat_at']
            ?? $execution['started_at']
            ?? $execution['requested_at']
            ?? null;

        if (! is_string($reference) || trim($reference) === '') {
            return false;
        }

        try {
            $seconds = Carbon::parse($reference)->diffInSeconds(now());
        } catch (\Throwable) {
            return false;
        }

        $threshold = $status === DriveSyncState::EXECUTION_STATUS_QUEUED
            ? static::QUEUED_STALE_AFTER_SECONDS
            : static::RUNNING_STALE_AFTER_SECONDS;

        return $seconds > $threshold;
    }

    protected function resolvePhpBinary(): string
    {
        $configuredBinary = trim((string) config('drive_sync.php_cli_binary', ''));

        if ($configuredBinary !== '' && is_executable($configuredBinary)) {
            return $configuredBinary;
        }

        $candidates = array_filter([
            PHP_SAPI === 'cli' ? PHP_BINARY : null,
            $this->homePath('/Library/Application Support/Herd/bin/php'),
            '/opt/homebrew/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
            'php',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'php' || is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    protected function resolveTriggeredBy(?User $triggeredBy): string
    {
        if (! $triggeredBy instanceof User) {
            return 'system';
        }

        $name = trim((string) $triggeredBy->name);

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) $triggeredBy->email);

        if ($email !== '') {
            return $email;
        }

        return (string) $triggeredBy->getKey();
    }

    protected function resolveRootFolderId(): string
    {
        $folderId = trim((string) config('filesystems.disks.google.folder'));

        return $folderId !== '' ? $folderId : 'pending-root-folder';
    }

    protected function buildDetachedShellCommand(string $runId, bool $bootstrap, ?User $triggeredBy, string $logPath): string
    {
        $parts = [
            escapeshellarg($this->resolvePhpBinary()),
            'artisan',
            'drive:sync-unclassified',
            '--run-id=' . escapeshellarg($runId),
        ];

        if ($bootstrap) {
            $parts[] = '--bootstrap';
        }

        if ($triggeredBy instanceof User) {
            $parts[] = '--triggered-by=' . escapeshellarg((string) $triggeredBy->getKey());
        }

        $innerCommand = implode(' ', $parts);

        return sprintf(
            'nohup %s >> %s 2>&1 < /dev/null & printf "%%s\n" "$!"',
            $innerCommand,
            escapeshellarg($logPath),
        );
    }

    protected function extractPid(string $output): ?int
    {
        if (preg_match_all('/\b(\d+)\b/', $output, $matches) !== 1) {
            return null;
        }

        $pid = end($matches[1]);

        if (! is_string($pid) || $pid === '') {
            return null;
        }

        return (int) $pid;
    }

    protected function homePath(string $suffix): ?string
    {
        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            return null;
        }

        return rtrim($home, '/') . $suffix;
    }

    protected function resolvePidFromProcessList(string $runId): ?int
    {
        $needle = 'drive:sync-unclassified --run-id=' . $runId;
        $command = sprintf(
            "ps -eo pid=,command= | grep %s | grep -v grep | tail -n 1 | awk '{print \$1}'",
            escapeshellarg($needle),
        );

        $result = Process::path(base_path())
            ->run(['sh', '-c', $command]);

        if ($result->failed()) {
            return null;
        }

        return $this->extractPid($result->output());
    }
}
