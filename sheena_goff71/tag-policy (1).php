<?php

namespace App\Core\Tag\Policies;

use App\Core\Tag\Models\Tag;
use App\Core\User\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TagPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if user can view tags.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view tags');
    }

    /**
     * Determine if user can view the tag.
     *
     * @param User $user
     * @param Tag $tag
     * @return bool
     */
    public function view(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('view tags');
    }

    /**
     * Determine if user can create tags.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create tags');
    }

    /**
     * Determine if user can update the tag.
     *
     * @param User $user
     * @param Tag $tag
     * @return bool
     */
    public function update(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('edit tags');
    }

    /**
     * Determine if user can delete the tag.
     *
     * @param User $user
     * @param Tag $tag
     * @return bool
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasPermissionTo('delete tags');
    }

    /**
     * Determine if user can merge tags.
     *
     * @param User $user
     * @return bool
     */
    public function merge(User $user): bool
    {
        return $user->hasPermissionTo('merge tags');
    }
}
