<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'guard_name',
        'module',
        'is_system'
    ];

    protected $casts = [
        'is_system' => 'boolean'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
                    ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')
                    ->withTimestamps();
    }

    public function scopeByGuard($query, string $guardName)
    {
        return $query->where('guard_name', $guardName);
    }

    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }
}
