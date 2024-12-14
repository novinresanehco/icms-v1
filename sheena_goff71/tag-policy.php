<?php

namespace App\Core\Tag\Policies;

use App\Core\User\Models\User;
use App\Core\Tag\Models\Tag;
use Illuminate\Auth\Access\HandlesAuthorization;

class TagPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tag $tag): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create tags');
    }

    public function update(User $user, Tag $tag): bool
    {
        if ($tag->isProtected()) {
            return $user->hasPermissionTo('manage protected tags');
        }

        return $user->hasPermissionTo('update tags');
    }

    public function delete(User $user, Tag $tag): bool
    {
        if ($tag->isProtected()) {
            return $user->hasPermissionTo('manage protected tags');
        }

        if ($tag->hasActiveRelationships()) {
            return $user->hasPermissionTo('delete tags with relationships');
        }

        return $user->hasPermissionTo('delete tags');
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('restore tags');
    }

    public function forceDelete(User $user, Tag $tag): bool
    {
        if ($tag->isProtected()) {
            return false;
        }

        return $user->hasPermissionTo('force delete tags');
    }

    public function reorder(User $user): bool
    {
        return $user->hasPermissionTo('reorder tags');
    }

    public function attachToContent(User $user): bool
    {
        return $user->hasPermissionTo('attach tags');
    }

    public function detachFromContent(User $user): bool
    {
        return $user->hasPermissionTo('detach tags');
    }
}
