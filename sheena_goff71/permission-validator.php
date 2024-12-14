<?php

namespace App\Core\Permission\Services;

use App\Core\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use App\Exceptions\PermissionValidationException;
use Illuminate\Support\Facades\Validator;

class PermissionValidator
{
    public function validateCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:permissions,name',
            'description' => 'nullable|string|max:1000',
            'category' => 'required|string|max:255',
            'resource' => 'required|string|max:255',
            'action' => 'required|string|max:255',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            throw new PermissionValidationException(
                'Permission validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(Permission $permission, array $data): void
    {
        if ($permission->isSystem() && isset($data['name'])) {
            throw new PermissionValidationException('Cannot modify system permission name');
        }

        $validator = Validator::make($data, [
            'name' => "sometimes|string|max:255|unique:permissions,name,{$permission->id}",
            'description' => 'nullable|string|max:1000',
            'category' => 'sometimes|string|max:255',
            'resource' => 'sometimes|string|max:255',
            'action' => 'sometimes|string|max:255',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            throw new PermissionValidationException(
                'Permission validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateDelete(Permission $permission): void
    {
        if ($permission->isSystem()) {
            throw new PermissionValidationException('Cannot delete system permission');
        }

        if ($permission->roles()->exists()) {
            throw new PermissionValidationException('Cannot delete permission assigned to roles');
        }

        if ($permission->users()->exists()) {
            throw new PermissionValidationException('Cannot delete permission assigned to users');
        }
    }

    public function validateRoles(array $roleIds): void
    {
        $validator = Validator::make(['roles' => $roleIds], [
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,id'
        ]);

        if ($validator->fails()) {
            throw new PermissionValidationException(
                'Invalid role IDs',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUsers(array $userIds): void
    {
        $validator = Validator::make(['users' => $userIds], [
            'users' => 'required|array',
            'users.*' => 'exists:users,id'
        ]);

        if ($validator->fails()) {
            throw new PermissionValidationException(
                'Invalid user IDs',
                $validator->errors()->toArray()
            );
        }
    }
}
