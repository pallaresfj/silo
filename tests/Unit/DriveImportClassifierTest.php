<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Support\Drive\DriveImportClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DriveImportClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_high_confidence_for_complete_valid_path(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Certificados',
            'slug' => 'certificados',
            'color' => '#3B82F6',
        ]);

        $entity = Entity::query()->create([
            'name' => 'Secretaria Academica',
            'type' => 'Interna',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/2026/certificados/secretaria-academica/certificado.pdf', [
            'name' => 'certificado.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame(2026, $result->year);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame((string) $entity->id, $result->entityId);
        $this->assertSame('Importado_Sin_Clasificar', $result->status);
        $this->assertSame('high', $result->confidence);
        $this->assertSame('certificado', $result->title);
    }

    public function test_it_uses_fallback_category_when_category_does_not_match(): void
    {
        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/2026/no-existe/archivo.pdf', [
            'name' => 'archivo.pdf',
        ]);

        $fallback = DocumentCategory::query()->where('slug', 'sin-clasificar')->firstOrFail();

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame((string) $fallback->id, $result->categoryId);
        $this->assertSame('Importado_Sin_Clasificar', $result->status);
        $this->assertSame('partial', $result->confidence);
    }

    public function test_it_uses_current_year_when_year_is_invalid(): void
    {
        Carbon::setTestNow('2030-06-01 09:00:00');

        $category = DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/x030/actas/archivo.pdf', [
            'name' => 'archivo.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame(2030, $result->year);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame('Importado_Sin_Clasificar', $result->status);

        Carbon::setTestNow();
    }

    public function test_it_keeps_entity_null_when_entity_does_not_match(): void
    {
        DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/2026/actas/no-encontrada/archivo.pdf', [
            'name' => 'archivo.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertNull($result->entityId);
        $this->assertSame('Importado_Sin_Clasificar', $result->status);
    }

    public function test_it_marks_partial_when_path_shape_is_invalid(): void
    {
        DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/archivo.pdf', [
            'name' => 'archivo.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame('Importado_Sin_Clasificar', $result->status);
        $this->assertSame('partial', $result->confidence);
    }

    public function test_it_classifies_institutional_path_and_uses_created_time_as_year(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $entity = Entity::query()->create([
            'name' => 'Consejo Academico',
            'type' => 'Interna',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/INSTITUCIONAL/ACTAS/CONSEJO_ACADEMICO/acta.pdf', [
            'name' => 'acta.pdf',
            'createdTime' => '2024-09-18T10:15:00Z',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_INSTITUTIONAL, $result->storageScope);
        $this->assertSame(2024, $result->year);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame((string) $entity->id, $result->entityId);
        $this->assertSame('high', $result->confidence);
    }

    public function test_it_uses_current_year_for_institutional_path_when_created_time_is_missing(): void
    {
        Carbon::setTestNow('2031-01-05 08:30:00');

        DocumentCategory::query()->create([
            'name' => 'Circulares',
            'slug' => 'circulares',
            'color' => '#3B82F6',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/INSTITUCIONAL/CIRCULARES/archivo.pdf', [
            'name' => 'archivo.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_INSTITUTIONAL, $result->storageScope);
        $this->assertSame(2031, $result->year);
        $this->assertSame('high', $result->confidence);

        Carbon::setTestNow();
    }

    public function test_it_matches_uppercase_underscore_paths_for_yearly_documents(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Certificados',
            'slug' => 'certificados',
            'color' => '#3B82F6',
        ]);

        $entity = Entity::query()->create([
            'name' => 'Secretaria Academica',
            'type' => 'Interna',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/2026/CERTIFICADOS/SECRETARIA_ACADEMICA/certificado.pdf', [
            'name' => 'certificado.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame((string) $entity->id, $result->entityId);
        $this->assertSame('high', $result->confidence);
    }

    public function test_it_matches_legacy_kebab_case_paths_during_transition(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Actas de Examen',
            'slug' => 'actas-examen',
            'color' => '#3B82F6',
        ]);

        $entity = Entity::query()->create([
            'name' => 'Consejo Academico',
            'type' => 'Interna',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/INSTITUCIONAL/actas-examen/consejo-academico/acta.pdf', [
            'name' => 'acta.pdf',
            'createdTime' => '2024-09-18T10:15:00Z',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_INSTITUTIONAL, $result->storageScope);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame((string) $entity->id, $result->entityId);
        $this->assertSame('high', $result->confidence);
    }

    public function test_it_accepts_historical_yearly_paths_from_1900_onwards(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $classifier = app(DriveImportClassifier::class);

        $result = $classifier->classify('/1905/ACTAS/libro.pdf', [
            'name' => 'libro.pdf',
        ]);

        $this->assertSame(Document::STORAGE_SCOPE_YEARLY, $result->storageScope);
        $this->assertSame(1905, $result->year);
        $this->assertSame((string) $category->id, $result->categoryId);
        $this->assertSame('high', $result->confidence);
    }
}
