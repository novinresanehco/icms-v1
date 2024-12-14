<?php

// database/migrations/2024_01_01_000002_create_access_control_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                  ->constrained('permission_groups')
                  ->onDelete('cascade');
            $table->foreignId('permission_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['group_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_group_items');
        Schema::dropIfExists('permission_groups');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};

namespace App\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasMany};

class Role extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'display_name',
        'description'
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
                    ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
                    ->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions()
                    ->where('name', $permission)
                    ->exists();
    }
}

class Permission extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'category'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
                    ->withTimestamps();
    }

    public function permissionGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionGroup::class,
            'permission_group_items',
            'permission_id',
            'group_id'
        )->withTimestamps();
    }
}

class PermissionGroup extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description'
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'permission_group_items',
            'group_id',
            'permission_id'
        )->withTimestamps();
    }
}

// User model traits
namespace App\Traits;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
                    ->withTimestamps();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()
                    ->where('name', $role)
                    ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
                    ->whereHas('permissions', function($query) use ($permission) {
                        $query->where('name', $permission);
                    })->exists();
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return $this->roles()
                    ->whereHas('permissions', function($query) use ($permissions) {
                        $query->whereIn('name', $permissions);
                    })->exists();
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }
}
