<?php

namespace App\Core\Policies;

use App\Core\Models\Media;
use App\Core\Models\User;

class MediaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('media.view');
    }

    public function view(User $user, Media $media): bool
    {
        return $user->hasPermission('media.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('media.create');
    }

    public function update(User $user, Media $media): bool
    {
        return $user->hasPermission('media.update');
    }

    public function delete(User $user, Media $media): bool
    {
        return $user->hasPermission('media.delete');
    }
}
