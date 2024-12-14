<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\{MfaManager, SessionManager, RoleManager};
use App\Core\Contracts\{AuthenticationInterface, AuditLoggerInterface};

class AuthenticationSystem implements AuthenticationInterface
{
    private MfaManager $mfaManager;
    private SessionManager $sessionManager;
    private RoleManager $roleManager;
    private AuditLoggerInterface $auditLogger;
    
    public function __construct(
        MfaManager $mfaManager,
        SessionManager $sessionManager,
        RoleManager $roleManager,
        AuditLoggerInterface $auditLogger
    ) {
        $this->mfaManager = $mfaManager;
        $this->sessionManager = $sessionManager;
        $this->roleManager = $roleManager;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            // Primary authentication
            $user = $this->validateCredentials($credentials);
            if (!$user) {
                throw new AuthenticationException('Invalid credentials');
            }

            // MFA validation
            if (!$this->mfaManager->validate($user, $credentials['mfa_token'] ?? null)) {
                throw new MfaRequiredException('MFA validation required');
            }

            // Session creation with strict security params
            $session = $this->sessionManager->create($user, [
                'expire' => config('auth.session_lifetime'),
                'ip' => request()->ip(),
                'device' => request()->userAgent(),
                'secure' => true,
                'http_only' => true
            ]);

            // Role and permission loading
            $this->roleManager->loadUserPermissions($user);

            DB::commit();
            
            $this->auditLogger->logAuthentication($user, true);
            
            return new AuthResult($user, $session);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logAuthentication(
                $credentials['email'] ?? 'unknown',
                false,
                $e->getMessage()
            );
            throw $e;
        }
    }

    public function validateSession(string $token): SessionValidationResult
    {
        try {
            $session = $this->sessionManager->validate($token);
            
            if (!$session->isValid()) {
                throw new InvalidSessionException('Session expired or invalid');
            }

            // Verify IP and user agent haven't changed
            if (!$this->verifySessionContext($session)) {
                throw new SecurityException('Session context mismatch');
            }

            // Rotate session token if needed
            if ($session->needsRotation()) {
                $session = $this->sessionManager->rotate($session);
            }

            return new SessionValidationResult($session, true);

        } catch (\Exception $e) {
            $this->auditLogger->logSessionValidation($token, false, $e->getMessage());
            throw $e;
        }
    }

    public function checkPermission(User $user, string $permission): bool
    {
        try {
            $hasPermission = $this->roleManager->checkPermission($user, $permission);
            $this->auditLogger->logPermissionCheck($user, $permission, $hasPermission);
            return $hasPermission;
        } catch (\Exception $e) {
            $this->auditLogger->logError('permission_check', $e->getMessage());
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        try {
            $session = $this->sessionManager->invalidate($token);
            $this->auditLogger->logLogout($session->user);
        } catch (\Exception $e) {
            $this->auditLogger->logError('logout', $e->getMessage());
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        // Check for account lockout
        if ($this->isAccountLocked($user)) {
            throw new AccountLockedException('Account temporarily locked');
        }

        // Reset failed attempts on successful validation
        Cache::forget("login_attempts:{$user->id}");

        return $user;
    }

    protected function isAccountLocked(User $user): bool
    {
        $attempts = Cache::get("login_attempts:{$user->id}", 0);
        
        if ($attempts >= config('auth.max_attempts')) {
            return true;
        }

        Cache::increment("login_attempts:{$user->id}");
        Cache::expire("login_attempts:{$user->id}", now()->addMinutes(30));
        
        return false;
    }

    protected function verifySessionContext(Session $session): bool
    {
        return $session->ip === request()->ip() && 
               $session->user_agent === request()->userAgent();
    }
}
