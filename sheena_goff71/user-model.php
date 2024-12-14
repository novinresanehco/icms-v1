<?php

namespace App\Core\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsToMany};
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
        'metadata'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'metadata' => 'array'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
                    ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
                    ->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(UserActivity::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->permissions->contains('name', $permission)) {
            return true;
        }

        return $this->roles->flatMap->permissions
                          ->contains('name', $permission);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function recordActivity(string $type, array $metadata = []): void
    {
        $this->activities()->create([
            'type' => $type,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function recordLogin(): void
    {
        $this->update([
            'last_login_at' => now()
        ]);

        $this->recordActivity('login');
    }

    public function getAccessibleResources(): array
    {
        $permissions = $this->getAllPermissions();
        
        return $permissions->pluck('resource')
                         ->unique()
                         ->values()
                         ->all();
    }

    protected function getAllPermissions(): Collection
    {
        return $this->permissions->merge(
            $this->roles->flatMap->permissions
        )->unique('id');
    }
}
