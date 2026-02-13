<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestGoogleDrive extends Command
{
    protected $signature = 'test:google-drive';
    protected $description = 'Test Google Drive connection and upload';

    public function handle()
    {
        $this->info('=== Testing Google Drive Connection ===');

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
            $disk->put($testPath, $testContent);
            $this->info('✅ File uploaded successfully!');
        } catch (\Throwable $e) {
            $this->error('❌ Upload failed: ' . $e->getMessage());
            $this->error('   Exception: ' . get_class($e));
            $this->error('   File: ' . $e->getFile() . ':' . $e->getLine());

            // Try writeStream as alternative
            $this->info('Trying writeStream instead...');
            try {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $testContent);
                rewind($stream);
                $disk->writeStream($testPath, $stream);
                $this->info('✅ writeStream succeeded!');
            } catch (\Throwable $e2) {
                $this->error('❌ writeStream also failed: ' . $e2->getMessage());
                return 1;
            }
        }

        // Step 4: Try getting metadata
        try {
            $meta = $adapter->getMetadata($testPath);
            $this->info('✅ Metadata retrieved!');
            $extra = $meta->extraMetadata();
            $this->info('   Drive ID: ' . ($extra['id'] ?? 'N/A'));
            $this->info('   Name: ' . ($extra['name'] ?? 'N/A'));
            $this->info('   Display path: ' . ($extra['display_path'] ?? 'N/A'));
            $this->info('   Virtual path: ' . ($extra['virtual_path'] ?? 'N/A'));
            if (!empty($extra['id'])) {
                $this->info('   URL: https://drive.google.com/file/d/' . $extra['id'] . '/view');
            }
        } catch (\Throwable $e) {
            $this->error('❌ Metadata failed: ' . $e->getMessage());
            $this->error('   Exception: ' . get_class($e));
        }

        // Step 5: Cleanup - delete test file
        try {
            $disk->delete($testPath);
            $this->info('✅ Test file cleaned up');
        } catch (\Throwable $e) {
            $this->warn('⚠️  Cleanup failed (not critical): ' . $e->getMessage());
        }

        $this->info('=== Test Complete ===');
        return 0;
    }
}
