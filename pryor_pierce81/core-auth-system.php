<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{AuthException, ValidationException};
use Illuminate\Support\Facades\{DB, Hash};

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManager $security;
    private TokenManager $tokenManager;
    private MFAProvider $mfaProvider;
    private AuditLogger $logger;
    private UserRepository $users;

    public function __construct(
        SecurityManager $security,
        TokenManager $tokenManager,
        MFAProvider $mfaProvider,
        AuditLogger $logger,
        UserRepository $users
    ) {
        $this->security = $security;
        $this->tokenManager = $tokenManager;
        $this->mfaProvider = $mfaProvider;
        $this->logger = $logger;
        $this->users = $users;
    }

    public function authenticate(array $credentials, array $mfaData = null): AuthResult 
    {
        return DB::transaction(function() use ($credentials, $mfaData) {
            $user = $this->validateCredentials($credentials);
            
            if ($this->mfaProvider->isRequired($user)) {
                if (!$mfaData) {
                    throw new MFARequiredException($user->getId());
                }
                $this->validateMFA($user, $mfaData);
            }

            $session = $this->createSession($user);
            $token = $this->tokenManager->issueToken($user, $session);

            $this->logger->logSuccessfulAuth($user, $session);

            return new AuthResult($user, $token, $session);
        });
    }

    public function validateSession(string $token): SessionInfo 
    {
        $session = $this->tokenManager->validateToken($token);
        
        if ($session->isExpired()) {
            $this->logger->logSessionExpired($session);
            throw new SessionExpiredException();
        }

        if ($this->security->detectSessionAnomaly($session)) {
            $this->logger->logSessionAnomaly($session);
            throw new SecurityException('Session anomaly detected');
        }

        $session->extend();
        return $session;
    }

    public function logout(string $token): void 
    {
        $session = $this->tokenManager->validateToken($token);
        $this->tokenManager->revokeToken($token);
        $this->logger->logLogout($session->getUser());
    }

    private function validateCredentials(array $credentials): User 
    {
        $user = $this->users->findByUsername($credentials['username']);
        
        if (!$user || !Hash::check($credentials['password'], $user->getPassword())) {
            $this->logger->logFailedAuth($credentials['username']);
            throw new AuthException('Invalid credentials');
        }

        if ($user->isLocked()) {
            $this->logger->logLockedAccess($user);
            throw new AccountLockedException();
        }

        return $user;
    }

    private function validateMFA(User $user, array $mfaData): void 
    {
        if (!$this->mfaProvider->verify($user, $mfaData)) {
            $this->logger->logFailedMFA($user);
            throw new MFAValidationException();
        }
    }

    private function createSession(User $user): Session 
    {
        return DB::transaction(function() use ($user) {
            $this->invalidateOldSessions($user);
            
            $session = new Session([
                'user_id' => $user->getId(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'expires_at' => now()->addMinutes(config('auth.session_lifetime'))
            ]);

            $this->sessions->save($session);
            return $session;
        });
    }
}

class AuthorizationManager implements AuthorizationInterface 
{
    private RoleRepository $roles;
    private PermissionCache $cache;
    private AuditLogger $logger;

    public function __construct(
        RoleRepository $roles,
        PermissionCache $cache,
        AuditLogger $logger
    ) {
        $this->roles = $roles;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function authorize(User $user, string $permission, $resource = null): bool 
    {
        $cacheKey = $this->buildCacheKey($user, $permission, $resource);
        
        return $this->cache->remember($cacheKey, function() use ($user, $permission, $resource) {
            $roles = $this->roles->getUserRoles($user);
            
            foreach ($roles as $role) {
                if ($this->checkPermission($role, $permission, $resource)) {
                    $this->logger->logSuccessfulAuth($user, $permission);
                    return true;
                }
            }

            $this->logger->logFailedAuth($user, $permission);
            return false;
        });
    }

    public function authorizeAll(User $user, array $permissions, $resource = null): bool 
    {
        foreach ($permissions as $permission) {
            if (!$this->authorize($user, $permission, $resource)) {
                return false;
            }
        }
        return true;
    }

    public function authorizeAny(User $user, array $permissions, $resource = null): bool 
    {
        foreach ($permissions as $permission) {
            if ($this->authorize($user, $permission, $resource)) {
                return true;
            }
        }
        return false;
    }

    private function checkPermission(Role $role, string $permission, $resource = null): bool 
    {
        if (!$role->hasPermission($permission)) {
            return false;
        }

        if ($resource && !$this->validateResourceAccess($role, $permission, $resource)) {
            return false;
        }

        return true;
    }

    private function validateResourceAccess(Role $role, string $permission, $resource): bool 
    {
        $constraints = $role->getPermissionConstraints($permission);
        
        foreach ($constraints as $constraint) {
            if (!$constraint->validate($resource)) {
                return false;
            }
        }

        return true;
    }

    private function buildCacheKey(User $user, string $permission, $resource = null): string 
    {
        $key = "auth.{$user->getId()}.{$permission}";
        
        if ($resource) {
            $key .= '.' . $this->getResourceIdentifier($resource);
        }
        
        return $key;
    }

    private function getResourceIdentifier($resource): string 
    {
        if (is_object($resource)) {
            return get_class($resource) . ':' . $resource->getId();
        }
        return (string) $resource;
    }
}
