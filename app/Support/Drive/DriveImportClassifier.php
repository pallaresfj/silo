<?php

namespace App\Support\Drive;

use App\Models\DocumentCategory;
use App\Models\Entity;
use Illuminate\Support\Str;

class DriveImportClassifier
{
    /**
     * @var array<string, string>|null
     */
    protected ?array $categoriesBySlug = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $entitiesBySlug = null;

    public function classify(string $relativePath, array $file): DriveImportClassification
    {
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));
        $fileName = trim((string) ($file['name'] ?? ''));

        if ($fileName === '') {
            $fileName = (string) ($segments[count($segments) - 1] ?? 'archivo-sin-nombre');
        }

        $yearSegment = $segments[0] ?? null;
        $categorySegment = $segments[1] ?? null;
        $entitySegment = count($segments) === 4 ? ($segments[2] ?? null) : null;

        $yearIsValid = is_string($yearSegment)
            && preg_match('/^\d{4}$/', $yearSegment) === 1
            && (int) $yearSegment >= 2010
            && (int) $yearSegment <= 2099;

        $year = $yearIsValid ? (int) $yearSegment : (int) now()->year;

        $categoryId = null;
        $categoryMatched = false;

        if (is_string($categorySegment) && $categorySegment !== '') {
            $categoryId = $this->resolveCategoryIdBySlug($categorySegment);
            $categoryMatched = $categoryId !== null;
        }

        if ($categoryId === null) {
            $categoryId = $this->resolveFallbackCategoryId();
        }

        $entityId = null;
        $entityMatched = true;

        if (is_string($entitySegment) && $entitySegment !== '') {
            $entityId = $this->resolveEntityIdBySlug($entitySegment);
            $entityMatched = $entityId !== null;
        }

        $pathShapeIsValid = count($segments) === 3 || count($segments) === 4;

        $isHighConfidence = $pathShapeIsValid
            && $yearIsValid
            && $categoryMatched
            && $entityMatched;

        $confidence = $isHighConfidence ? 'high' : 'partial';

        return new DriveImportClassification(
            year: $year,
            categoryId: $categoryId,
            entityId: $entityId,
            status: $isHighConfidence ? 'Borrador' : 'Importado_Sin_Clasificar',
            confidence: $confidence,
            title: $this->buildTitle($fileName),
            fileName: $fileName,
            metadata: [
                'classifier' => [
                    'year_valid' => $yearIsValid,
                    'category_matched' => $categoryMatched,
                    'entity_matched' => $entityMatched,
                    'path_shape_valid' => $pathShapeIsValid,
                ],
            ],
        );
    }

    protected function buildTitle(string $fileName): string
    {
        $title = pathinfo($fileName, PATHINFO_FILENAME);
        $title = trim((string) $title);

        return $title !== '' ? $title : $fileName;
    }

    protected function resolveCategoryIdBySlug(string $slug): ?string
    {
        $normalized = Str::slug($slug);

        return $this->categoryMap()[$normalized] ?? null;
    }

    protected function resolveEntityIdBySlug(string $slug): ?string
    {
        $normalized = Str::slug($slug);

        return $this->entityMap()[$normalized] ?? null;
    }

    protected function resolveFallbackCategoryId(): string
    {
        $category = DocumentCategory::query()->firstOrCreate(
            ['slug' => 'sin-clasificar'],
            [
                'name' => 'Sin clasificar',
                'color' => DocumentCategory::DEFAULT_COLOR,
                'is_system' => true,
            ]
        );

        if ($this->categoriesBySlug === null) {
            $this->categoriesBySlug = [];
        }

        $this->categoriesBySlug['sin-clasificar'] = (string) $category->id;

        return (string) $category->id;
    }

    /**
     * @return array<string, string>
     */
    protected function categoryMap(): array
    {
        if ($this->categoriesBySlug !== null) {
            return $this->categoriesBySlug;
        }

        $this->categoriesBySlug = DocumentCategory::query()
            ->get(['id', 'slug'])
            ->mapWithKeys(static function (DocumentCategory $category): array {
                return [Str::slug((string) $category->slug) => (string) $category->id];
            })
            ->all();

        return $this->categoriesBySlug;
    }

    /**
     * @return array<string, string>
     */
    protected function entityMap(): array
    {
        if ($this->entitiesBySlug !== null) {
            return $this->entitiesBySlug;
        }

        $this->entitiesBySlug = Entity::query()
            ->get(['id', 'name'])
            ->mapWithKeys(static function (Entity $entity): array {
                return [Str::slug((string) $entity->name) => (string) $entity->id];
            })
            ->all();

        return $this->entitiesBySlug;
    }
}
