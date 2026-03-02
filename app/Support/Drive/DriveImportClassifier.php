<?php

namespace App\Support\Drive;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Entity;
use App\Support\GoogleDriveHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DriveImportClassifier
{
    /**
     * @var array<string, string>|null
     */
    protected ?array $categoriesByFolderSegment = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $entitiesByFolderSegment = null;

    public function classify(string $relativePath, array $file): DriveImportClassification
    {
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));
        $fileName = trim((string) ($file['name'] ?? ''));

        if ($fileName === '') {
            $fileName = (string) ($segments[count($segments) - 1] ?? 'archivo-sin-nombre');
        }

        $rootSegment = (string) ($segments[0] ?? '');
        $storageScope = $this->resolveStorageScope($rootSegment);
        $pathShapeIsValid = $this->pathShapeIsValid($segments, $storageScope);
        $categorySegment = $segments[1] ?? null;
        $entitySegment = count($segments) === 4 ? ($segments[2] ?? null) : null;

        $yearIsValid = $storageScope === Document::STORAGE_SCOPE_YEARLY
            && $this->isValidYearSegment($rootSegment);
        $yearSource = 'fallback_now';
        $year = (int) now()->year;

        if ($yearIsValid) {
            $year = (int) $rootSegment;
            $yearSource = 'path';
        } elseif ($storageScope === Document::STORAGE_SCOPE_INSTITUTIONAL) {
            $createdTimeYear = $this->resolveYearFromCreatedTime($file);

            if ($createdTimeYear !== null) {
                $year = $createdTimeYear;
                $yearSource = 'drive_created_time';
            }
        }

        $categoryId = null;
        $categoryMatched = false;

        if (is_string($categorySegment) && $categorySegment !== '') {
            $categoryId = $this->resolveCategoryIdByFolderSegment($categorySegment);
            $categoryMatched = $categoryId !== null;
        }

        if ($categoryId === null) {
            $categoryId = $this->resolveFallbackCategoryId();
        }

        $entityId = null;
        $entityMatched = true;

        if (is_string($entitySegment) && $entitySegment !== '') {
            $entityId = $this->resolveEntityIdByFolderSegment($entitySegment);
            $entityMatched = $entityId !== null;
        }

        $isHighConfidence = $pathShapeIsValid
            && ($storageScope === Document::STORAGE_SCOPE_INSTITUTIONAL || $yearIsValid)
            && $categoryMatched
            && $entityMatched;

        $confidence = $isHighConfidence ? 'high' : 'partial';

        return new DriveImportClassification(
            storageScope: $storageScope,
            year: $year,
            categoryId: $categoryId,
            entityId: $entityId,
            status: 'Importado_Sin_Clasificar',
            confidence: $confidence,
            title: $this->buildTitle($fileName),
            fileName: $fileName,
            metadata: [
                'classifier' => [
                    'storage_scope' => $storageScope,
                    'path_root_segment' => $rootSegment,
                    'year_valid' => $yearIsValid,
                    'year_source' => $yearSource,
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

    protected function resolveCategoryIdByFolderSegment(string $segment): ?string
    {
        foreach ($this->folderSegmentCandidates($segment, 'SIN_CLASIFICAR') as $candidate) {
            if (isset($this->categoryMap()[$candidate])) {
                return $this->categoryMap()[$candidate];
            }
        }

        return null;
    }

    protected function resolveEntityIdByFolderSegment(string $segment): ?string
    {
        foreach ($this->folderSegmentCandidates($segment, 'SIN_ENTIDAD') as $candidate) {
            if (isset($this->entityMap()[$candidate])) {
                return $this->entityMap()[$candidate];
            }
        }

        return null;
    }

    protected function resolveStorageScope(string $rootSegment): string
    {
        return $rootSegment === GoogleDriveHelper::getInstitutionalFolderName()
            ? Document::STORAGE_SCOPE_INSTITUTIONAL
            : Document::STORAGE_SCOPE_YEARLY;
    }

    protected function pathShapeIsValid(array $segments, string $storageScope): bool
    {
        if (count($segments) !== 3 && count($segments) !== 4) {
            return false;
        }

        if ($storageScope === Document::STORAGE_SCOPE_INSTITUTIONAL) {
            return ($segments[0] ?? null) === GoogleDriveHelper::getInstitutionalFolderName();
        }

        return $this->isValidYearSegment((string) ($segments[0] ?? ''));
    }

    protected function isValidYearSegment(string $yearSegment): bool
    {
        return preg_match('/^\d{4}$/', $yearSegment) === 1
            && (int) $yearSegment >= 1900
            && (int) $yearSegment <= 2099;
    }

    protected function resolveYearFromCreatedTime(array $file): ?int
    {
        $createdTime = trim((string) ($file['createdTime'] ?? ''));

        if ($createdTime === '') {
            return null;
        }

        try {
            $year = (int) Carbon::parse($createdTime)->year;
        } catch (\Throwable) {
            return null;
        }

        return $year >= 1900 && $year <= 2099 ? $year : null;
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

        if ($this->categoriesByFolderSegment === null) {
            $this->categoriesByFolderSegment = [];
        }

        foreach ($this->folderSegmentCandidates('sin-clasificar', 'SIN_CLASIFICAR') as $candidate) {
            $this->categoriesByFolderSegment[$candidate] = (string) $category->id;
        }

        return (string) $category->id;
    }

    /**
     * @return array<string, string>
     */
    protected function categoryMap(): array
    {
        if ($this->categoriesByFolderSegment !== null) {
            return $this->categoriesByFolderSegment;
        }

        $this->categoriesByFolderSegment = [];

        DocumentCategory::query()
            ->get(['id', 'slug', 'name'])
            ->each(function (DocumentCategory $category): void {
                $id = (string) $category->id;

                foreach ($this->categoryAliases($category) as $alias) {
                    $this->categoriesByFolderSegment[$alias] ??= $id;
                }
            });

        return $this->categoriesByFolderSegment;
    }

    /**
     * @return array<string, string>
     */
    protected function entityMap(): array
    {
        if ($this->entitiesByFolderSegment !== null) {
            return $this->entitiesByFolderSegment;
        }

        $this->entitiesByFolderSegment = [];

        Entity::query()
            ->get(['id', 'name'])
            ->each(function (Entity $entity): void {
                $id = (string) $entity->id;

                foreach ($this->entityAliases($entity) as $alias) {
                    $this->entitiesByFolderSegment[$alias] ??= $id;
                }
            });

        return $this->entitiesByFolderSegment;
    }

    /**
     * @return list<string>
     */
    protected function folderSegmentCandidates(string $segment, string $fallback): array
    {
        $raw = trim($segment);
        $candidates = [];

        if ($raw !== '') {
            $candidates[] = $raw;
        }

        $kebab = Str::slug($segment);
        if ($kebab !== '') {
            $candidates[] = $kebab;
        }

        $candidates[] = GoogleDriveHelper::normalizeDriveFolderName($segment, $fallback);

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return list<string>
     */
    protected function categoryAliases(DocumentCategory $category): array
    {
        $aliases = [];
        $slug = trim((string) $category->slug);
        $name = trim((string) $category->name);

        if ($slug !== '') {
            $aliases[] = $slug;

            $normalizedSlug = Str::slug($slug);
            if ($normalizedSlug !== '') {
                $aliases[] = $normalizedSlug;
            }

            $aliases[] = GoogleDriveHelper::normalizeDriveFolderName($slug, 'SIN_CLASIFICAR');
        }

        if ($name !== '') {
            $aliases[] = GoogleDriveHelper::normalizeDriveFolderName($name, 'SIN_CLASIFICAR');
            $normalizedName = Str::slug($name);

            if ($normalizedName !== '') {
                $aliases[] = $normalizedName;
            }
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    /**
     * @return list<string>
     */
    protected function entityAliases(Entity $entity): array
    {
        $name = trim((string) $entity->name);

        if ($name === '') {
            return [GoogleDriveHelper::normalizeDriveFolderName(null, 'SIN_ENTIDAD')];
        }

        $aliases = [
            GoogleDriveHelper::normalizeDriveFolderName($name, 'SIN_ENTIDAD'),
        ];

        $normalizedName = Str::slug($name);

        if ($normalizedName !== '') {
            $aliases[] = $normalizedName;
        }

        return array_values(array_unique(array_filter($aliases)));
    }
}
