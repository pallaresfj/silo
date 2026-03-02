<?php

use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Support\Drive\DocumentDriveDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('prepares yearly document creation without attachment using the yearly scope', function () {
    $category = DocumentCategory::query()->create([
        'name' => 'Actas',
        'slug' => 'actas',
        'color' => '#3B82F6',
    ]);

    $page = new class extends CreateDocument
    {
        public function prepare(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }
    };

    $payload = $page->prepare([
        'creation_mode' => 'upload',
        'storage_scope' => Document::STORAGE_SCOPE_YEARLY,
        'title' => '2026 - Acta General',
        'year' => 2026,
        'category_id' => $category->id,
        'entity_id' => null,
        'status' => 'Borrador',
        'metadata' => ['tags' => ['general']],
    ]);

    expect($payload['storage_scope'])->toBe(Document::STORAGE_SCOPE_YEARLY);
    expect($payload['file_name'])->toBe('sin-archivo');
    expect($payload)->not->toHaveKey('creation_mode');
    expect($payload)->not->toHaveKey('attachment');
});

it('prepares institutional native document creation with the institutional destination', function () {
    $category = DocumentCategory::query()->create([
        'name' => 'Resoluciones',
        'slug' => 'resoluciones',
        'color' => '#3B82F6',
    ]);

    $entity = Entity::query()->create([
        'name' => 'Consejo Academico',
        'type' => 'Interna',
    ]);

    $page = new class extends CreateDocument
    {
        public ?DocumentDriveDestination $capturedDestination = null;

        public function prepare(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }

        protected function createNativeDocumentInGoogleDrive(
            string $title,
            string $nativeType,
            DocumentDriveDestination $destination,
        ): ?array {
            $this->capturedDestination = $destination;

            return [
                'id' => 'native-123',
                'webViewLink' => 'https://docs.google.com/document/d/native-123/edit',
                'fileName' => "{$title}.gdoc",
            ];
        }
    };

    $payload = $page->prepare([
        'creation_mode' => 'drive_native',
        'drive_native_type' => 'document',
        'storage_scope' => Document::STORAGE_SCOPE_INSTITUTIONAL,
        'title' => 'Manual de convivencia',
        'year' => 2026,
        'category_id' => $category->id,
        'entity_id' => $entity->id,
        'status' => 'Borrador',
    ]);

    expect($payload['storage_scope'])->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($payload['gdrive_id'])->toBe('native-123');
    expect($payload['gdrive_url'])->toBe('https://docs.google.com/document/d/native-123/edit');
    expect($payload['file_name'])->toBe('Manual de convivencia.gdoc');
    expect($page->capturedDestination)->not->toBeNull();
    expect($page->capturedDestination->storageScope)->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($page->capturedDestination->categorySlug)->toBe('RESOLUCIONES');
    expect($page->capturedDestination->entityFolder)->toBe('CONSEJO_ACADEMICO');
});

it('uploads institutional attachment and removes the temporary local file', function () {
    Storage::fake('local');

    $category = DocumentCategory::query()->create([
        'name' => 'Circulares',
        'slug' => 'circulares',
        'color' => '#3B82F6',
    ]);

    $temporaryPath = 'documents-temp/circular.pdf';
    Storage::disk('local')->put($temporaryPath, 'contenido');

    $page = new class extends CreateDocument
    {
        public ?DocumentDriveDestination $capturedDestination = null;

        public function prepare(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }

        protected function uploadToGoogleDrive(
            string $fileName,
            string $fileContents,
            string $mimeType,
            DocumentDriveDestination $destination,
        ): ?array {
            $this->capturedDestination = $destination;

            expect($fileName)->toBe('circular.pdf');
            expect($fileContents)->toBe('contenido');

            return [
                'id' => 'upload-456',
                'webViewLink' => 'https://drive.google.com/file/d/upload-456/view',
            ];
        }
    };

    $payload = $page->prepare([
        'creation_mode' => 'upload',
        'storage_scope' => Document::STORAGE_SCOPE_INSTITUTIONAL,
        'attachment' => $temporaryPath,
        'title' => 'Circular institucional',
        'year' => 2026,
        'category_id' => $category->id,
        'entity_id' => null,
        'status' => 'Borrador',
    ]);

    expect($payload['storage_scope'])->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($payload['gdrive_id'])->toBe('upload-456');
    expect($payload['file_name'])->toBe('circular.pdf');
    expect($page->capturedDestination)->not->toBeNull();
    expect($page->capturedDestination->storageScope)->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($page->capturedDestination->categorySlug)->toBe('CIRCULARES');
    Storage::disk('local')->assertMissing($temporaryPath);
});
