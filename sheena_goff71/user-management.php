<?php

namespace App\Modules\Users;

use Illuminate\Support\Facades\{DB, Hash, Cache};
use App\Core\Service\BaseService;
use App\Core\Events\{UserEvent, SecurityEvent};
use App\Core\Exceptions\{UserException, SecurityException};
use App\Models\{User, Role, Permission};

class UserManager extends BaseService
{
    protected array $validationRules = [
        'create' => [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:12|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{12,}$/',
            'role_id' => 'required|exists:roles,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id'
        ],
        'update' => [
            'name' => 'string|max:255',
            'email' => 'email|unique:users,email',
            'password' => 'min:12|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{12,}$/',
            'role_id' => 'exists:roles,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
            'status' => 'in:active,inactive,suspended'
        ]
    ];

    protected array $securityConfig = [
        'max_login_attempts' => 5,
        'lockout_duration' => 30, // minutes
        'password_expiry' => 90, // days
        'session_timeout' => 15, // minutes
        'require_2fa' => true
    ];

    public function createUser(array $data): Result
    {
        return $this->executeOperation('create', $data);
    }

    public function updateUser(int $id, array $data): Result
    {
        $data['id'] = $id;
        return $this->executeOperation('update', $data);
    }

    public function deleteUser(int $id): Result
    {
        return $this->executeOperation('delete', ['id' => $id]);
    }

    public function assignRole(int $userId, int $roleId): Result
    {
        return $this->executeOperation('assign_role', [
            'user_id' => $userId,
            'role_id' => $roleId
        ]);
    }

    public function grantPermissions(int $userId, array $permissionIds): Result
    {
        return $this->executeOperation('grant_permissions', [
            'user_id' => $userId,
            'permission_ids' => $permissionIds
        ]);
    }

    protected function processOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'create' => $this->processCreate($data),
            'update' => $this->processUpdate($data),
            'delete' => $this->processDelete($data),
            'assign_role' => $this->processRoleAssignment($data),
            'grant_permissions' => $this->processPermissionGrant($data),
            default => throw new UserException("Invalid operation: {$operation}")
        };
    }

    protected function processCreate(array $data): User
    {
        // Hash password
        $data['password'] = Hash::make($data['password']);

        // Create user
        $user = $this->repository->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 'active',
            'last_password_change' => now(),
            'require_password_change' => true
        ]);

        // Assign role
        $user->roles()->attach($data['role_id']);

        // Assign additional permissions
        if (isset($data['permissions'])) {
            $user->permissions()->attach($data['permissions']);
        }

        // Set up 2FA if required
        if ($this->securityConfig['require_2fa']) {
            $this->setup2FA($user);
        }

        // Generate security credentials
        $this->generateSecurityCredentials($user);

        // Fire events
        $this->events->dispatch(new UserEvent('created', $user));

        return $user;
    }

    protected function processUpdate(array $data): User
    {
        $user = $this->repository->findOrFail($data['id']);

        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'] ?? null
        ]);

        // Update password if provided
        if (isset($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
            $updateData['last_password_change'] = now();
            $updateData['require_password_change'] = false;
        }

        // Update user
        $updated = $this->repository->update($user, $updateData);

        // Update role if provided
        if (isset($data['role_id'])) {
            $updated->roles()->sync([$data['role_id']]);
        }

        // Update permissions if provided
        if (isset($data['permissions'])) {
            $updated->permissions()->sync($data['permissions']);
        }

        // Regenerate security credentials if status changed
        if (isset($data['status'])) {
            $this->handleStatusChange($user, $data['status']);
        }

        // Fire events
        $this->events->dispatch(new UserEvent('updated', $updated));

        return $updated;
    }

    protected function processDelete(array $data): bool
    {
        $user = $this->repository->findOrFail($data['id']);

        // Remove all roles and permissions
        $user->roles()->detach();
        $user->permissions()->detach();

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete user
        $deleted = $this->repository->delete($user);

        // Fire events
        $this->events->dispatch(new UserEvent('deleted', $user));

        return $deleted;
    }

    protected function processRoleAssignment(array $data): User
    {
        $user = $this->repository->findOrFail($data['user_id']);
        $role = Role::findOrFail($data['role_id']);

        // Verify role assignment is allowed
        $this->verifyRoleAssignment($user, $role);

        // Assign role
        $user->roles()->sync([$role->id]);

        // Update permissions
        $this->syncRolePermissions($user, $role);

        // Fire events
        $this->events->dispatch(new UserEvent('role_assigned', $user, ['role' => $role]));

        return $user->fresh();
    }

    protected function processPermissionGrant(array $data): User
    {
        $user = $this->repository->findOrFail($data['user_id']);
        $permissions = Permission::findMany($data['permission_ids']);

        // Verify permissions can be granted
        $this->verifyPermissionGrant($user, $permissions);

        // Grant permissions
        $user->permissions()->syncWithoutDetaching($permissions->pluck('id'));

        // Fire events
        $this->events->dispatch(new UserEvent('permissions_granted', $user, [
            'permissions' => $permissions
        ]));

        return $user->fresh();
    }

    protected function verifyRoleAssignment(User $user, Role $role): void
    {
        if (!$this->security->canAssignRole($user, $role)) {
            throw new SecurityException('Role assignment not allowed');
        }
    }

    protected function verifyPermissionGrant(User $user, $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->security->canGrantPermission($user, $permission)) {
                throw new SecurityException(
                    "Cannot grant permission: {$permission->name}"
                );
            }
        }
    }

    protected function syncRolePermissions(User $user, Role $role): void
    {
        $rolePermissions = $role->permissions()->pluck('id');
        $user->permissions()->syncWithoutDetaching($rolePermissions);
    }

    protected function setup2FA(User $user): void
    {
        // Implementation of 2FA setup
    }

    protected function generateSecurityCredentials(User $user): void
    {
        // Implementation of security credential generation
    }

    protected function handleStatusChange(User $user, string $newStatus): void
    {
        if ($newStatus === 'suspended') {
            $user->tokens()->delete();
            $this->events->dispatch(new SecurityEvent('user_suspended', $user));
        }
    }

    protected function getValidationRules(string $operation): array
    {
        return $this->validationRules[$operation] ?? [];
    }

    protected function getRequiredPermissions(string $operation): array
    {
        return ["users.{$operation}"];
    }

    protected function isValidResult(string $operation, $result): bool
    {
        return match($operation) {
            'create', 'update', 'assign_role', 'grant_permissions' => $result instanceof User,
            'delete' => is_bool($result),
            default => false
        };
    }
}
