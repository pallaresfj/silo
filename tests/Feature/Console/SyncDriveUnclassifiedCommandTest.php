<?php

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DriveSyncState;
use App\Models\Entity;
use App\Support\Drive\Contracts\DriveSyncGateway;
use App\Support\Drive\DriveUnclassifiedSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeDriveSyncGateway;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('drive_sync.enabled', true);
    config()->set('drive_sync.notify', false);
    config()->set('filesystems.disks.google.folder', 'root-folder');
});

it('imports orphan files during bootstrap without duplicating existing records', function () {
    $category = createSyncCategory('Certificados', 'certificados');
    $entity = Entity::query()->create([
        'name' => 'Secretaria Academica',
        'type' => 'Interna',
    ]);

    Document::query()->create([
        'gdrive_id' => 'existing-file',
        'gdrive_url' => 'https://drive.google.com/file/d/existing-file/view',
        'file_name' => 'existente.pdf',
        'title' => 'Existente',
        'year' => 2026,
        'category_id' => $category->id,
        'entity_id' => $entity->id,
        'status' => 'Publicado',
    ]);

    app()->instance(DriveSyncGateway::class, new FakeDriveSyncGateway(
        rootMetadata: ['id' => 'root-folder', 'name' => 'SGI_SILO_DOC', 'driveId' => 'drive-1'],
        startPageToken: 'token-bootstrap',
        recursiveFiles: [
            [
                'id' => 'existing-file',
                'name' => 'existente.pdf',
                'mimeType' => 'application/pdf',
                'parents' => ['entity-folder'],
                'trashed' => false,
                'webViewLink' => 'https://drive.google.com/file/d/existing-file/view',
                'path' => '/2026/certificados/secretaria-academica/existente.pdf',
            ],
            [
                'id' => 'new-file',
                'name' => 'nuevo.pdf',
                'mimeType' => 'application/pdf',
                'parents' => ['entity-folder'],
                'trashed' => false,
                'webViewLink' => 'https://drive.google.com/file/d/new-file/view',
                'path' => '/2026/certificados/secretaria-academica/nuevo.pdf',
            ],
        ],
    ));

    $summary = app(DriveUnclassifiedSyncService::class)->sync(true, 'run-bootstrap', 'Administrador');

    expect($summary['mode'])->toBe('bootstrap');
    expect($summary['imported_total'])->toBe(1);
    expect($summary['skipped_existing'])->toBe(1);

    expect(Document::withoutGlobalScopes()->where('gdrive_id', 'new-file')->exists())->toBeTrue();

    $imported = Document::withoutGlobalScopes()->where('gdrive_id', 'new-file')->firstOrFail();
    expect($imported->status)->toBe('Importado_Sin_Clasificar');
    expect($imported->storage_scope)->toBe(Document::STORAGE_SCOPE_YEARLY);

    $state = DriveSyncState::query()->where('key', 'documents_root')->firstOrFail();
    expect($state->last_start_page_token)->toBe('token-bootstrap');
    expect($state->getExecutionMetadata())->toMatchArray([
        'run_id' => 'run-bootstrap',
        'status' => DriveSyncState::EXECUTION_STATUS_COMPLETED,
        'requested_by' => 'Administrador',
        'mode' => 'bootstrap',
        'items_total' => 2,
        'items_processed' => 2,
    ]);
});

it('processes incremental changes and skips non-importable records', function () {
    $category = createSyncCategory('Certificados', 'certificados');
    createSyncCategory('Sin clasificar', 'sin-clasificar');
    $entity = Entity::query()->create([
        'name' => 'Secretaria Academica',
        'type' => 'Interna',
    ]);

    Document::query()->create([
        'gdrive_id' => 'existing-file',
        'gdrive_url' => 'https://drive.google.com/file/d/existing-file/view',
        'file_name' => 'existente.pdf',
        'title' => 'Existente',
        'year' => 2026,
        'category_id' => $category->id,
        'entity_id' => $entity->id,
        'status' => 'Publicado',
    ]);

    DriveSyncState::query()->create([
        'key' => 'documents_root',
        'root_folder_id' => 'root-folder',
        'shared_drive_id' => 'drive-1',
        'last_start_page_token' => 'token-old',
    ]);

    app()->instance(DriveSyncGateway::class, new FakeDriveSyncGateway(
        rootMetadata: ['id' => 'root-folder', 'name' => 'SGI_SILO_DOC', 'driveId' => 'drive-1'],
        changes: [
            [
                'fileId' => 'existing-file',
                'removed' => false,
                'file' => [
                    'id' => 'existing-file',
                    'name' => 'existente.pdf',
                    'mimeType' => 'application/pdf',
                    'parents' => ['entity-folder'],
                    'trashed' => false,
                    'webViewLink' => 'https://drive.google.com/file/d/existing-file/view',
                ],
            ],
            [
                'fileId' => 'removed-file',
                'removed' => true,
                'file' => null,
            ],
            [
                'fileId' => 'folder-file',
                'removed' => false,
                'file' => [
                    'id' => 'folder-file',
                    'name' => 'folder',
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => ['root-folder'],
                    'trashed' => false,
                    'webViewLink' => null,
                ],
            ],
            [
                'fileId' => 'trashed-file',
                'removed' => false,
                'file' => [
                    'id' => 'trashed-file',
                    'name' => 'trash.pdf',
                    'mimeType' => 'application/pdf',
                    'parents' => ['entity-folder'],
                    'trashed' => true,
                    'webViewLink' => null,
                ],
            ],
            [
                'fileId' => 'outside-file',
                'removed' => false,
                'file' => [
                    'id' => 'outside-file',
                    'name' => 'outside.pdf',
                    'mimeType' => 'application/pdf',
                    'parents' => ['outside-folder'],
                    'trashed' => false,
                    'webViewLink' => null,
                ],
            ],
            [
                'fileId' => 'new-file',
                'removed' => false,
                'file' => [
                    'id' => 'new-file',
                    'name' => 'nuevo.pdf',
                    'mimeType' => 'application/pdf',
                    'parents' => ['entity-folder'],
                    'trashed' => false,
                    'webViewLink' => null,
                ],
            ],
        ],
        newStartPageToken: 'token-new',
        metadataById: [
            'entity-folder' => [
                'id' => 'entity-folder',
                'name' => 'secretaria-academica',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['category-folder'],
                'trashed' => false,
                'webViewLink' => null,
            ],
            'category-folder' => [
                'id' => 'category-folder',
                'name' => 'certificados',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['year-folder'],
                'trashed' => false,
                'webViewLink' => null,
            ],
            'year-folder' => [
                'id' => 'year-folder',
                'name' => '2026',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['root-folder'],
                'trashed' => false,
                'webViewLink' => null,
            ],
            'outside-folder' => [
                'id' => 'outside-folder',
                'name' => 'outside',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['outside-root'],
                'trashed' => false,
                'webViewLink' => null,
            ],
            'outside-root' => [
                'id' => 'outside-root',
                'name' => 'OtherRoot',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [],
                'trashed' => false,
                'webViewLink' => null,
            ],
        ],
    ));

    $summary = app(DriveUnclassifiedSyncService::class)->sync(false, 'run-incremental', 'Administrador');

    expect($summary['mode'])->toBe('incremental');
    expect($summary['imported_total'])->toBe(1);
    expect($summary['skipped_existing'])->toBe(1);
    expect($summary['skipped_outside_root'])->toBe(1);

    expect(Document::withoutGlobalScopes()->where('gdrive_id', 'new-file')->exists())->toBeTrue();

    $imported = Document::withoutGlobalScopes()->where('gdrive_id', 'new-file')->firstOrFail();
    expect($imported->storage_scope)->toBe(Document::STORAGE_SCOPE_YEARLY);

    $state = DriveSyncState::query()->where('key', 'documents_root')->firstOrFail();
    expect($state->last_start_page_token)->toBe('token-new');
    expect($state->getExecutionMetadata())->toMatchArray([
        'run_id' => 'run-incremental',
        'status' => DriveSyncState::EXECUTION_STATUS_COMPLETED,
        'requested_by' => 'Administrador',
        'mode' => 'incremental',
        'items_total' => 6,
        'items_processed' => 6,
    ]);
});

it('imports institutional files using the institutional scope and created time year', function () {
    createSyncCategory('Actas', 'actas');

    DriveSyncState::query()->create([
        'key' => 'documents_root',
        'root_folder_id' => 'root-folder',
        'shared_drive_id' => 'drive-1',
        'last_start_page_token' => 'token-old',
    ]);

    app()->instance(DriveSyncGateway::class, new FakeDriveSyncGateway(
        rootMetadata: ['id' => 'root-folder', 'name' => 'SGI_SILO_DOC', 'driveId' => 'drive-1'],
        changes: [
            [
                'fileId' => 'institutional-file',
                'removed' => false,
                'file' => [
                    'id' => 'institutional-file',
                    'name' => 'manual.pdf',
                    'mimeType' => 'application/pdf',
                    'parents' => ['category-folder'],
                    'trashed' => false,
                    'webViewLink' => null,
                    'createdTime' => '2024-08-10T15:20:00Z',
                ],
            ],
        ],
        newStartPageToken: 'token-new',
        metadataById: [
            'category-folder' => [
                'id' => 'category-folder',
                'name' => 'actas',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['institutional-folder'],
                'trashed' => false,
                'webViewLink' => null,
            ],
            'institutional-folder' => [
                'id' => 'institutional-folder',
                'name' => 'INSTITUCIONAL',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['root-folder'],
                'trashed' => false,
                'webViewLink' => null,
            ],
        ],
    ));

    $summary = app(DriveUnclassifiedSyncService::class)->sync();

    expect($summary['mode'])->toBe('incremental');
    expect($summary['imported_total'])->toBe(1);

    $imported = Document::withoutGlobalScopes()->where('gdrive_id', 'institutional-file')->firstOrFail();
    expect($imported->storage_scope)->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($imported->year)->toBe(2024);
    expect($imported->metadata['import_scope'])->toBe(Document::STORAGE_SCOPE_INSTITUTIONAL);
    expect($imported->metadata['path_root_segment'])->toBe('INSTITUCIONAL');
});

it('registers hourly scheduler with overlap protection', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduledEvent) => str_contains((string) $scheduledEvent->command, 'drive:sync-unclassified'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});

function createSyncCategory(string $name, string $slug): DocumentCategory
{
    return DocumentCategory::query()->create([
        'name' => $name,
        'slug' => $slug,
        'color' => '#3B82F6',
    ]);
}
