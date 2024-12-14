<?php

namespace App\Core\Policies;

use App\Core\Models\{User, Category};
use Illuminate\Auth\Access\HandlesAuthorization;

class CategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-categories');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('view-categories');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-categories');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('edit-categories');
    }

    public function delete(User $user, Category $category): bool
    {
        if ($category->hasChildren()) {
            return false;
        }
        
        return $user->hasPermissionTo('delete-categories');
    }

    public function restore(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('restore-categories');
    }

    public function forceDelete(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('force-delete-categories');
    }

    public function reorder(User $user): bool
    {
        return $user->hasPermissionTo('reorder-categories');
    }
}
