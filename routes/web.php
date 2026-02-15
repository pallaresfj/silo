<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::post('/auth/logout', [GoogleAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('auth.logout');

// TEMPORARY: Debug route for Google Drive - Folder Permissions Check
Route::get('/test-gdrive', function () {
    $output = [];
    $folderId = config('filesystems.disks.google.folder');

    $output[] = '=== Google Drive Folder Diagnostics ===';
    $output[] = 'Configured Folder ID: ' . ($folderId ?? 'MISSING');

    if (!$folderId) {
        return 'ERROR: No folder ID configured.';
    }

    try {
        $disk = Storage::disk('google');
        $adapter = $disk->getAdapter();
        $service = $adapter->getService();

        $output[] = '✅ Service initialized';

        // 1. Get Folder Metadata
        try {
            $folder = $service->files->get($folderId, [
                'fields' => 'id, name, mimeType, capabilities, owners, shared, driveId',
                'supportsAllDrives' => true
            ]);

            $output[] = '✅ Folder found!';
            $output[] = 'Name: ' . $folder->getName();
            $output[] = 'MimeType: ' . $folder->getMimeType();
            $output[] = 'Shared: ' . ($folder->getShared() ? 'YES' : 'NO');
            $output[] = 'Drive ID (if Team Drive): ' . ($folder->getDriveId() ?? 'N/A');

            $output[] = '--- Capabilities (Permissions) ---';
            $caps = $folder->getCapabilities();
            $output[] = 'canAddChildren: ' . ($caps->getCanAddChildren() ? 'YES' : 'NO');
            $output[] = 'canListChildren: ' . ($caps->getCanListChildren() ? 'YES' : 'NO');
            $output[] = 'canEdit: ' . ($caps->getCanEdit() ? 'YES' : 'NO');

            $output[] = '--- Owners ---';
            foreach ($folder->getOwners() as $owner) {
                $output[] = 'Owner: ' . $owner->getDisplayName() . ' (' . $owner->getEmailAddress() . ')';
            }

        } catch (\Google\Service\Exception $e) {
            $errors = json_decode($e->getMessage(), true);
            $reason = $errors['error']['errors'][0]['reason'] ?? 'unknown';
            $message = $errors['error']['message'] ?? $e->getMessage();

            $output[] = '❌ Failed to get folder metadata';
            $output[] = "Reason: {$reason}";
            $output[] = "Message: {$message}";

            if ($e->getCode() == 404) {
                $output[] = 'FATAL: The Service Account cannot see this folder via API.';
                $output[] = 'Possible causes:';
                $output[] = '1. Access was not granted to the Service Account email.';
                $output[] = '2. The folder ID is incorrect.';
            }
        }

        // 2. Try to List Children (sanity check)
        $output[] = '';
        $output[] = '--- Children Check ---';
        try {
            $children = $service->files->listFiles([
                'q' => "'{$folderId}' in parents and trashed = false",
                'pageSize' => 5,
                'fields' => 'files(id, name)',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true
            ]);
            $output[] = 'Found ' . count($children->getFiles()) . ' items in folder.';
            foreach ($children->getFiles() as $child) {
                $output[] = "- {$child->getName()} ({$child->getId()})";
            }
        } catch (\Throwable $e) {
            $output[] = '❌ Failed to list children: ' . $e->getMessage();
        }

    } catch (\Throwable $e) {
        $output[] = '❌ Critical Error: ' . $e->getMessage();
        $output[] = $e->getTraceAsString();
    }

    return '<pre>' . implode("\n", $output) . '</pre>';
});
