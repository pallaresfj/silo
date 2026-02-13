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

    /**
     * Documents associated with this entity.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'entity_id');
    }
}
