<?php

namespace App\Core\Security;

use App\Core\Contracts\{AuthorizationInterface, UserRepositoryInterface};
use App\Core\Services\{AuditLogger, CacheManager};
use App\Core\Exceptions\{AuthorizationException, SecurityException};
use Illuminate\Support\Facades\{DB, Hash};

class AuthorizationManager implements AuthorizationInterface
{
    private UserRepositoryInterface $userRepository;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    
    private const MAX_LOGIN_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    
    public function __construct(
        UserRepositoryInterface $userRepository,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->userRepository = $userRepository;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        try {
            $this->validateLoginAttempts($credentials['username']);
            
            $user = $this->userRepository->findByUsername($credentials['username']);
            if (!$user || !$this->verifyCredentials($user, $credentials)) {
                $this->handleFailedLogin($credentials['username']);
                throw new AuthorizationException('Invalid credentials');
            }

            $token = $this->generateSecureToken($user);
            $this->updateUserSecurity($user, $token);
            
            DB::commit();
            $this->auditLogger->logSuccessfulLogin($user);
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedLogin($credentials['username'], $e);
            throw $e;
        }
    }

    public function authorize(int $userId, string $permission): bool 
    {
        return $this->cache->remember("user.{$userId}.perm.{$permission}", function() use ($userId, $permission) {
            $user = $this->userRepository->findWithRoles($userId);
            if (!$user) {
                throw new SecurityException('User not found');
            }

            foreach ($user->roles as $role) {
                if ($this->roleHasPermission($role, $permission)) {
                    return true;
                }
            }
            
            return false;
        });
    }

    public function validateSession(string $token): SessionValidation 
    {
        $session = $this->cache->get("session.$token");
        if (!$session) {
            throw new SecurityException('Invalid session');
        }

        if ($this->isSessionExpired($session)) {
            $this->invalidateSession($token);
            throw new SecurityException('Session expired');
        }

        return new SessionValidation($session->userId, $session->permissions);
    }

    public function invalidateAllUserSessions(int $userId): void 
    {
        DB::transaction(function() use ($userId) {
            $this->userRepository->updateSecurityStamp($userId);
            $this->cache->invalidateUserSessions($userId);
            $this->auditLogger->logSessionInvalidation($userId);
        });
    }

    private function validateLoginAttempts(string $username): void 
    {
        $attempts = $this->cache->get("login.attempts.$username") ?? 0;
        
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $lockoutExpiry = $this->cache->get("login.lockout.$username");
            if ($lockoutExpiry && time() < $lockoutExpiry) {
                throw new SecurityException('Account temporarily locked');
            }
            $this->cache->delete("login.attempts.$username");
        }
    }

    private function handleFailedLogin(string $username): void 
    {
        $attempts = $this->cache->increment("login.attempts.$username");
        
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->cache->set(
                "login.lockout.$username",
                time() + self::LOCKOUT_TIME,
                self::LOCKOUT_TIME
            );
        }
    }

    private function verifyCredentials(User $user, array $credentials): bool 
    {
        if (!Hash::check($credentials['password'], $user->password)) {
            return false;
        }

        if (!empty($credentials['mfa_code'])) {
            return $this->verifyMfaCode($user, $credentials['mfa_code']);
        }

        return true;
    }

    private function generateSecureToken(User $user): string 
    {
        $token = bin2hex(random_bytes(32));
        $this->cache->set(
            "session.$token",
            [
                'userId' => $user->id,
                'created' => time(),
                'securityStamp' => $user->securityStamp,
                'permissions' => $this->getUserPermissions($user)
            ],
            3600
        );
        return $token;
    }

    private function updateUserSecurity(User $user, string $token): void 
    {
        $this->userRepository->updateLastLogin($user->id);
        $this->cache->set("user.{$user->id}.activeToken", $token, 3600);
    }

    private function roleHasPermission(Role $role, string $permission): bool 
    {
        return $this->cache->remember(
            "role.{$role->id}.perm.$permission",
            function() use ($role, $permission) {
                return $role->permissions->contains('name', $permission);
            }
        );
    }

    private function verifyMfaCode(User $user, string $code): bool 
    {
        return $this->userRepository->verifyMfaCode($user->id, $code);
    }

    private function isSessionExpired(object $session): bool 
    {
        $user = $this->userRepository->find($session->userId);
        if ($user->securityStamp !== $session->securityStamp) {
            return true;
        }
        
        return (time() - $session->created) > 3600;
    }

    private function getUserPermissions(User $user): array 
    {
        return $this->cache->remember(
            "user.{$user->id}.permissions",
            function() use ($user) {
                return $this->userRepository->getUserPermissions($user->id);
            }
        );
    }
}
