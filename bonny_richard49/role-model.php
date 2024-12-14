<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Interfaces\RoleInterface;

class Role extends Model implements RoleInterface
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'level',
        'is_system'
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'level' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($role) {
            if (!$role->slug) {
                $role->slug = str_slug($role->name);
            }
        });

        static::deleting(function ($role) {
            if ($role->is_system) {
                throw new \Exception('System roles cannot be deleted');
            }
        });
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission($permission): bool
    {
        if (is_string($permission)) {
            return $this->permissions->contains('slug', $permission);
        }

        return $this->permissions->contains($permission);
    }

    public function grantPermission($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        if (!$this->hasPermission($permission)) {
            $this->permissions()->attach($permission);
        }
    }

    public function revokePermission($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        $this->permissions()->detach($permission);
    }

    public function syncPermissions(array $permissions): void
    {
        $this->permissions()->sync($permissions);
    }
}
