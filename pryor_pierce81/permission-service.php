<?php

namespace App\Core\Auth;

class PermissionService 
{
    private $cache;
    private $monitor;

    public function validate(string $userId, string $resource): bool
    {
        $operationId = $this->monitor->startPermissionCheck();

        try {
            // Check cache first
            if ($result = $this->cache->get("perm:$userId:$resource")) {
                return $result;
            }

            // Validate permissions
            $result = $this->checkPermissions($userId, $resource);

            // Cache result
            $this->cache->set("perm:$userId:$resource", $result);

            $this->monitor->permissionCheckSuccess($operationId);
            return $result;

        } catch (\Exception $e) {
            $this->monitor->permissionCheckFailure($operationId, $e);
            throw $e;
        }
    }

    private function checkPermissions(string $userId, string $resource): bool
    {
        // Get user roles
        $roles = $this->getUserRoles($userId);

        // Verify against resource permissions
        $required = $this->getResourcePermissions($resource);

        return $this->validatePermissions($roles, $required);
    }
}
