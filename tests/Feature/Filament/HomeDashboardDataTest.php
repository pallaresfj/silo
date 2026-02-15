<?php

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Models\User;
use App\Support\Dashboard\HomeDashboardDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('builds metrics and top categories for rector users', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);

    $actas = createCategory('Actas de Examen', 'actas-examen');
    $certificados = createCategory('Certificados', 'certificados');
    $reglamentos = createCategory('Reglamentos', 'reglamentos');
    $expedientes = createCategory('Expedientes', 'expedientes');
    createCategory('Otros', 'otros');

    createDocument($actas, ['status' => 'Borrador']);
    createDocument($actas, ['status' => 'Pendiente_OCR']);
    createDocument($actas, ['status' => 'Publicado']);
    createDocument($actas, ['status' => 'Publicado']);

    createDocument($certificados, ['status' => 'Importado_Sin_Clasificar']);
    createDocument($certificados, ['status' => 'Publicado']);
    createDocument($certificados, ['status' => 'Borrador']);

    createDocument($reglamentos, ['status' => 'Archivado']);
    createDocument($reglamentos, ['status' => 'Publicado']);

    createDocument($expedientes, ['status' => 'Publicado']);

    $trashedPending = createDocument($expedientes, ['status' => 'Pendiente_OCR']);
    $trashedPublished = createDocument($expedientes, ['status' => 'Publicado']);
    $trashedPending->delete();
    $trashedPublished->delete();

    $data = app(HomeDashboardDataBuilder::class)->build();

    expect($data['metrics'])->toBe([
        'pending' => 4,
        'approved' => 5,
        'archived' => 3,
    ]);

    expect($data['topCategories'])->toHaveCount(4);
    expect($data['metricLinks']['pending'])
        ->toContain('filters%5Bdashboard_bucket%5D%5Bvalue%5D=pending');
    expect($data['metricLinks']['approved'])
        ->toContain('filters%5Bdashboard_bucket%5D%5Bvalue%5D=approved');
    expect($data['metricLinks']['archived'])
        ->toContain('filters%5Bdashboard_bucket%5D%5Bvalue%5D=archived')
        ->toContain('filters%5Btrashed%5D%5Bvalue%5D=1');
    expect(array_column($data['topCategories'], 'name'))->toBe([
        'Actas de Examen',
        'Certificados',
        'Reglamentos',
        'Expedientes',
    ]);
    expect(array_column($data['topCategories'], 'count'))->toBe([4, 3, 2, 1]);
    expect($data['topCategories'][0]['filteredUrl'])
        ->toContain('filters%5Bcategory%5D%5Bvalue%5D=' . $actas->id);
});

it('respects document visibility scope for non rector users', function () {
    $docente = User::factory()->create(['role' => 'docente']);
    actingAs($docente);

    $category = createCategory('General', 'general');

    createDocument($category, ['status' => 'Publicado']);
    createDocument($category, ['status' => 'Borrador']);
    createDocument($category, ['status' => 'Archivado']);

    $trashedPublished = createDocument($category, ['status' => 'Publicado']);
    $trashedPublished->delete();

    $data = app(HomeDashboardDataBuilder::class)->build();

    expect($data['metrics'])->toBe([
        'pending' => 0,
        'approved' => 1,
        'archived' => 1,
    ]);

    expect($data['reviewQueue'])->toBeEmpty();
    expect($data['topCategories'])->toHaveCount(1);
    expect($data['topCategories'][0]['count'])->toBe(1);
});

it('limits the review queue to five items and keeps newest first', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);

    $category = createCategory('Certificados', 'certificados');
    $entity = Entity::create([
        'name' => 'Secretaría Académica',
        'type' => 'Interna',
    ]);

    $oldest = createDocument($category, [
        'title' => 'Documento 1',
        'status' => 'Borrador',
        'entity_id' => $entity->id,
    ], Carbon::parse('2026-02-01 08:00:00'));

    createDocument($category, [
        'title' => 'Documento 2',
        'status' => 'Pendiente_OCR',
        'entity_id' => $entity->id,
    ], Carbon::parse('2026-02-01 09:00:00'));

    createDocument($category, [
        'title' => 'Documento 3',
        'status' => 'Importado_Sin_Clasificar',
        'entity_id' => $entity->id,
    ], Carbon::parse('2026-02-01 10:00:00'));

    createDocument($category, [
        'title' => 'Documento 4',
        'status' => 'Borrador',
        'entity_id' => $entity->id,
    ], Carbon::parse('2026-02-01 11:00:00'));

    createDocument($category, [
        'title' => 'Documento 5',
        'status' => 'Pendiente_OCR',
        'entity_id' => $entity->id,
    ], Carbon::parse('2026-02-01 12:00:00'));

    $newest = createDocument($category, [
        'title' => 'Documento 6',
        'status' => 'Borrador',
        'entity_id' => $entity->id,
        'gdrive_url' => 'https://drive.google.com/file/d/test-abc/view',
    ], Carbon::parse('2026-02-01 13:00:00'));

    $data = app(HomeDashboardDataBuilder::class)->build();

    expect($data['reviewQueue'])->toHaveCount(5);
    expect($data['reviewQueue'][0]['title'])->toBe('Documento 6');
    expect($data['reviewQueue'][0]['editUrl'])
        ->toBe(DocumentResource::getUrl('edit', ['record' => $newest]));
    expect($data['reviewQueue'][0]['openUrl'])
        ->toContain('https://drive.google.com/file/d/test-abc/view')
        ->toContain('authuser=' . urlencode((string) $rector->email));
    expect($data['reviewQueue'][0]['icon'])
        ->toBe('heroicon-o-document');
    expect(array_column($data['reviewQueue'], 'title'))
        ->not->toContain('Documento 1');
    expect(array_column($data['reviewQueue'], 'id'))
        ->not->toContain((string) $oldest->id);
});

it('renders the new dashboard in /admin with queue links', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);
    $this->withoutVite();

    $category = createCategory('Actas de Examen', 'actas-examen');
    $entity = Entity::create([
        'name' => 'Consejo Académico',
        'type' => 'Interna',
    ]);

    $document = createDocument($category, [
        'title' => 'Acta de Consejo #14',
        'status' => 'Pendiente_OCR',
        'entity_id' => $entity->id,
        'gdrive_url' => 'https://drive.google.com/file/d/file-987/view',
    ]);

    $response = $this->get('/admin');

    $response->assertStatus(200);
    $response->assertSee('Buscar documentos...');
    $response->assertSee('Nuevo documento');
    $response->assertSee('name="search"', false);
    $response->assertSee('action="' . DocumentResource::getUrl('index') . '"', false);
    $response->assertSee(DocumentResource::getUrl('create'), false);
    $response->assertSee('filters%5Bdashboard_bucket%5D%5Bvalue%5D=pending', false);
    $response->assertSee('filters%5Bdashboard_bucket%5D%5Bvalue%5D=approved', false);
    $response->assertSee('filters%5Bdashboard_bucket%5D%5Bvalue%5D=archived', false);
    $response->assertSee('filters%5Btrashed%5D%5Bvalue%5D=1', false);
    $response->assertSee('Pendientes');
    $response->assertSee('Aprobados');
    $response->assertSee('Archivados');
    $response->assertSee('Categorías Principales');
    $response->assertSee('Bandeja de Revisión');
    $response->assertSee('Abrir');
    $response->assertSee('Editar');
    $response->assertSee('https://drive.google.com/file/d/file-987/view', false);
    $response->assertSee('authuser=' . urlencode((string) $rector->email), false);
    $response->assertSee(DocumentResource::getUrl('edit', ['record' => $document]), false);
});

it('hides create document actions in dashboard for lector role', function () {
    $lector = User::factory()->create(['role' => 'lector']);
    actingAs($lector);
    $this->withoutVite();

    $category = createCategory('General', 'general');
    createDocument($category, [
        'title' => 'Documento Publicado',
        'status' => 'Publicado',
    ]);

    $response = $this->get('/admin');

    $response->assertStatus(200);
    $response->assertDontSee('Nuevo documento');
    $response->assertDontSee('href="' . DocumentResource::getUrl('create') . '"', false);
});

it('applies category filter through documents query string alias', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);
    $this->withoutVite();

    $categoryA = createCategory('Actas', 'actas');
    $categoryB = createCategory('Certificados', 'certificados');

    $docA = createDocument($categoryA, [
        'title' => 'Documento Solo Categoria A',
        'status' => 'Publicado',
    ]);

    $docB = createDocument($categoryB, [
        'title' => 'Documento Solo Categoria B',
        'status' => 'Publicado',
    ]);

    $response = $this->get('/admin/documents?filters[category][value]=' . $categoryA->id);

    $response->assertStatus(200);
    $response->assertSee((string) $docA->title);
    $response->assertDontSee((string) $docB->title);
});

it('applies dashboard bucket filter alias for pending approved and archived', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);
    $this->withoutVite();

    $category = createCategory('General', 'general');

    $pendingDoc = createDocument($category, [
        'title' => 'Documento Pendiente',
        'status' => 'Borrador',
    ]);

    $approvedDoc = createDocument($category, [
        'title' => 'Documento Aprobado',
        'status' => 'Publicado',
    ]);

    $archivedDoc = createDocument($category, [
        'title' => 'Documento Archivado',
        'status' => 'Archivado',
    ]);

    $trashedDoc = createDocument($category, [
        'title' => 'Documento Eliminado',
        'status' => 'Publicado',
    ]);
    $trashedDoc->delete();

    $pendingResponse = $this->get('/admin/documents?filters[dashboard_bucket][value]=pending');
    $pendingResponse->assertStatus(200);
    $pendingResponse->assertSee((string) $pendingDoc->title);
    $pendingResponse->assertDontSee((string) $approvedDoc->title);

    $approvedResponse = $this->get('/admin/documents?filters[dashboard_bucket][value]=approved');
    $approvedResponse->assertStatus(200);
    $approvedResponse->assertSee((string) $approvedDoc->title);
    $approvedResponse->assertDontSee((string) $pendingDoc->title);

    $archivedResponse = $this->get('/admin/documents?filters[dashboard_bucket][value]=archived&filters[trashed][value]=1');
    $archivedResponse->assertStatus(200);
    $archivedResponse->assertSee((string) $archivedDoc->title);
    $archivedResponse->assertSee((string) $trashedDoc->title);
});

it('applies documents search query string alias', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);
    $this->withoutVite();

    $category = createCategory('General', 'general');

    $docMatch = createDocument($category, [
        'title' => 'Resolucion Academica Especial',
        'status' => 'Publicado',
    ]);

    $docOther = createDocument($category, [
        'title' => 'Circular Interna de Secretaría',
        'status' => 'Publicado',
    ]);

    $response = $this->get('/admin/documents?search=Resolucion%20Academica%20Especial');

    $response->assertStatus(200);
    $response->assertSee((string) $docMatch->title);
    $response->assertDontSee((string) $docOther->title);
});

it('applies documents search query string alias using metadata tags', function () {
    $rector = User::factory()->create(['role' => 'rector']);
    actingAs($rector);
    $this->withoutVite();

    $category = createCategory('General', 'general');

    $docByTag = createDocument($category, [
        'title' => 'Archivo General',
        'status' => 'Publicado',
        'metadata' => [
            'tags' => ['oficio', 'legal', '2026'],
        ],
    ]);

    $docOther = createDocument($category, [
        'title' => 'Circular Interna',
        'status' => 'Publicado',
        'metadata' => [
            'tags' => ['academico'],
        ],
    ]);

    $response = $this->get('/admin/documents?search=legal');

    $response->assertStatus(200);
    $response->assertSee((string) $docByTag->title);
    $response->assertDontSee((string) $docOther->title);
});

function createCategory(string $name, string $slug): DocumentCategory
{
    return DocumentCategory::create([
        'name' => $name,
        'slug' => $slug,
        'color' => '#3B82F6',
    ]);
}

function createDocument(DocumentCategory $category, array $overrides = [], ?Carbon $createdAt = null): Document
{
    $entityId = $overrides['entity_id'] ?? null;
    $title = $overrides['title'] ?? 'Documento ' . fake()->unique()->numberBetween(1000, 9999);
    $status = $overrides['status'] ?? 'Borrador';
    $year = $overrides['year'] ?? 2026;
    $fileName = $overrides['file_name'] ?? 'documento.pdf';

    /** @var Document $document */
    $document = Document::create([
        'gdrive_id' => $overrides['gdrive_id'] ?? null,
        'gdrive_url' => $overrides['gdrive_url'] ?? null,
        'file_name' => $fileName,
        'title' => $title,
        'year' => $year,
        'category_id' => $category->id,
        'entity_id' => $entityId,
        'status' => $status,
        'metadata' => $overrides['metadata'] ?? null,
    ]);

    if ($createdAt !== null) {
        $document->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }

    return $document;
}
