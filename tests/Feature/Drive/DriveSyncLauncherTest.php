<?php

use App\Models\DriveSyncState;
use App\Models\User;
use App\Support\Drive\DriveSyncLauncher;
use App\Support\Drive\DriveUnclassifiedSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('filesystems.disks.google.folder', 'root-folder');
});

it('launches the external sync in background and stores queued execution metadata', function () {
    Process::fake([
        '*' => Process::result('43210'),
    ]);

    $user = User::factory()->create([
        'name' => 'Admin Sync',
        'role' => 'administrador',
    ]);

    $result = app(DriveSyncLauncher::class)->launch(true, $user);

    expect($result)->toMatchArray([
        'started' => true,
        'already_running' => false,
        'status' => DriveSyncState::EXECUTION_STATUS_QUEUED,
    ]);

    $state = DriveSyncState::query()
        ->where('key', DriveUnclassifiedSyncService::STATE_KEY)
        ->firstOrFail();

    expect($state->getExecutionMetadata())->toMatchArray([
        'run_id' => $result['run_id'],
        'status' => DriveSyncState::EXECUTION_STATUS_QUEUED,
        'bootstrap' => true,
        'mode' => 'bootstrap',
        'requested_by' => 'Admin Sync',
        'items_processed' => 0,
    ]);

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, 'drive:sync-unclassified')
            && str_contains($command, '--bootstrap');
    });
});

it('does not launch a second background process while one is already active', function () {
    Process::fake([
        '*' => Process::result('43210'),
    ]);

    $user = User::factory()->create([
        'name' => 'Admin Sync',
        'role' => 'administrador',
    ]);

    app(DriveSyncLauncher::class)->launch(false, $user);
    $result = app(DriveSyncLauncher::class)->launch(false, $user);

    expect($result)->toMatchArray([
        'started' => false,
        'already_running' => true,
        'status' => DriveSyncState::EXECUTION_STATUS_QUEUED,
    ]);

    Process::assertRanTimes(function ($process): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, 'drive:sync-unclassified');
    }, 1);
});
