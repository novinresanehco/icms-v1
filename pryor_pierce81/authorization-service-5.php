<?php

namespace App\Core\Security\Authorization;

class CriticalAuthorizationService
{
    private $roleManager;
    private $permissionCache;
    private $monitor;

    public function authorize(Request $request): bool
    {
        $this->monitor->startAuthorization();

        try {
            // Validate token
            if (!$this->validateToken($request->token)) {
                throw new AuthException('Invalid token');
            }

            // Check permissions
            if (!$this->checkPermissions($request)) {
                throw new AuthException('Insufficient permissions');
            }

            $this->monitor->authorizationSuccess();
            return true;

        } catch (\Exception $e) {
            $this->monitor->authorizationFailure($e);
            throw $e;
        }
    }

    private function checkPermissions(Request $request): bool
    {
        $permissions = $this->permissionCache->get($request->token);
        return $this->roleManager->hasPermission($permissions, $request->resource);
    }
}
