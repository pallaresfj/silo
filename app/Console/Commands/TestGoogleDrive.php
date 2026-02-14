<?php

namespace App\Console\Commands;

use Google\Service\Drive\DriveFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TestGoogleDrive extends Command
{
    protected $signature = 'test:google-drive {--direct : Test direct API upload}';
    protected $description = 'Test Google Drive connection and upload';

    public function handle()
    {
        if ($this->option('direct')) {
            return $this->testDirectApi();
        }

        return $this->testFlysystemAdapter();
    }

    protected function testDirectApi(): int
    {
        $this->info('=== Testing Direct Google Drive API ===');

        try {
            $service = $this->getGoogleDriveService();
            $rootFolderId = config('filesystems.disks.google.folder');

            $this->info("Root folder ID: {$rootFolderId}");

            $folder = $service->files->get($rootFolderId, [
                'fields' => 'id, name, driveId, shared',
                'supportsAllDrives' => true,
            ]);
            $this->line('Folder name: ' . $folder->getName());
            $this->line('Shared: ' . ($folder->getShared() ? 'YES' : 'NO'));
            $this->line('Shared Drive ID: ' . ($folder->getDriveId() ?? 'N/A'));
            if (!$folder->getDriveId()) {
                $this->warn('⚠️  Esta carpeta no está en un Shared Drive. Con Service Account la subida puede fallar por cuota.');
            }

            // Test uploading a file directly
            $testFileName = 'test-direct-api-' . now()->format('Ymd_His') . '.txt';
            $testContent = 'Test file created at ' . now()->toDateTimeString();

            $this->info("Uploading test file: {$testFileName}");

            $fileMetadata = new DriveFile([
                'name' => $testFileName,
                'parents' => [$rootFolderId],
            ]);

            $file = $service->files->create($fileMetadata, [
                'data' => $testContent,
                'mimeType' => 'text/plain',
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink, name',
                'supportsAllDrives' => true,
            ]);

            $this->info('✅ File uploaded successfully!');
            $this->line("  File ID: {$file->getId()}");
            $this->line("  File Name: {$file->getName()}");
            $this->line("  Web Link: {$file->getWebViewLink()}");

            // Cleanup
            $this->info('Cleaning up test file...');
            try {
                $service->files->delete($file->getId(), [
                    'supportsAllDrives' => true,
                ]);
                $this->info('✅ Test file deleted.');
            } catch (\Throwable $cleanupError) {
                $this->warn('⚠️  Cleanup failed (not critical): ' . $cleanupError->getMessage());
            }

            $this->newLine();
            $this->info('🎉 Direct API test completed successfully!');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Direct API test failed!');
            $this->error("Error: {$e->getMessage()}");
            Log::error('Google Drive direct API test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    protected function testFlysystemAdapter(): int
    {
        $this->info('=== Testing Google Drive Flysystem Adapter ===');
        $failed = false;

        // Step 1: Check disk class
        try {
            $disk = Storage::disk('google');
            $this->info('✅ Disk class: ' . get_class($disk));
            $adapter = $disk->getAdapter();
            $this->info('✅ Adapter class: ' . get_class($adapter));
        } catch (\Throwable $e) {
            $this->error('❌ Failed to get disk: ' . $e->getMessage());
            return 1;
        }

        // Step 2: Try listing files
        try {
            $dirs = $disk->directories('/');
            $this->info('✅ Directories in root: ' . count($dirs));
            foreach (array_slice($dirs, 0, 5) as $d) {
                $this->line("   📁 {$d}");
            }
        } catch (\Throwable $e) {
            $this->warn('⚠️  Could not list directories: ' . $e->getMessage());
        }

        // Step 3: Try writing a test file
        $testContent = 'Test file from Laravel at ' . now()->toDateTimeString();
        $testPath = 'SGI-Doc/test-upload.txt';

        try {
            $this->info("Uploading test file to: {$testPath}");
            $written = $disk->put($testPath, $testContent);
            if (!$written) {
                $this->error('❌ Upload failed: Storage::put devolvió false.');
                $failed = true;
            } elseif (!$disk->exists($testPath)) {
                $this->error('❌ Upload failed: el archivo no existe luego de Storage::put.');
                $failed = true;
            } else {
                $this->info('✅ File uploaded successfully via Flysystem!');
            }
        } catch (\Throwable $e) {
            $this->error('❌ Upload failed: ' . $e->getMessage());
            $failed = true;
        }

        // Step 4: Cleanup
        try {
            if ($disk->exists($testPath)) {
                $disk->delete($testPath);
                $this->info('✅ Test file cleaned up');
            } else {
                $this->warn('⚠️  Cleanup skipped: test file was not found.');
            }
        } catch (\Throwable $e) {
            $this->warn('⚠️  Cleanup failed (not critical): ' . $e->getMessage());
        }

        $this->info('=== Test Complete ===');
        $this->newLine();
        $this->line('Tip: Run with --direct to test the direct API (recommended for uploads)');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function getGoogleDriveService(): \Google\Service\Drive
    {
        $config = config('filesystems.disks.google');

        $client = new \Google\Client();
        $client->setScopes([\Google\Service\Drive::DRIVE]);

        $privateKey = $config['private_key'] ?? null;

        if (!$privateKey) {
            throw new \RuntimeException('Google Drive Service Account not configured.');
        }

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

        return new \Google\Service\Drive($client);
    }
}
