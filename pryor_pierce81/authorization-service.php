<?php

namespace App\Core\Security;

use App\Core\Exception\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

class AuthorizationService
{
    private LoggerInterface $logger;
    private array $permissions = [];
    private array $roleCache = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function hasPermission(User $user, string $operation, array $context = []): bool
    {
        $permissionId = $this->generatePermissionId($user, $operation, $context);

        try {
            if (isset($this->permissions[$permissionId])) {
                return $this->permissions[$permissionId];
            }

            $roles = $this->getUserRoles($user);
            $required = $this->getRequiredPermissions($operation);

            $hasPermission = $this->validatePermissions($roles, $required, $context);
            $this->permissions[$permissionId] = $hasPermission;

            $this->logPermissionCheck($user->getId(), $operation, $hasPermission);

            return $hasPermission;

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($user->getId(), $operation, $e);
            throw new AuthorizationException('Permission check failed', 0, $e);
        }
    }

    public function checkContentPermission(User $user, Content $content, array $context = []): bool
    {
        try {
            $operation = 'content:' . ($context['action'] ?? 'view');
            $extendedContext = array_merge($context, [
                'content_id' => $content->getId(),
                'content_type' => $content->type,
                'content_status' => $content->status
            ]);

            return $this->hasPermission($user, $operation, $extendedContext);

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($user->getId(), 'content_permission', $e);
            throw new AuthorizationException('Content permission check failed', 0, $e);
        }
    }

    public function validateContentPermissions(Content $content): bool
    {
        try {
            $permissions = $content->permissions;
            
            if (empty($permissions)) {
                return true;
            }

            foreach ($permissions as $permission) {
                if (!$this->isValidPermission($permission)) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure(null, 'validate_permissions', $e);
            throw new AuthorizationException('Permission validation failed', 0, $e);
        }
    }

    private function getUserRoles(User $user): array
    {
        $cacheKey = 'user_roles:' . $user->getId();

        return Cache::remember($cacheKey, 3600, function() use ($user) {
            return $user->roles()->with('permissions')->get()->toArray();
        });
    }

    private function getRequiredPermissions(string $operation): array
    {
        return config('permissions.' . $operation, []);
    }

    private function validatePermissions(array $roles, array $required, array $context): bool
    {
        foreach ($required as $permission) {
            if (!$this->hasRequiredPermission($roles, $permission, $context)) {
                return false;
            }
        }

        return true;
    }

    private function hasRequiredPermission(array $roles, string $permission, array $context): bool
    {
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission, $context)) {
                return true;
            }
        }

        return false;
    }

    private function roleHasPermission(array $role, string $permission, array $context): bool
    {
        foreach ($role['permissions'] as $perm) {
            if ($perm['name'] === $permission) {
                return $this->validatePermissionContext($perm, $context);
            }
        }

        return false;
    }

    private function validatePermissionContext(array $permission, array $context): bool
    {
        if (empty($permission['context_constraints'])) {
            return true;
        }

        foreach ($permission['context_constraints'] as $key => $value) {
            if (!isset($context[$key]) || $context[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function generatePermissionId(User $user, string $operation, array $context): string
    {
        return md5($user->getId() . $operation . serialize($context));
    }

    private function handleAuthorizationFailure(?int $userId, string $operation, \Exception $e): void
    {
        $this->logger->error('Authorization operation failed', [
            'user_id' => $userId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
