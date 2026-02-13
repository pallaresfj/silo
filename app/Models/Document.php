<?php

namespace App\Models;

use App\Models\Scopes\DocumentVisibilityScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'gdrive_id',
        'gdrive_url',
        'file_name',
        'title',
        'year',
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
     * Pattern: SGI-Doc/{Year}/{CategorySlug}
     */
    public function getDriveFolderAttribute(): string
    {
        $category = $this->category?->slug ?? 'sin-clasificar';

        return "SGI-Doc/{$this->year}/{$category}";
    }
}
