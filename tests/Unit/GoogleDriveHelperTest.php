<?php

namespace Tests\Unit;

use App\Support\GoogleDriveHelper;
use Tests\TestCase;

class GoogleDriveHelperTest extends TestCase
{
    public function test_it_normalizes_category_folder_names_to_upper_snake_case(): void
    {
        $this->assertSame('ACTAS_DE_EXAMEN', GoogleDriveHelper::normalizeCategorySlug('Actas de Examen'));
        $this->assertSame('SIN_CLASIFICAR', GoogleDriveHelper::normalizeCategorySlug(null));
    }

    public function test_it_normalizes_entity_folder_names_to_upper_snake_case(): void
    {
        $this->assertSame('SECRETARIA_ACADEMICA', GoogleDriveHelper::normalizeEntityFolderName('Secretaría Académica'));
        $this->assertSame('SIN_ENTIDAD', GoogleDriveHelper::normalizeEntityFolderName(''));
    }
}
