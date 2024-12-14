<?php

namespace App\Core\User;

use App\Core\Security\{SecurityManager, SecurityContext};
use App\Core\Contracts\{UserManagerInterface, RoleManagerInterface};
use Illuminate\Support\Facades\{DB, Hash, Cache};
use App\Core\Exceptions\{UserException, ValidationException};

class UserManager implements UserManagerInterface
{
    private SecurityManager $security;
    private RoleManager $roleManager;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        RoleManager $roleManager,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->roleManager = $roleManager;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function createUser(array $data, SecurityContext $context): User
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUserCreation($data),
            $context
        );
    }

    private function executeUserCreation(array $data): User
    {
        $validated = $this->validator->validate($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:12',
            'role_id' => 'required|exists:roles,id'
        ]);

        DB::beginTransaction();
        try {
            $user = new User();
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->password = Hash::make($validated['password']);
            $user->save();

            $this->roleManager->assignRole($user, $validated['role_id']);
            
            $this->auditLogger->logUserCreation($user);
            
            DB::commit();
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserException('Failed to create user: ' . $e->getMessage());
        }
    }

    public function updateUser(int $id, array $data, SecurityContext $context): User
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUserUpdate($id, $data),
            $context
        );
    }

    private function executeUserUpdate(int $id, array $data): User
    {
        $user = User::findOrFail($id);
        
        $validated = $this->validator->validate($data, [
            'name' => 'string|max:255',
            'email' => "email|unique:users,email,{$id}",
            'password' => 'string|min:12',
            'role_id' => 'exists:roles,id'
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }
            if (isset($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            
            $user->save();

            if (isset($validated['role_id'])) {
                $this->roleManager->updateRole($user, $validated['role_id']);
            }

            $this->auditLogger->logUserUpdate($user);
            
            DB::commit();
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserException('Failed to update user: ' . $e->getMessage());
        }
    }

    public function deleteUser(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUserDeletion($id),
            $context
        );
    }

    private function executeUserDeletion(int $id): bool
    {
        $user = User::findOrFail($id);

        DB::beginTransaction();
        try {
            $this->roleManager->removeAllRoles($user);
            $user->delete();

            $this->auditLogger->logUserDeletion($user);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserException('Failed to delete user: ' . $e->getMessage());
        }
    }

    public function getUser(int $id, SecurityContext $context): User
    {
        return $this->security->executeCriticalOperation(
            fn() => Cache::remember(
                "user:{$id}",
                3600,
                fn() => User::with('roles')->findOrFail($id)
            ),
            $context
        );
    }
}

class RoleManager implements RoleManagerInterface
{
    private PermissionRegistry $permissions;
    private AuditLogger $auditLogger;

    public function assignRole(User $user, int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $user->roles()->attach($roleId);
        
        $this->auditLogger->logRoleAssignment($user, $role);
        $this->clearPermissionCache($user);
    }

    public function updateRole(User $user, int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $user->roles()->sync([$roleId]);
        
        $this->auditLogger->logRoleUpdate($user, $role);
        $this->clearPermissionCache($user);
    }

    public function removeAllRoles(User $user): void
    {
        $user->roles()->detach();
        $this->auditLogger->logRolesRemoval($user);
        $this->clearPermissionCache($user);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return Cache::remember(
            "user_permissions:{$user->id}",
            3600,
            fn() => $this->calculatePermissions($user)
        )->contains($permission);
    }

    private function calculatePermissions(User $user): Collection
    {
        return $user->roles()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique();
    }

    private function clearPermissionCache(User $user): void
    {
        Cache::forget("user_permissions:{$user->id}");
    }
}

class PermissionRegistry
{
    private array $permissions = [];

    public function register(string $permission, string $description): void
    {
        $this->permissions[$permission] = $description;
    }

    public function isValid(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function getDescription(string $permission): ?string
    {
        return $this->permissions[$permission] ?? null;
    }
}
