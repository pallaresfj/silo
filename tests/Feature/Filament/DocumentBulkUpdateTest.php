<?php

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows the bulk update action to users who can update documents', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->assertTableBulkActionExists('bulkUpdateAttributes')
        ->assertTableBulkActionVisible('bulkUpdateAttributes')
        ->assertTableBulkActionHasLabel('bulkUpdateAttributes', 'Actualizar seleccionados');
});

it('hides the bulk update action from users without document update permission', function () {
    $lector = User::factory()->create(['role' => 'lector']);
    actingAs($lector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->assertTableBulkActionHidden('bulkUpdateAttributes');
});

it('updates only the status for the selected active documents', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Actas', 'actas');
    $entity = createBulkEntity('Secretaría');

    $first = createBulkDocument($category, [
        'entity_id' => $entity->id,
        'status' => 'Borrador',
    ]);
    $second = createBulkDocument($category, [
        'entity_id' => $entity->id,
        'status' => 'Pendiente_OCR',
    ]);
    $untouched = createBulkDocument($category, [
        'entity_id' => $entity->id,
        'status' => 'Borrador',
    ]);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$first, $second], [
            'status' => 'Publicado',
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($first)->status)->toBe('Publicado')
        ->and(refreshDocument($first)->entity_id)->toBe($entity->id)
        ->and(refreshDocument($second)->status)->toBe('Publicado')
        ->and(refreshDocument($second)->entity_id)->toBe($entity->id)
        ->and(refreshDocument($untouched)->status)->toBe('Borrador');
});

it('updates only the category for the selected documents', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $currentCategory = createBulkCategory('Circulares', 'circulares');
    $newCategory = createBulkCategory('Resoluciones', 'resoluciones');

    $first = createBulkDocument($currentCategory, ['status' => 'Borrador']);
    $second = createBulkDocument($currentCategory, ['status' => 'Publicado']);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$first, $second], [
            'category_id' => $newCategory->id,
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($first)->category_id)->toBe($newCategory->id)
        ->and(refreshDocument($first)->status)->toBe('Borrador')
        ->and(refreshDocument($second)->category_id)->toBe($newCategory->id)
        ->and(refreshDocument($second)->status)->toBe('Publicado');
});

it('assigns an entity to the selected documents', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Constancias', 'constancias');
    $entity = createBulkEntity('Consejo Académico');
    $first = createBulkDocument($category);
    $second = createBulkDocument($category);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$first, $second], [
            'entity_mode' => 'set',
            'entity_id' => $entity->id,
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($first)->entity_id)->toBe($entity->id)
        ->and(refreshDocument($second)->entity_id)->toBe($entity->id);
});

it('clears the entity from the selected documents', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Memorandos', 'memorandos');
    $entity = createBulkEntity('Rectoría');
    $first = createBulkDocument($category, ['entity_id' => $entity->id]);
    $second = createBulkDocument($category, ['entity_id' => $entity->id]);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$first, $second], [
            'entity_mode' => 'clear',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($first)->entity_id)->toBeNull()
        ->and(refreshDocument($second)->entity_id)->toBeNull();
});

it('updates status category and entity together in one bulk action', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $oldCategory = createBulkCategory('General', 'general');
    $newCategory = createBulkCategory('Contratos', 'contratos');
    $entity = createBulkEntity('Secretaría Académica');
    $first = createBulkDocument($oldCategory, ['status' => 'Borrador']);
    $second = createBulkDocument($oldCategory, ['status' => 'Pendiente_OCR']);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$first, $second], [
            'status' => 'Publicado',
            'category_id' => $newCategory->id,
            'entity_mode' => 'set',
            'entity_id' => $entity->id,
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($first)->status)->toBe('Publicado')
        ->and(refreshDocument($first)->category_id)->toBe($newCategory->id)
        ->and(refreshDocument($first)->entity_id)->toBe($entity->id)
        ->and(refreshDocument($second)->status)->toBe('Publicado')
        ->and(refreshDocument($second)->category_id)->toBe($newCategory->id)
        ->and(refreshDocument($second)->entity_id)->toBe($entity->id);
});

it('does not change documents when the bulk action form is left without changes', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Manuales', 'manuales');
    $entity = createBulkEntity('Tesorería');
    $document = createBulkDocument($category, [
        'entity_id' => $entity->id,
        'status' => 'Pendiente_OCR',
    ]);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$document], [
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($document)->status)->toBe('Pendiente_OCR')
        ->and(refreshDocument($document)->category_id)->toBe($category->id)
        ->and(refreshDocument($document)->entity_id)->toBe($entity->id)
        ->and(refreshDocument($document)->deleted_at)->toBeNull();
});

it('marks documents as archived without sending them to the trash', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Actos', 'actos');
    $document = createBulkDocument($category, ['status' => 'Borrador']);

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->callTableBulkAction('bulkUpdateAttributes', [$document], [
            'status' => 'Archivado',
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($document)->status)->toBe('Archivado')
        ->and(refreshDocument($document)->deleted_at)->toBeNull();
});

it('updates only active documents when the selection includes trashed ones', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Informes', 'informes');
    $active = createBulkDocument($category, ['status' => 'Borrador']);
    $trashed = createBulkDocument($category, ['status' => 'Borrador']);
    $trashed->delete();

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->set('tableFilters.trashed.value', true)
        ->callTableBulkAction('bulkUpdateAttributes', [$active, $trashed], [
            'status' => 'Publicado',
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshDocument($active)->status)->toBe('Publicado')
        ->and(refreshTrashedDocument($trashed)->status)->toBe('Borrador')
        ->and(refreshTrashedDocument($trashed)->deleted_at)->not->toBeNull();
});

it('does not update documents when only trashed records are selected', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    $category = createBulkCategory('Históricos', 'historicos');
    $trashed = createBulkDocument($category, ['status' => 'Pendiente_OCR']);
    $trashed->delete();

    actingAs($rector);

    Livewire::test(ListDocuments::class)
        ->loadTable()
        ->set('tableFilters.trashed.value', false)
        ->callTableBulkAction('bulkUpdateAttributes', [$trashed], [
            'status' => 'Publicado',
            'entity_mode' => 'keep',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect(refreshTrashedDocument($trashed)->status)->toBe('Pendiente_OCR')
        ->and(refreshTrashedDocument($trashed)->deleted_at)->not->toBeNull();
});

function createBulkCategory(string $name, string $slug): DocumentCategory
{
    return DocumentCategory::create([
        'name' => $name,
        'slug' => $slug,
        'color' => '#3B82F6',
    ]);
}

function createBulkEntity(string $name): Entity
{
    return Entity::create([
        'name' => $name,
        'type' => 'Interna',
    ]);
}

function createBulkDocument(DocumentCategory $category, array $overrides = []): Document
{
    return Document::create([
        'gdrive_id' => $overrides['gdrive_id'] ?? null,
        'gdrive_url' => $overrides['gdrive_url'] ?? null,
        'file_name' => $overrides['file_name'] ?? 'documento.pdf',
        'title' => $overrides['title'] ?? 'Documento ' . fake()->unique()->numberBetween(1000, 9999),
        'year' => $overrides['year'] ?? 2026,
        'category_id' => $category->id,
        'entity_id' => $overrides['entity_id'] ?? null,
        'status' => $overrides['status'] ?? 'Borrador',
        'metadata' => $overrides['metadata'] ?? null,
    ]);
}

function refreshDocument(Document $document): Document
{
    return Document::withoutGlobalScopes()->findOrFail($document->getKey());
}

function refreshTrashedDocument(Document $document): Document
{
    return Document::withoutGlobalScopes()->withTrashed()->findOrFail($document->getKey());
}
