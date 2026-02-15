<?php

namespace Database\Seeders;

use App\Models\DocumentCategory;
use Illuminate\Database\Seeder;

class SystemDocumentCategorySeeder extends Seeder
{
    public function run(): void
    {
        DocumentCategory::query()->updateOrCreate(
            ['slug' => 'sin-clasificar'],
            [
                'name' => 'Sin clasificar',
                'color' => DocumentCategory::DEFAULT_COLOR,
                'is_system' => true,
            ]
        );
    }
}
