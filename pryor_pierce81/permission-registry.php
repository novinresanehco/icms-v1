<?php

namespace App\Core\Auth;

use App\Core\Exception\AuthorizationException;
use Psr\Log\LoggerInterface;

class PermissionRegistry implements PermissionRegistryInterface
{
    private $storage;
    private LoggerInterface $logger;

    public function __construct(
        PermissionStorageInterface $storage,
        LoggerInterface $logger
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
    }

    public function getRolePermissions(string $roleId): array
    {
        try {
            return $this->storage->getRolePermissions($roleId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get role permissions', [
                'role_id' => $roleId,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Failed to get role permissions', 0, $e);
        }
    }

    public function grantPermission(string $roleId, string $permission): void
    {
        try {
            $this->storage->addPermission($roleId, $permission);
            $this->logger->info('Permission granted', [
                'role_id' => $roleId,
                'permission' => $permission
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to grant permission', [
                'role_id' => $roleId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Failed to grant permission', 0, $e);
        }
    }

    public function revokePermission(string $roleId, string $permission): void
    {
        try {
            $this->storage->removePermission($roleId, $permission);
            $this->logger->info('Permission revoked', [
                'role_id' => $roleId,
                'permission' => $permission
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to revoke permission', [
                'role_id' => $roleId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Failed to revoke permission', 0, $e);
        }
    }

    public function getPermissionConstraints(string $permission): array
    {
        try {
            return $this->storage->getPermissionConstraints($permission);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get permission constraints', [
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Failed to get permission constraints', 0, $e);
        }
    }
}
