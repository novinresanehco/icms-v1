<?php

namespace App\Core\Role\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasMany};
use App\Core\User\Models\User;
use App\Core\Permission\Models\Permission;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'level',
        'is_system',
        'metadata'
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'level' => 'integer',
        'metadata' => 'array'
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
                    ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
                    ->withTimestamps();
    }

    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('name', $permission);
    }

    public function hasUser(User $user): bool
    {
        return $this->users->contains($user);
    }

    public function canBeDeleted(): bool
    {
        return !$this->is_system;
    }

    public function canBeModified(): bool
    {
        return !$this->is_system;
    }

    public function getPermissionsByCategory(): array
    {
        return $this->permissions
                    ->groupBy('category')
                    ->map(fn ($permissions) => $permissions->pluck('name'))
                    ->toArray();
    }

    public function getUserCount(): int
    {
        return $this->users()->count();
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'user_count' => $this->getUserCount(),
            'permissions' => $this->getPermissionsByCategory()
        ]);
    }
}
