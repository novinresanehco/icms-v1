<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class RoleManager implements RoleInterface
{
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;
    private PermissionManager $permissions;

    private const CACHE_TTL = 3600;
    private const MAX_RETRIES = 3;

    public function __construct(
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit,
        PermissionManager $permissions
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->permissions = $permissions;
    }

    public function createRole(RoleRequest $request, SecurityContext $context): RoleResult
    {
        DB::beginTransaction();

        try {
            // Validate request
            $this->validateRoleRequest($request, $context);

            // Create role
            $role = $this->processRoleCreation($request);

            // Assign permissions
            $this->assignRolePermissions($role->getId(), $request->getPermissions());

            // Update cache
            $this->invalidateRoleCache();

            DB::commit();

            // Log creation
            $this->logRoleCreation($role, $context);

            return new RoleResult($role);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRoleCreationFailure($e, $request);
            throw $e;
        }
    }

    public function updateRole(
        string $roleId,
        RoleRequest $request,
        SecurityContext $context
    ): RoleResult {
        DB::beginTransaction();

        try {
            // Load existing role
            $existing = $this->loadRole($roleId);

            // Validate update
            $this->validateRoleUpdate($request, $existing, $context);

            // Process update
            $updated = $this->processRoleUpdate($existing, $request);

            // Update permissions
            $this->updateRolePermissions($roleId, $request->getPermissions());

            // Update cache
            $this->invalidateRoleCache($roleId);

            DB::commit();

            // Log update
            $this->logRoleUpdate($updated, $context);

            return new RoleResult($updated);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRoleUpdateFailure($e, $roleId, $request);
            throw $e;
        }
    }

    public function deleteRole(string $roleId, SecurityContext $context): void
    {
        DB::beginTransaction();

        try {
            // Load role
            $role = $this->loadRole($roleId);

            // Validate deletion
            $this->validateRoleDeletion($role, $context);

            // Process deletion
            $this->processRoleDeletion($role);

            // Update cache
            $this->invalidateRoleCache($roleId);

            DB::commit();

            // Log deletion
            $this->logRoleDeletion($role, $context);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRoleDeletionFailure($e, $roleId);
            throw $e;
        }
    }

    private function validateRoleRequest(RoleRequest $request, SecurityContext $context): void
    {
        if (!$this->validator->validateRole($request)) {
            throw new RoleValidationException('Invalid role request');
        }

        if (!$context->canManageRoles()) {
            throw new RoleAuthorizationException('Insufficient permissions');
        }
    }

    private function processRoleCreation(RoleRequest $request): Role
    {
        $role = new Role(
            $request->getName(),
            $request->getDescription(),
            $request->getMetadata()
        );

        if (!$role->isValid()) {
            throw new RoleProcessingException('Role creation failed');
        }

        return $role;
    }

    private function assignRolePermissions(string $roleId, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->permissions->assignPermission(
                $permission,
                $roleId,
                new SecurityContext(['system' => true])
            );
        }
    }

    private function loadRole(string $roleId): Role
    {
        $cacheKey = $this->getRoleCacheKey($roleId);

        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->loadRoleFromDatabase($roleId)
        );
    }

    private function loadRoleFromDatabase(string $roleId): Role
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $data = DB::table('roles')
                    ->where('id', $roleId)
                    ->first();

                if (!$data) {
                    throw new RoleNotFoundException("Role not found: {$roleId}");
                }

                return Role::fromDatabase($data);

            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRIES) {
                    throw new RoleLoadException(
                        'Failed to load role',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
    }

    private function invalidateRoleCache(?string $roleId = null): void
    {
        if ($roleId) {
            $this->cache->forget($this->getRoleCacheKey($roleId));
        } else {
            $this->cache->tags(['roles'])->flush();
        }
    }

    private function getRoleCacheKey(string $roleId): string
    {
        return "role:{$roleId}";
    }

    private function logRoleCreation(Role $role, SecurityContext $context): void
    {
        $this->audit->logRoleCreation([
            'role_id' => $role->getId(),
            'created_by' => $context->getUserId(),
            'timestamp' => now()
        ]);
    }

    private function handleRoleCreationFailure(\Exception $e, RoleRequest $request): void
    {
        $this->audit->logRoleCreationFailure([
            'error' => $e->getMessage(),
            'request' => $request->toArray(),
            'timestamp' => now()
        ]);
    }
}
