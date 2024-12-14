<?php

namespace App\Core\Validation;

use App\Core\Exceptions\AuthValidationException;
use Illuminate\Support\Facades\Validator;

class AuthValidator
{
    public function validateCredentials(array $credentials): void
    {
        $validator = Validator::make($credentials, [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid credentials: ' . implode(', ', $validator->errors()->all())
            );
        }
    }

    public function validateRegistration(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'status' => 'nullable|in:active,inactive',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,id',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid registration data: ' . implode(', ', $validator->errors()->all())
            );
        }
    }

    public function validatePasswordReset(array $data): void
    {
        $validator = Validator::make($data, [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid password reset data: ' . implode(', ', $validator->errors()->all())
            );
        }
    }

    public function validateRoleCreation(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|unique:roles,name',
            'description' => 'nullable|string',
            'guard_name' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid role data: ' . implode(', ', $validator->errors()->all())
            );
        }
    }

    public function validatePermissionCreation(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|unique:permissions,name',
            'description' => 'nullable|string',
            'guard_name' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid permission data: ' . implode(', ', $validator->errors()->all())
            );
        }
    }
}
