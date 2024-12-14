<?php

namespace App\Core\Security;

class CriticalAccessControl
{
    private $permissions;
    private $monitor;
    private $cache;

    public function authorize(string $userId, string $resource): bool
    {
        $operationId = $this->monitor->startAuthorization();

        try {
            // Check cache first
            if ($result = $this->checkCache($userId, $resource)) {
                return $result;
            }

            // Get user roles
            $roles = $this->permissions->getUserRoles($userId);
            
            // Check permissions
            $result = $this->checkPermissions($roles, $resource);

            // Cache result
            $this->cacheResult($userId, $resource, $result);

            $this->monitor->authorizationSuccess($operationId);
            return $result;

        } catch (\Exception $e) {
            $this->monitor->authorizationFailure($operationId, $e);
            throw $e;
        }
    }

    private function checkPermissions(array $roles, string $resource): bool
    {
        foreach ($roles as $role) {
            if ($this->permissions->hasAccess($role, $resource)) {
                return true;
            }
        }
        return false;
    }

    private function cacheResult(string $userId, string $resource, bool $result): void
    {
        $this->cache->set(
            "access:$userId:$resource",
            $result,
            300 // 5 minutes
        );
    }
}
