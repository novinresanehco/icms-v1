<?php

namespace App\Core\User\Services;

use App\Core\User\Models\User;
use App\Exceptions\UserValidationException;
use Illuminate\Support\Facades\Validator;

class UserValidator
{
    public function validateCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        if ($validator->fails()) {
            throw new UserValidationException(
                'User validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(User $user, array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'password' => 'sometimes|string|min:8',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        if ($validator->fails()) {
            throw new UserValidationException(
                'User validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateDelete(User $user): void
    {
        if ($user->isSuperAdmin()) {
            throw new UserValidationException('Cannot delete super admin user');
        }
    }

    public function validateRole(string $role): void
    {
        if (!Role::where('name', $role)->exists()) {
            throw new UserValidationException("Role {$role} does not exist");
        }
    }

    public function validateRoles(array $roles): void
    {
        $existing = Role::whereIn('name', $roles)->pluck('name');
        $invalid = array_diff($roles, $existing->all());

        if (!empty($invalid)) {
            throw new UserValidationException(
                'Invalid roles: ' . implode(', ', $invalid)
            );
        }
    }

    public function validatePermission(string $permission): void
    {
        if (!Permission::where('name', $permission)->exists()) {
            throw new UserValidationException("Permission {$permission} does not exist");
        }
    }

    public function validatePermissions(array $permissions): void
    {
        $existing = Permission::whereIn('name', $permissions)->pluck('name');
        $invalid = array_diff($permissions, $existing->all());

        if (!empty($invalid)) {
            throw new UserValidationException(
                'Invalid permissions: ' . implode(', ', $invalid)
            );
        }
    }

    public function validateProfileUpdate(User $user, array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'timezone' => 'sometimes|string|timezone',
            'language' => 'sometimes|string|size:2',
            'metadata' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            throw new UserValidationException(
                'Profile validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validatePassword(string $password): void
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => 'required|string|min:8|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/']
        );

        if ($validator->fails()) {
            throw new UserValidationException(
                'Password validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateSuspension(User $user): void
    {
        if ($user->isSuperAdmin()) {
            throw new UserValidationException('Cannot suspend super admin user');
        }

        if ($user->isSuspended()) {
            throw new UserValidationException('User is already suspended');
        }
    }
}
