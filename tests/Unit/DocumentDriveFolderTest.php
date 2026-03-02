<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentDriveFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_yearly_drive_folder_path(): void
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

        $document = Document::query()->create([
            'file_name' => 'acta.pdf',
            'title' => 'Acta',
            'year' => 2026,
            'storage_scope' => Document::STORAGE_SCOPE_YEARLY,
            'category_id' => $category->id,
            'entity_id' => $entity->id,
            'status' => 'Borrador',
        ]);

        $this->assertSame('SGI-Doc/2026/ACTAS/CONSEJO_ACADEMICO', $document->drive_folder);
    }

    public function test_it_builds_institutional_drive_folder_path(): void
    {
        $category = DocumentCategory::query()->create([
            'name' => 'Actas',
            'slug' => 'actas',
            'color' => '#3B82F6',
        ]);

        $document = Document::query()->create([
            'file_name' => 'acta.pdf',
            'title' => 'Acta',
            'year' => 2026,
            'storage_scope' => Document::STORAGE_SCOPE_INSTITUTIONAL,
            'category_id' => $category->id,
            'entity_id' => null,
            'status' => 'Borrador',
        ]);

        $this->assertSame('SGI-Doc/INSTITUCIONAL/ACTAS', $document->drive_folder);
    }
}
