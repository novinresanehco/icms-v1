<?php

namespace App\Core\Permission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Core\User\Models\User;
use App\Core\Role\Models\Role;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category',
        'resource',
        'action',
        'is_system',
        'metadata'
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'metadata' => 'array'
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
                    ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
                    ->withTimestamps();
    }

    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function canBeDeleted(): bool
    {
        return !$this->is_system;
    }

    public function getUserCount(): int
    {
        return $this->users()->count();
    }

    public function getRoleCount(): int
    {
        return $this->roles()->count();
    }

    public function getFullName(): string
    {
        return "{$this->resource}.{$this->action}";
    }
}
