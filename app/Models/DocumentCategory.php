<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentCategory extends Model
{
    use HasFactory, HasUuids;

    public const DEFAULT_COLOR = 'primary';

    protected $fillable = [
        'name',
        'slug',
        'color',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Auto-generate slug from name when creating.
     */
    protected static function booted(): void
    {
        static::creating(function (DocumentCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::saving(function (DocumentCategory $category): void {
            $category->color = static::normalizeColor($category->color);
        });

        static::deleting(function (DocumentCategory $category) {
            if ($category->documents()->exists()) {
                throw new \RuntimeException('No se puede eliminar una categoría con documentos asociados.');
            }
        });
    }

    /**
     * Documents in this category.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }

    public static function colorOptions(): array
    {
        return [
            'primary' => 'Azul',
            'gray' => 'Gris',
            'info' => 'Cian',
            'success' => 'Verde',
            'warning' => 'Amarillo',
            'danger' => 'Rojo',
        ];
    }

    public static function normalizeColor(?string $color): string
    {
        $color = (string) $color;

        return array_key_exists($color, static::colorOptions())
            ? $color
            : static::DEFAULT_COLOR;
    }
}
