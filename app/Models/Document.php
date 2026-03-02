<?php

namespace App\Models;

use App\Models\Scopes\DocumentVisibilityScope;
use App\Support\GoogleDriveHelper;
use App\Support\GoogleDriveUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STORAGE_SCOPE_YEARLY = 'yearly';

    public const STORAGE_SCOPE_INSTITUTIONAL = 'institutional';

    protected $fillable = [
        'gdrive_id',
        'gdrive_url',
        'file_name',
        'title',
        'year',
        'storage_scope',
        'category_id',
        'entity_id',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'year' => 'integer',
        ];
    }

    /**
     * Apply the DocumentVisibility global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new DocumentVisibilityScope);
    }

    /**
     * The document's category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    /**
     * The entity (sender/recipient) associated with this document.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Build the Drive folder path for this document.
     * Pattern: SGI-Doc/{Year}/{CategorySlug}[/{EntitySlug}]
     */
    public function getDriveFolderAttribute(): string
    {
        $category = GoogleDriveHelper::normalizeCategorySlug($this->category?->slug);
        $storageScope = $this->storage_scope ?: self::STORAGE_SCOPE_YEARLY;

        if ($storageScope === self::STORAGE_SCOPE_INSTITUTIONAL) {
            $root = 'SGI-Doc/' . GoogleDriveHelper::getInstitutionalFolderName();

            if (blank($this->entity?->name)) {
                return "{$root}/{$category}";
            }

            $entity = GoogleDriveHelper::normalizeEntityFolderName($this->entity->name);

            return "{$root}/{$category}/{$entity}";
        }

        if (blank($this->entity?->name)) {
            return "SGI-Doc/{$this->year}/{$category}";
        }

        $entity = GoogleDriveHelper::normalizeEntityFolderName($this->entity->name);

        return "SGI-Doc/{$this->year}/{$category}/{$entity}";
    }

    public function resolveOpenUrlForCurrentUser(): ?string
    {
        $email = Auth::user()?->email;

        return GoogleDriveUrl::resolve(
            storedUrl: $this->gdrive_url,
            fileId: $this->gdrive_id,
            email: $email,
        );
    }
}
