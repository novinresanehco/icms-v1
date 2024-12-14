<?php

namespace App\Core\Role\Services;

use App\Core\Role\Models\Role;
use App\Core\Permission\Models\Permission;
use App\Exceptions\RoleValidationException;
use Illuminate\Support\Facades\Validator;

class RoleValidator
{
    public function validateCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:1000',
            'level' => 'required|integer|min:1',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            throw new RoleValidationException(
                'Role validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(Role $role, array $data): void
    {
        if ($role->isSystem() && isset($data['name'])) {
            throw new RoleValidationException('Cannot modify system role name');
        }

        $validator = Validator::make($data, [
            'name' => "sometimes|string|max:255|unique:roles,name,{$role->id}",
            'description' => 'nullable|string|max:1000',
            'level' => 'sometimes|integer|min:1',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            throw new RoleValidationException(
                'Role validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateDelete(Role $role): void
    {
        if ($role->isSystem()) {
            throw new RoleValidationException('Cannot delete system role');
        }

        if ($role->users()->exists()) {
            throw new RoleValidationException('Cannot delete role with assigned users');
        }
    }

    public function validatePermissions(array $permissions): void
    {
        $existing = Permission::whereIn('name', $permissions)->pluck('name');
        $invalid = array_diff($permissions, $existing->all());

        if (!empty($invalid)) {
            throw new RoleValidationException(
                'Invalid permissions: ' . implode(', ', $invalid)
            );
        }
    }

    public function validatePermission(string $permission): void
    {
        if (!Permission::where('name', $permission)->exists()) {
            throw new RoleValidationException("Permission {$permission} does not exist");
        }
    }

    public function validateUsers(array $userIds): void
    {
        $validator = Validator::make(['users' => $userIds], [
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        if ($validator->fails()) {
            throw new RoleValidationException(
                'Invalid user IDs',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateRoleHierarchy(Role $role, int $newLevel): void
    {
        if ($role->isSystem() && $newLevel !== $role->level) {
            throw new RoleValidationException('Cannot modify system role level');
        }

        if ($newLevel < 1) {
            throw new RoleValidationException('Role level must be greater than 0');
        }
    }
}
