<?php

namespace Tests\Unit;

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

        $this->assertSame('Importado_Sin_Clasificar', $result->status);
        $this->assertSame('partial', $result->confidence);
    }
}
