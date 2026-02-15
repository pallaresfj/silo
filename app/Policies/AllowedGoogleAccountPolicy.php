<?php

namespace App\Policies;

use App\Models\AllowedGoogleAccount;
use App\Models\User;

class AllowedGoogleAccountPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('whitelist.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AllowedGoogleAccount $allowedGoogleAccount): bool
    {
        return $user->hasPermission('whitelist.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('whitelist.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AllowedGoogleAccount $allowedGoogleAccount): bool
    {
        return $user->hasPermission('whitelist.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AllowedGoogleAccount $allowedGoogleAccount): bool
    {
        return $user->hasPermission('whitelist.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AllowedGoogleAccount $allowedGoogleAccount): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AllowedGoogleAccount $allowedGoogleAccount): bool
    {
        return false;
    }
}
