<?php

namespace App\Core\Security;

use App\Core\Interfaces\AccessControlInterface;
use App\Core\Exceptions\{AuthenticationException, AuthorizationException};
use Illuminate\Support\Facades\{Hash, Cache};
use App\Models\{User, Role, Permission};

class AccessControlService implements AccessControlInterface
{
    private AuditService $auditService;
    private const MAX_LOGIN_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const CACHE_PREFIX = 'login_attempts:';
    
    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function authenticate(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedLogin($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($this->isLockedOut($credentials['email'])) {
            throw new AuthenticationException('Account is locked due to too many failed attempts');
        }

        $this->clearLoginAttempts($credentials['email']);
        $this->auditService->logSecurityEvent('login_success', ['user_id' => $user->id]);

        return $user;
    }

    public function authorize(User $user, string $permission): bool
    {
        try {
            if ($this->checkPermission($user, $permission)) {
                $this->auditService->logAccessEvent(
                    $permission,
                    'authorize',
                    true
                );
                return true;
            }

            $this->auditService->logAccessEvent(
                $permission,
                'authorize',
                false
            );
            
            return false;

        } catch (\Exception $e) {
            $this->auditService->logSecurityEvent('authorization_error', [
                'user_id' => $user->id,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            
            throw new AuthorizationException('Authorization check failed', 0, $e);
        }
    }

    public function checkPermission(User $user, string $permission): bool
    {
        // Check cache first
        $cacheKey = "user_permission:{$user->id}:{$permission}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Check actual permissions
        $hasPermission = $user->roles()
            ->whereHas('permissions', function($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();

        // Cache result
        Cache::put($cacheKey, $hasPermission, now()->addMinutes(60));

        return $hasPermission;
    }

    public function validateMfa(User $user, string $code): bool
    {
        try {
            $valid = $this->verifyMfaCode($user, $code);
            
            $this->auditService->logSecurityEvent(
                $valid ? 'mfa_success' : 'mfa_failure',
                ['user_id' => $user->id]
            );

            return $valid;

        } catch (\Exception