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

    public const DEFAULT_COLOR = '#3B82F6';

    public const LEGACY_COLOR_MAP = [
        'primary' => '#3B82F6',
        'gray' => '#6B7280',
        'info' => '#06B6D4',
        'success' => '#22C55E',
        'warning' => '#F59E0B',
        'danger' => '#EF4444',
    ];

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

    public static function normalizeColor(?string $color): string
    {
        $color = trim((string) $color);

        if ($color === '') {
            return static::DEFAULT_COLOR;
        }

        $legacyColor = static::LEGACY_COLOR_MAP[strtolower($color)] ?? null;
        if ($legacyColor !== null) {
            return $legacyColor;
        }

        if (preg_match('/^#(?:[A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color) !== 1) {
            return static::DEFAULT_COLOR;
        }

        if (strlen($color) === 4) {
            $color = sprintf('#%s%s%s%s%s%s', $color[1], $color[1], $color[2], $color[2], $color[3], $color[3]);
        }

        return strtoupper($color);
    }

    public static function contrastTextColor(?string $backgroundColor): string
    {
        $hex = ltrim(static::normalizeColor($backgroundColor), '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance >= 150 ? '#111827' : '#FFFFFF';
    }

    public function getColorAttribute(?string $value): string
    {
        return static::normalizeColor($value);
    }
}
