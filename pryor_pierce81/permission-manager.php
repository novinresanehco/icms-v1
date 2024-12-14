<?php

namespace App\Core\Permission;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\PermissionException;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class PermissionManager implements PermissionManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;
    private array $cachedPermissions = [];

    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validatePermission(string $permission, array $context): bool
    {
        $validationId = $this->generateValidationId();

        try {
            $this->security->validateSecureOperation('permission:validate', $context);
            
            if (!isset($context['user_id'])) {
                throw new PermissionException('User context required');
            }

            $hasPermission = $this->checkPermission($permission, $context['user_id']);
            $this->audit->logPermissionCheck($validationId, $permission, $hasPermission);

            return $hasPermission;

        } catch (\Exception $e) {
            $this->handlePermissionFailure($validationId, $permission, $e);
            throw new PermissionException('Permission validation failed', 0, $e);
        }
    }

    public function assignPermission(string $permission, int $roleId): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('permission:assign', [
                'role_id' => $roleId
            ]);

            $this->validatePermissionString($permission);
            $this->validateRole($roleId);

            $this->processPermissionAssignment($permission, $roleId);
            $this->audit->logPermissionAssignment($permission, $roleId);

            $this->invalidatePermissionCache($roleId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAssignmentFailure($permission, $roleId, $e);
            throw new PermissionException('Permission assignment failed', 0, $e);
        }
    }

    public function revokePermission(string $permission, int $roleId): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('permission:revoke', [
                'role_id' => $roleId
            ]);

            $this->validatePermissionString($permission);
            $this->validateRole($roleId);

            $this->processPermissionRevocation($permission, $roleId);
            $this->audit->logPermissionRevocation($permission, $roleId);

            $this->invalidatePermissionCache($roleId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRevocationFailure($permission, $roleId, $e);
            throw new PermissionException('Permission revocation failed', 0, $e);
        }
    }

    private function checkPermission(string $permission, int $userId): bool
    {
        $userRoles = $this->getUserRoles($userId);
        
        foreach ($userRoles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        
        return false;
    }

    private function validatePermissionString(string $permission): void
    {
        if (!preg_match($this->config['permission_pattern'], $permission)) {
            throw new PermissionException('Invalid permission format');
        }

        if (!$this->isValidPermission($permission)) {
            throw new PermissionException('Permission does not exist');
        }
    }

    private function validateRole(int $roleId): void
    {
        if (!$this->roleExists($roleId)) {
            throw new PermissionException('Role does not exist');
        }

        if ($this->isSystemRole($roleId)) {
            throw new PermissionException('Cannot modify system role permissions');
        }
    }

    private function roleHasPermission(Role $role, string $permission): bool
    {
        if (!isset($this->cachedPermissions[$role->id])) {
            $this->cachedPermissions[$role->id] = $this->loadRolePermissions($role->id);
        }

        return in_array($permission, $this->cachedPermissions[$role->id]);
    }

    private function handlePermissionFailure(string $id, string $permission, \Exception $e): void
    {
        $this->logger->error('Permission operation failed', [
            'validation_id' => $id,
            'permission' => $permission,
            'error' => $e->getMessage()
        ]);

        $this->audit->logPermissionFailure($id, $permission, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'permission_pattern' => '/^[a-z]+:[a-z]+$/',
            'cache_ttl' => 3600,
            'system_roles' => ['admin', 'system'],
            'max_role_permissions' => 100
        ];
    }
}
