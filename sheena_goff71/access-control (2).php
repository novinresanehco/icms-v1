<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\Events\SecurityEvent;

class AccessControlSystem
{
    private PermissionRegistry $permissions;
    private AuditSystem $audit;
    private SecurityConfig $config;
    private MetricsCollector $metrics;
    private array $activePermissionCache = [];

    public function validateAccess(string $userId, string $resource, string $action): bool
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $cacheKey = $this->generateAccessCacheKey($userId, $resource, $action);
            
            if ($cached = $this->getCachedDecision($cacheKey)) {
                return $cached;
            }

            $this->validateSecurityContext();
            
            $roles = $this->getUserRoles($userId);
            $permissions = $this->getEffectivePermissions($roles);
            
            $decision = $this->evaluateAccess($permissions, $resource, $action);
            
            $this->logAccessAttempt($userId, $resource, $action, $decision);
            $this->cacheAccessDecision($cacheKey, $decision);
            
            DB::commit();
            $this->updateMetrics($startTime);
            
            return $decision;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessFailure($e, $userId, $resource, $action);
            throw $e;
        }
    }

    public function assignRole(string $userId, string $roleId): void
    {
        DB::transaction(function() use ($userId, $roleId) {
            $this->validateRole($roleId);
            $this->clearUserPermissionCache($userId);
            
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => now(),
                'assigned_by' => auth()->id()
            ]);
            
            $this->audit->logSecurityEvent(new SecurityEvent(
                'role_assigned',
                'user_id: ' . $userId . ', role_id: ' . $roleId
            ));
        });
    }

    public function revokeRole(string $userId, string $roleId): void
    {
        DB::transaction(function() use ($userId, $roleId) {
            $this->clearUserPermissionCache($userId);
            
            DB::table('user_roles')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->delete();
                
            $this->audit->logSecurityEvent(new SecurityEvent(
                'role_revoked',
                'user_id: ' . $userId . ', role_id: ' . $roleId
            ));
        });
    }

    public function updatePermissions(string $roleId, array $permissions): void
    {
        DB::transaction(function() use ($roleId, $permissions) {
            $this->validatePermissions($permissions);
            $this->clearRolePermissionCache($roleId);
            
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->delete();
                
            $this->insertPermissions($roleId, $permissions);
            
            $this->audit->logSecurityEvent(new SecurityEvent(
                'permissions_updated',
                'role_id: ' . $roleId
            ));
        });
    }

    private function validateSecurityContext(): void
    {
        if (!$this->config->isSecurityContextValid()) {
            throw new SecurityContextException('Invalid security context');
        }
    }

    private function getUserRoles(string $userId): array
    {
        return Cache::remember(
            'user_roles:' . $userId,
            $this->config->getRolesCacheTTL(),
            fn() => DB::table('user_roles')
                ->where('user_id', $userId)
                ->pluck('role_id')
                ->all()
        );
    }

    private function getEffectivePermissions(array $roles): array
    {
        $permissions = [];
        
        foreach ($roles as $roleId) {
            if (!isset($this->activePermissionCache[$roleId])) {
                $this->activePermissionCache[$roleId] = $this->loadRolePermissions($roleId);
            }
            $permissions = array_merge($permissions, $this->activePermissionCache[$roleId]);
        }
        
        return array_unique($permissions);
    }

    private function evaluateAccess(array $permissions, string $resource, string $action): bool
    {
        $required = $this->permissions->getRequiredPermissions($resource, $action);
        return empty(array_diff($required, $permissions));
    }

    private function logAccessAttempt(string $userId, string $resource, string $action, bool $decision): void
    {
        $this->audit->logSecurityEvent(new SecurityEvent(
            $decision ? 'access_granted' : 'access_denied',
            [
                'user_id' => $userId,
                'resource' => $resource,
                'action' => $action,
                'timestamp' => microtime(true)
            ]
        ));
    }

    private function generateAccessCacheKey(string $userId, string $resource, string $action): string
    {
        return "access_decision:{$userId}:{$resource}:{$action}";
    }

    private function getCachedDecision(string $key): ?bool
    {
        if ($this->config->isAccessCacheEnabled()) {
            return Cache::get($key);
        }
        return null;
    }

    private function cacheAccessDecision(string $key, bool $decision): void
    {
        if ($this->config->isAccessCacheEnabled()) {
            Cache::put($key, $decision, $this->config->getAccessCacheTTL());
        }
    }

    private function clearUserPermissionCache(string $userId): void
    {
        Cache::forget('user_roles:' . $userId);
        Cache::tags(['access_decisions'])->flush();
    }

    private function clearRolePermissionCache(string $roleId): void
    {
        unset($this->activePermissionCache[$roleId]);
        Cache::tags(['access_decisions', 'role_permissions'])->flush();
    }

    private function loadRolePermissions(string $roleId): array
    {
        return DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission')
            ->all();
    }

    private function updateMetrics(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->recordTiming('access_control.validation_time', $duration);
        $this->metrics->increment('access_control.validations_total');
    }
}
