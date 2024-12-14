<?php

namespace App\Core\Security;

class AuthenticationService implements AuthenticationInterface 
{
    private TokenManager $tokenManager;
    private PasswordManager $passwordManager;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private RateLimiter $limiter;

    public function authenticate(array $credentials): AuthResult 
    {
        try {
            // Rate limiting check
            $this->limiter->checkLimit($credentials['ip']);

            // Validate credentials
            $this->validateCredentials($credentials);

            // Verify multi-factor if required
            if ($this->config->requiresMFA()) {
                $this->verifyMFA($credentials);
            }

            // Generate secure token
            $token = $this->tokenManager->generate(
                $credentials['user_id'],
                $this->getTokenMetadata($credentials)
            );

            // Log successful authentication
            $this->logger->logAuthentication(
                $credentials['user_id'],
                AuthEvent::SUCCESS
            );

            return new AuthResult(true, $token);

        } catch (AuthException $e) {
            $this->handleAuthFailure($e, $credentials);
            throw $e;
        }
    }

    private function validateCredentials(array $credentials): void 
    {
        if (!$this->passwordManager->verify(
            $credentials['password'],
            $credentials['stored_hash']
        )) {
            throw new InvalidCredentialsException();
        }

        if ($this->passwordManager->needsRehash($credentials['stored_hash'])) {
            $this->passwordManager->rehash($credentials['password']);
        }
    }

    private function verifyMFA(array $credentials): void 
    {
        if (!isset($credentials['mfa_token'])) {
            throw new MFARequiredException();
        }

        if (!$this->tokenManager->verifyMFA($credentials['mfa_token'])) {
            throw new InvalidMFAException();
        }
    }
}

class AuthorizationService implements AuthorizationInterface 
{
    private RoleManager $roleManager;
    private PermissionManager $permissionManager;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function authorize(int $userId, string $permission): bool 
    {
        $cacheKey = "auth.{$userId}.{$permission}";

        return $this->cache->remember($cacheKey, function() use ($userId, $permission) {
            try {
                // Get user roles
                $roles = $this->roleManager->getUserRoles($userId);

                // Check permission
                foreach ($roles as $role) {
                    if ($this->checkPermission($role, $permission)) {
                        return true;
                    }
                }

                // Log unauthorized attempt
                $this->logger->logUnauthorized($userId, $permission);
                return false;

            } catch (AuthException $e) {
                $this->handleAuthFailure($e, $userId, $permission);
                throw $e;
            }
        });
    }

    private function checkPermission(Role $role, string $permission): bool 
    {
        return $this->permissionManager->hasPermission($role, $permission);
    }
}

class RoleManager 
{
    private array $roleHierarchy;
    private CacheManager $cache;
    private DB $database;

    public function getUserRoles(int $userId): array 
    {
        return $this->cache->remember("user.{$userId}.roles", function() use ($userId) {
            return $this->database->table('user_roles')
                ->where('user_id', $userId)
                ->with('role.permissions')
                ->get();
        });
    }

    public function hasRole(int $userId, string $role): bool 
    {
        $userRoles = $this->getUserRoles($userId);
        
        foreach ($userRoles as $userRole) {
            if ($this->checkRoleHierarchy($userRole->name, $role)) {
                return true;
            }
        }
        
        return false;
    }

    private function checkRoleHierarchy(string $userRole, string $requiredRole): bool 
    {
        return isset($this->roleHierarchy[$userRole]) &&
            in_array($requiredRole, $this->roleHierarchy[$userRole]);
    }
}

class TokenManager 
{
    private string $secretKey;
    private int $expiration;

    public function generate(int $userId, array $metadata): string 
    {
        $payload = [
            'user_id' => $userId,
            'metadata' => $metadata,
            'exp' => time() + $this->expiration,
            'jti' => $this->generateUniqueId()
        ];

        return JWT::encode($payload, $this->secretKey);
    }

    public function verify(string $token): bool 
    {
        try {
            $payload = JWT::decode($token, $this->secretKey);
            return !$this->isExpired($payload) && !$this->isRevoked($payload->jti);
        } catch (JWTException $e) {
            throw new InvalidTokenException();
        }
    }

    public function revoke(string $token): void 
    {
        $payload = JWT::decode($token, $this->secretKey);
        $this->addToBlacklist($payload->jti);
    }

    private function isExpired(object $payload): bool 
    {
        return $payload->exp < time();
    }

    private function isRevoked(string $jti): bool 
    {
        return Cache::has("revoked_token.{$jti}");
    }

    private function addToBlacklist(string $jti): void 
    {
        Cache::put("revoked_token.{$jti}", true, now()->addDays(7));
    }
}
