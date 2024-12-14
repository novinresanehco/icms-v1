```php
<?php

namespace App\Core\Security;

use App\Core\Exception\AuthorizationException;
use Psr\Log\LoggerInterface;

class AuthorizationManager implements AuthorizationManagerInterface 
{
    private LoggerInterface $logger;
    private array $permissions = [];
    private array $roles = [];
    private array $config;

    public function __construct(
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function checkPermission(
        User $user,
        string $permission,
        array $context = []
    ): bool {
        $operationId = $this->generateOperationId();

        try {
            $this->validatePermission($permission);
            $this->validateUser($user);

            if ($this->hasPermission($user, $permission, $context)) {
                $this->logPermissionGranted($operationId, $user, $permission);
                return true;
            }

            $this->logPermissionDenied($operationId, $user, $permission);
            return false;

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($operationId, $user, $permission, $e);
            throw new AuthorizationException(
                "Permission check failed: {$permission}",
                0,
                $e
            );
        }
    }

    public function assignRole(User $user, string $role): void 
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->validateRole($role);
            $this->validateUser($user);

            if ($this->hasRole($user, $role)) {
                throw new AuthorizationException("Role already assigned");
            }

            $this->executeRoleAssignment($user, $role);
            $this->logRoleAssignment($operationId, $user, $role);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthorizationFailure($operationId, $user, $role, $e);
            throw new AuthorizationException(
                "Role assignment failed: {$role}",
                0,
                $e
            );
        }
    }

    public function revokeRole(User $user, string $role): void 
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->validateRole($role);
            $this->validateUser($user);

            if (!$this->hasRole($user, $role)) {
                throw new AuthorizationException("Role not assigned");
            }

            $this->executeRoleRevocation($user, $role);
            $this->logRoleRevocation($operationId, $user, $role);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthorizationFailure($operationId, $user, $role, $e);
            throw new AuthorizationException(
                "Role revocation failed: {$role}",
                0,
                $e
            );
        }
    }

    private function hasPermission(
        User $user,
        string $permission,
        array $context
    ): bool {
        foreach ($user->getRoles() as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                if ($this->validateContext($role, $permission, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function roleHasPermission(string $role, string $permission): bool 
    {
        if (!isset($this->roles[$role])) {
            return false;
        }

        return in_array($permission, $this->roles[$role]['permissions']);
    }

    private function validateContext(
        string $role,
        string $permission,
        array $context
    ): bool {
        if (empty($context)) {
            return true;
        }

        $validator = $this->permissions[$permission]['validator'] ?? null;
        if (!$validator) {
            return true;
        }

        return $validator($context);
    }

    private function executeRoleAssignment(User $user, string $role): void 
    {
        DB::table('user_roles')->insert([
            'user_id' => $user->getId(),
            'role' => $role,
            'assigned_at' => now()
        ]);
    }

    private function executeRoleRevocation(User $user, string $role): void 
    {
        DB::table('user_roles')
            ->where('user_id', $user->getId())
            ->where('role', $role)
            ->delete();
    }

    private function validatePermission(string $permission): void 
    {
        if (!isset($this->permissions[$permission])) {
            throw new AuthorizationException("Invalid permission: {$permission}");
        }
    }

    private function validateRole(string $role): void 
    {
        if (!isset($this->roles[$role])) {
            throw new AuthorizationException("Invalid role: {$role}");
        }
    }

    private function validateUser(User $user): void 
    {
        if (!$user->isActive()) {
            throw new AuthorizationException("User is not active");
        }
    }

    private function generateOperationId(): string 
    {
        return uniqid('auth_', true);
    }

    private function logPermissionGranted(
        string $operationId,
        User $user,
        string $permission
    ): void {
        $this->logger->info('Permission granted', [
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'permission' => $permission,
            'timestamp' => microtime(true)
        ]);
    }

    private function logPermissionDenied(
        string $operationId,
        User $user,
        string $permission
    ): void {
        $this->logger->warning('Permission denied', [
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'permission' => $permission,
            'timestamp' => microtime(true)
        ]);
    }

    private function logRoleAssignment(
        string $operationId,
        User $user,
        string $role
    ): void {
        $this->logger->info('Role assigned', [
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'role' => $role,
            'timestamp' => microtime(true)
        ]);
    }

    private function logRoleRevocation(
        string $operationId,
        User $user,
        string $role
    ): void {
        $this->logger->info('Role revoked', [
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'role' => $role,
            'timestamp' => microtime(true)
        ]);
    }

    private function handleAuthorizationFailure(
        string $operationId,
        User $user,
        string $target,
        \Exception $e
    ): void {
        $this->logger->error('Authorization operation failed', [
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'target' => $target,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array 
    {
        return [
            'cache_permissions' => true,
            'cache_ttl' => 3600,
            'strict_mode' => true,
            'default_role' => 'user'
        ];
    }
}
```
