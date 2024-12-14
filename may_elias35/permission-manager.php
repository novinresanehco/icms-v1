<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class PermissionManager implements PermissionInterface 
{
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    private const CACHE_TTL = 3600;
    private const MAX_RETRIES = 3;

    public function __construct(
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function validatePermission(string $permission, SecurityContext $context): bool
    {
        try {
            // Validate inputs
            $this->validateInputs($permission, $context);

            // Check permission
            $hasPermission = $this->checkPermission($permission, $context);

            // Log validation
            $this->logPermissionCheck($permission, $context, $hasPermission);

            return $hasPermission;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $permission, $context);
            throw $e;
        }
    }

    public function assignPermission(
        string $permission,
        string $roleId,
        SecurityContext $context
    ): void {
        DB::beginTransaction();

        try {
            // Validate assignment
            $this->validateAssignment($permission, $roleId, $context);

            // Process assignment
            $this->processPermissionAssignment($permission, $roleId);

            // Update cache
            $this->invalidatePermissionCache($roleId);

            DB::commit();

            // Log assignment
            $this->logPermissionAssignment($permission, $roleId, $context);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAssignmentFailure($e, $permission, $roleId);
            throw $e;
        }
    }

    public function revokePermission(
        string $permission,
        string $roleId,
        SecurityContext $context
    ): void {
        DB::beginTransaction();

        try {
            // Validate revocation
            $this->validateRevocation($permission, $roleId, $context);

            // Process revocation
            $this->processPermissionRevocation($permission, $roleId);

            // Update cache
            $this->invalidatePermissionCache($roleId);

            DB::commit();

            // Log revocation
            $this->logPermissionRevocation($permission, $roleId, $context);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRevocationFailure($e, $permission, $roleId);
            throw $e;
        }
    }

    private function validateInputs(string $permission, SecurityContext $context): void
    {
        if (!$this->validator->validatePermission($permission)) {
            throw new PermissionValidationException('Invalid permission format');
        }

        if (!$context->isValid()) {
            throw new PermissionValidationException('Invalid security context');
        }
    }

    private function checkPermission(string $permission, SecurityContext $context): bool
    {
        $roleId = $context->getRoleId();
        $permissions = $this->getPermissions($roleId);

        return in_array($permission, $permissions, true) ||
               in_array('*', $permissions, true);
    }

    private function getPermissions(string $roleId): array
    {
        $cacheKey = $this->getPermissionCacheKey($roleId);

        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->loadPermissions($roleId)
        );
    }

    private function loadPermissions(string $roleId): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                return DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->pluck('permission')
                    ->toArray();

            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRIES) {
                    throw new PermissionLoadException(
                        'Failed to load permissions',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }

        throw new PermissionLoadException('Failed to load permissions after retries');
    }

    private function validateAssignment(
        string $permission,
        string $roleId,
        SecurityContext $context
    ): void {
        if (!$context->canAssignPermissions()) {
            throw new PermissionDeniedException('Cannot assign permissions');
        }

        if (!$this->validator->validatePermissionAssignment($permission, $roleId)) {
            throw new PermissionValidationException('Invalid permission assignment');
        }
    }

    private function processPermissionAssignment(string $permission, string $roleId): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission' => $permission,
            'created_at' => now()
        ]);
    }

    private function invalidatePermissionCache(string $roleId): void
    {
        $this->cache->forget($this->getPermissionCacheKey($roleId));
    }

    private function getPermissionCacheKey(string $roleId): string
    {
        return "permissions:{$roleId}";
    }

    private function logPermissionCheck(
        string $permission,
        SecurityContext $context,
        bool $result
    ): void {
        $this->audit->logPermissionCheck([
            'permission' => $permission,
            'role_id' => $context->getRoleId(),
            'user_id' => $context->getUserId(),
            'result' => $result,
            'timestamp' => now()
        ]);
    }

    private function logPermissionAssignment(
        string $permission,
        string $roleId,
        SecurityContext $context
    ): void {
        $this->audit->logPermissionAssignment([
            'permission' => $permission,
            'role_id' => $roleId,
            'assigned_by' => $context->getUserId(),
            'timestamp' => now()
        ]);
    }

    private function logPermissionRevocation(
        string $permission,
        string $roleId,
        SecurityContext $context
    ): void {
        $this->audit->logPermissionRevocation([
            'permission' => $permission,
            'role_id' => $roleId,
            'revoked_by' => $context->getUserId(),
            'timestamp' => now()
        ]);
    }

    private function handleValidationFailure(
        \Exception $e,
        string $permission,
        SecurityContext $context
    ): void {
        $this->audit->logPermissionValidationFailure([
            'error' => $e->getMessage(),
            'permission' => $permission,
            'role_id' => $context->getRoleId(),
            'user_id' => $context->getUserId(),
            'timestamp' => now()
        ]);
    }

    private function handleAssignmentFailure(
        \Exception $e,
        string $permission,
        string $roleId
    ): void {
        $this->audit->logPermissionAssignmentFailure([
            'error' => $e->getMessage(),
            'permission' => $permission,
            'role_id' => $roleId,
            'timestamp' => now()
        ]);
    }

    private function handleRevocationFailure(
        \Exception $e,
        string $permission,
        string $roleId
    ): void {
        $this->audit->logPermissionRevocationFailure([
            'error' => $e->getMessage(),
            'permission' => $permission,
            'role_id' => $roleId,
            'timestamp' => now()
        ]);
    }
}
