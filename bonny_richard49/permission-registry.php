<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\{SecurityConfig, SecurityException};
use App\Core\Interfaces\PermissionRegistryInterface;

class PermissionRegistry implements PermissionRegistryInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private array $permissions = [];
    private array $inheritance = [];

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->loadPermissions();
    }

    public function registerPermission(
        string $permission,
        array $attributes = [],
        ?array $inherits = null
    ): void {
        DB::beginTransaction();
        try {
            // Validate permission format
            $this->validatePermissionFormat($permission);

            // Check for existing permission
            if ($this->exists($permission)) {
                throw new PermissionExistsException("Permission already exists: {$permission}");
            }

            // Validate inheritance chain
            if ($inherits) {
                foreach ($inherits as $parent) {
                    if (!$this->exists($parent)) {
                        throw new PermissionNotFoundException("Parent permission not found: {$parent}");
                    }
                }
            }

            // Register permission
            $this->permissions[$permission] = array_merge(
                $attributes,
                ['created_at' => now()]
            );

            // Set inheritance
            if ($inherits) {
                $this->inheritance[$permission] = $inherits;
            }

            // Store in database
            $this->storePermission($permission, $attributes, $inherits);

            // Clear cache
            $this->clearPermissionCache();

            // Log registration
            $this->auditLogger->logPermissionRegistration($permission, $attributes, $inherits);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRegistryError($e, 'register', [
                'permission' => $permission,
                'attributes' => $attributes
            ]);
            throw new PermissionRegistrationException(
                'Failed to register permission',
                previous: $e
            );
        }
    }

    public function updatePermission(
        string $permission,
        array $attributes,
        ?array $inherits = null
    ): void {
        DB::beginTransaction();
        try {
            // Verify permission exists
            if (!$this->exists($permission)) {
                throw new PermissionNotFoundException("Permission not found: {$permission}");
            }

            // Update attributes
            $this->permissions[$permission] = array_merge(
                $attributes,
                ['updated_at' => now()]
            );

            // Update inheritance if provided
            if ($inherits !== null) {
                $this->validateInheritanceChain($permission, $inherits);
                $this->inheritance[$permission] = $inherits;
            }

            // Update in database
            $this->updateStoredPermission($permission, $attributes, $inherits);

            // Clear cache
            $this->clearPermissionCache();

            // Log update
            $this->auditLogger->logPermissionUpdate($permission, $attributes, $inherits);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRegistryError($e, 'update', [
                'permission' => $permission,
                'attributes' => $attributes
            ]);
            throw new PermissionUpdateException(
                'Failed to update permission',
                previous: $e
            );
        }
    }

    public function exists(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function getAttributes(string $permission): array
    {
        if (!$this->exists($permission)) {
            throw new PermissionNotFoundException("Permission not found: {$permission}");
        }
        return $this->permissions[$permission];
    }

    public function validateContext(User $user, string $permission, array $context): bool
    {
        try {
            // Get permission attributes
            $attributes = $this->getAttributes($permission);

            // No contextual rules defined
            if (!isset($attributes['context_rules'])) {
                return true;
            }

            // Evaluate each context rule
            foreach ($attributes['context_rules'] as $rule) {
                if (!$this->evaluateContextRule($user, $rule, $context)) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->handleRegistryError($e, 'context_validation', [
                'user_id' => $user->id,
                'permission' => $permission,
                'context' => $context
            ]);
            throw new ContextValidationException(
                'Context validation failed',
                previous: $e
            );
        }
    }

    public function getInheritedPermissions(string $permission): array
    {
        $inherited = [];
        $toProcess = [$permission];

        while (!empty($toProcess)) {
            $current = array_shift($toProcess);
            
            if (isset($this->inheritance[$current])) {
                foreach ($this->inheritance[$current] as $parent) {
                    if (!in_array($parent, $inherited)) {
                        $inherited[] = $parent;
                        $toProcess[] = $parent;
                    }
                }
            }
        }

        return $inherited;
    }

    public function removePermission(string $permission): void
    {
        DB::beginTransaction();
        try {
            // Check if permission exists
            if (!$this->exists($permission)) {
                throw new PermissionNotFoundException("Permission not found: {$permission}");
            }

            // Check if other permissions inherit from this one
            foreach ($this->inheritance as $child => $parents) {
                if (in_array($permission, $parents)) {
                    throw new PermissionInUseException(
                        "Permission cannot be removed: inherited by {$child}"
                    );
                }
            }

            // Remove from registry
            unset($this->permissions[$permission]);
            unset($this->inheritance[$permission]);

            // Remove from database
            $this->removeStoredPermission($permission);

            // Clear cache
            $this->clearPermissionCache();

            // Log removal
            $this->auditLogger->logPermissionRemoval($permission);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRegistryError($e, 'remove', [
                'permission' => $permission
            ]);
            throw new PermissionRemovalException(
                'Failed to remove permission',
                previous: $e
            );
        }
    }

    private function loadPermissions(): void
    {
        $this->permissions = Cache::remember(
            'permissions',
            $this->config->getCacheTTL(),
            fn() => $this->loadStoredPermissions()
        );

        $this->inheritance = Cache::remember(
            'permission_inheritance',
            $this->config->getCacheTTL(),
            fn() => $this->loadStoredInheritance()
        );
    }

    private function validatePermissionFormat(string $permission): void
    {
        if (!preg_match('/^[a-z0-9_\-\.*]+$/i', $permission)) {
            throw new InvalidPermissionFormatException(
                'Invalid permission format'
            );
        }
    }

    private function validateInheritanceChain(string $permission, array $inherits): void
    {
        // Check for circular dependencies
        $allInherited = $this->getInheritedPermissions($permission);
        foreach ($inherits as $parent) {
            if ($parent === $permission || in_array($parent, $allInherited)) {
                throw new CircularInheritanceException(
                    'Circular inheritance detected'
                );
            }
        }
    }

    private function evaluateContextRule(User $user, array $rule, array $context): bool
    {
        // Implement context rule evaluation based on rule type
        return match($rule['type']) {
            'ownership' => $this->evaluateOwnership($user, $rule, $context),
            'department' => $this->evaluateDepartment($user, $rule, $context),
            'time_window' => $this->evaluateTimeWindow($rule, $context),
            default => throw new UnsupportedRuleTypeException()
        };
    }

    private function clearPermissionCache(): void
    {
        Cache::forget('permissions');
        Cache::forget('permission_inheritance');
    }

    private function handleRegistryError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logRegistryError($e, $operation, $context);
    }

    // Database interaction methods
    private function storePermission(string $permission, array $attributes, ?array $inherits): void
    {
        // Implementation for storing permission in database
    }

    private function updateStoredPermission(string $permission, array $attributes, ?array $inherits): void
    {
        // Implementation for updating stored permission
    }

    private function removeStoredPermission(string $permission): void
    {
        // Implementation for removing stored permission
    }

    private function loadStoredPermissions(): array
    {
        // Implementation for loading permissions from database
        return [];
    }

    private function loadStoredInheritance(): array
    {
        // Implementation for loading inheritance from database
        return [];
    }
}
