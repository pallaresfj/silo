<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class DocumentVisibilityScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * - Rector role: sees ALL documents (no filter applied).
     * - Other roles: only see documents with status 'Publicado'.
     * - Guest (unauthenticated): only 'Publicado'.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // Rector sees everything
        if ($user && $user->role === 'rector') {
            return;
        }

        // Everyone else only sees published documents
        $builder->where('status', 'Publicado');
    }
}
