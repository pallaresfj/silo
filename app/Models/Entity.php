<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'type',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Entity $entity) {
            if ($entity->documents()->exists()) {
                throw new \RuntimeException('No se puede eliminar una entidad con documentos asociados.');
            }
        });
    }

    /**
     * Documents associated with this entity.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'entity_id');
    }
}
