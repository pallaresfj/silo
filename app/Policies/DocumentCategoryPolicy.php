<?php

namespace App\Policies;

use App\Models\DocumentCategory;
use App\Models\User;

class DocumentCategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('categories.view') || $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DocumentCategory $documentCategory): bool
    {
        return $user->hasPermission('categories.view') || $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DocumentCategory $documentCategory): bool
    {
        return $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DocumentCategory $documentCategory): bool
    {
        return $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DocumentCategory $documentCategory): bool
    {
        return $user->hasPermission('categories.manage');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DocumentCategory $documentCategory): bool
    {
        return $user->hasPermission('categories.manage');
    }
}
