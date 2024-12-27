<?php

namespace App\Policies;

class ContentPolicy
{
    private SecurityManager $security;
    private AuditLogger $audit;

    public function view(User $user, Content $content): bool
    {
        if ($content->status === 'published') {
            return true;
        }

        if ($user->id === $content->author_id) {
            return true;
        }

        return $user->hasPermission('content.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('content.create');
    }

    public function update(User $user, Content $content): bool
    {
        if ($user->id === $content->author_id) {
            return true;
        }

        return $user->hasPermission('content.edit');
    }

    public function delete(User $user, Content $content): bool
    {
        if ($user->id === $content->author_id) {
            return $user->hasPermission('content.delete_own');
        }

        return $user->hasPermission('content.delete');
    }

    public function publish(User $user, Content $content): bool
    {
        return $user->hasPermission('content.publish');
    }
}

class MediaPolicy
{
    private SecurityManager $security;
    private AuditLogger $audit;

    public function view(User $user, Media $media): bool
    {
        if ($user->id === $media->uploader_id) {
            return true;
        }

        return $user->hasPermission('media.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('media.upload');
    }

    public function update(User $user, Media $media): bool
    {
        if ($user->id === $media->uploader_id) {
            return true;
        }

        return $user->hasPermission('media.edit');
    }

    public function delete(User $user, Media $media): bool
    {
        if ($user->id === $media->uploader_id) {
            return $user->hasPermission('media.delete_own');
        }

        return $user->hasPermission('media.delete');
    }
}

class UserPolicy
{
    private SecurityManager $security;
    private AuditLogger $audit;

    public function view(User $viewer, User $user): bool
    {
        if ($viewer->id === $user->id) {
            return true;
        }

        return $viewer->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(User $viewer, User $user): bool
    {
        if ($viewer->id === $user->id) {
            return true;
        }

        return $viewer->hasPermission('users.edit');
    }

    public function delete(User $viewer, User $user): bool
    {
        if ($viewer->id === $user->id) {
            return false;
        }

        return $viewer->hasPermission('users.delete');
    }

    public function impersonate(User $viewer, User $user): bool
    {
        if ($viewer->id === $user->id) {
            return false;
        }

        return $viewer->hasPermission('users.impersonate');
    }
}

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles->contains(function ($role) use ($permission) {
            return $role->hasPermission($permission);
        });
    }

    public function assignRole(string $role): void
    {
        $role = Role::whereName($role)->firstOrFail();
        $this->roles()->sync($role, false);
    }

    public function removeRole(string $role): void
    {
        $role = Role::whereName($role)->firstOrFail();
        $this->roles()->detach($role);
    }
}
