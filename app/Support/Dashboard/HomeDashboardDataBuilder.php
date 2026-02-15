<?php

namespace App\Support\Dashboard;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class HomeDashboardDataBuilder
{
    public const PENDING_STATUSES = [
        'Borrador',
        'Pendiente_OCR',
        'Importado_Sin_Clasificar',
    ];

    /**
     * @return array{
     *     metrics: array{pending: int, approved: int, archived: int},
     *     metricLinks: array{pending: string, approved: string, archived: string},
     *     unclassifiedAlert: array{
     *         count: int,
     *         filteredUrl: string,
     *         items: array<int, array{id: string, title: string, path: string|null, openUrl: ?string, editUrl: string}>
     *     },
     *     topCategories: array<int, array{id: string, name: string, slug: string, count: int, color: string, textColor: string, icon: string, filteredUrl: string}>,
     *     reviewQueue: array<int, array{id: string, title: string, status: string, entityName: ?string, categoryName: ?string, icon: string, createdAtHuman: ?string, openUrl: ?string, editUrl: string}>,
     *     links: array{documentsIndex: string, createDocument: string},
     *     canCreateDocument: bool
     * }
     */
    public function build(): array
    {
        return [
            'metrics' => $this->getMetrics(),
            'metricLinks' => $this->getMetricLinks(),
            'unclassifiedAlert' => $this->getUnclassifiedAlert(),
            'topCategories' => $this->getTopCategories(),
            'reviewQueue' => $this->getReviewQueue(),
            'links' => [
                'documentsIndex' => DocumentResource::getUrl('index'),
                'createDocument' => DocumentResource::getUrl('create'),
            ],
            'canCreateDocument' => $this->canCreateDocument(),
        ];
    }

    /**
     * @return array{pending: int, approved: int, archived: int}
     */
    protected function getMetrics(): array
    {
        $pending = Document::query()
            ->whereIn('status', static::PENDING_STATUSES)
            ->count();

        $approved = Document::query()
            ->where('status', 'Publicado')
            ->count();

        $archived = Document::query()
            ->where('status', 'Archivado')
            ->count();

        $trashed = Document::onlyTrashed()->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'archived' => $archived + $trashed,
        ];
    }

    /**
     * @return array{pending: string, approved: string, archived: string}
     */
    protected function getMetricLinks(): array
    {
        return [
            'pending' => $this->buildDashboardBucketUrl('pending'),
            'approved' => $this->buildDashboardBucketUrl('approved'),
            'archived' => $this->buildDashboardBucketUrl('archived', includeTrashed: true),
        ];
    }

    /**
     * @return array{
     *     count: int,
     *     filteredUrl: string,
     *     items: array<int, array{id: string, title: string, path: string|null, openUrl: ?string, editUrl: string}>
     * }
     */
    protected function getUnclassifiedAlert(): array
    {
        $query = Document::query()
            ->where('status', 'Importado_Sin_Clasificar')
            ->orderByDesc('created_at');

        $count = (clone $query)->count();
        $limit = max(1, (int) config('drive_sync.dashboard_top_items', 5));

        $items = $query
            ->limit($limit)
            ->get()
            ->map(function (Document $document): array {
                return [
                    'id' => (string) $document->id,
                    'title' => (string) $document->title,
                    'path' => $document->metadata['import_path'] ?? null,
                    'openUrl' => $this->resolveDocumentOpenUrl($document),
                    'editUrl' => DocumentResource::getUrl('edit', ['record' => $document]),
                ];
            })
            ->all();

        return [
            'count' => $count,
            'filteredUrl' => $this->buildStatusFilterUrl('Importado_Sin_Clasificar'),
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array{id: string, name: string, slug: string, count: int, color: string, textColor: string, icon: string, filteredUrl: string}>
     */
    protected function getTopCategories(): array
    {
        return DocumentCategory::query()
            ->withCount('documents')
            ->orderByDesc('documents_count')
            ->orderBy('name')
            ->limit(4)
            ->get()
            ->filter(fn (DocumentCategory $category): bool => $category->documents_count > 0)
            ->map(function (DocumentCategory $category): array {
                return [
                    'id' => (string) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) $category->slug,
                    'count' => (int) $category->documents_count,
                    'color' => DocumentCategory::normalizeColor($category->color),
                    'textColor' => DocumentCategory::contrastTextColor($category->color),
                    'icon' => $this->resolveCategoryIcon($category),
                    'filteredUrl' => $this->buildCategoryFilteredUrl((string) $category->id),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, title: string, status: string, entityName: ?string, categoryName: ?string, icon: string, createdAtHuman: ?string, openUrl: ?string, editUrl: string}>
     */
    protected function getReviewQueue(): array
    {
        return Document::query()
            ->whereIn('status', static::PENDING_STATUSES)
            ->with([
                'category:id,name,slug,color',
                'entity:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function (Document $document): array {
                return [
                    'id' => (string) $document->id,
                    'title' => (string) $document->title,
                    'status' => (string) $document->status,
                    'entityName' => $document->entity?->name,
                    'categoryName' => $document->category?->name,
                    'icon' => $this->resolveFileTypeIcon($document->file_name),
                    'createdAtHuman' => $document->created_at?->locale(app()->getLocale())->diffForHumans(),
                    'openUrl' => $this->resolveDocumentOpenUrl($document),
                    'editUrl' => DocumentResource::getUrl('edit', ['record' => $document]),
                ];
            })
            ->all();
    }

    protected function buildCategoryFilteredUrl(string $categoryId): string
    {
        $baseUrl = DocumentResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'category' => [
                    'value' => $categoryId,
                ],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    protected function buildDashboardBucketUrl(string $bucket, bool $includeTrashed = false): string
    {
        $baseUrl = DocumentResource::getUrl('index');
        $params = [
            'filters' => [
                'dashboard_bucket' => [
                    'value' => $bucket,
                ],
            ],
        ];

        if ($includeTrashed) {
            $params['filters']['trashed'] = [
                'value' => 1,
            ];
        }

        $query = http_build_query($params);

        return "{$baseUrl}?{$query}";
    }

    protected function buildStatusFilterUrl(string $status): string
    {
        $baseUrl = DocumentResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'status' => [
                    'value' => $status,
                ],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    protected function resolveDocumentOpenUrl(Document $document): ?string
    {
        return $document->resolveOpenUrlForCurrentUser();
    }

    protected function resolveFileTypeIcon(?string $fileName): string
    {
        $extension = Str::of((string) pathinfo((string) $fileName, PATHINFO_EXTENSION))
            ->lower()
            ->toString();

        return match ($extension) {
            'pdf' => 'heroicon-o-document',
            'xls', 'xlsx', 'csv', 'tsv', 'ods', 'gsheet' => 'heroicon-o-table-cells',
            'ppt', 'pptx', 'pps', 'ppsx', 'odp', 'key', 'gslides' => 'heroicon-o-presentation-chart-bar',
            'doc', 'docx', 'odt', 'txt', 'rtf', 'md', 'gdoc' => 'heroicon-o-document-text',
            default => 'heroicon-o-document',
        };
    }

    protected function resolveCategoryIcon(?DocumentCategory $category): string
    {
        if ($category === null) {
            return 'heroicon-o-folder';
        }

        $term = Str::of("{$category->slug} {$category->name}")
            ->lower()
            ->toString();

        return match (true) {
            str_contains($term, 'acta'), str_contains($term, 'examen') => 'heroicon-o-document-text',
            str_contains($term, 'certificado') => 'heroicon-o-bookmark-square',
            str_contains($term, 'reglamento') => 'heroicon-o-scale',
            str_contains($term, 'expediente') => 'heroicon-o-briefcase',
            default => 'heroicon-o-folder',
        };
    }

    protected function canCreateDocument(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasPermission('documents.create');
    }
}
