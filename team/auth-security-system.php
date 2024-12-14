<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{AuthException, SecurityException};

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private CacheManager $cache;
    private TokenManager $tokens;
    private UserRepository $users;
    private AuditLogger $audit;

    public function authenticate(array $credentials, SecurityContext $context): AuthResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($credentials, $context) {
                $validated = $this->validateCredentials($credentials);
                $user = $this->verifyUser($validated);
                
                if (!$this->verifyMFA($user, $validated)) {
                    throw new AuthException('MFA verification failed');
                }

                $token = $this->createSecureToken($user, $context);
                $this->auditSuccessfulLogin($user, $context);
                
                return new AuthResult($user, $token);
            },
            $context
        );
    }

    public function verify(string $token, SecurityContext $context): AuthResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($token, $context) {
                $decoded = $this->tokens->verify($token);
                $user = $this->users->findById($decoded['user_id']);
                
                if (!$this->verifySession($user, $decoded)) {
                    throw new AuthException('Session verification failed');
                }

                $this->auditTokenVerification($user, $context);
                return new AuthResult($user, $token);
            },
            $context
        );
    }

    public function authorize(User $user, string $permission, SecurityContext $context): bool
    {
        return $this->protection->executeProtectedOperation(
            function() use ($user, $permission, $context) {
                return $this->cache->remember(
                    "auth.permission.{$user->id}.{$permission}",
                    function() use ($user, $permission) {
                        return $this->checkPermission($user, $permission);
                    }
                );
            },
            $context
        );
    }

    public function logout(string $token, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($token, $context) {
                $decoded = $this->tokens->verify($token);
                $this->invalidateToken($token);
                $this->clearUserSessions($decoded['user_id']);
                $this->auditLogout($decoded['user_id'], $context);
            },
            $context
        );
    }

    private function validateCredentials(array $credentials): array
    {
        $rules = [
            'username' => 'required|string|max:100',
            'password' => 'required|string|min:12',
            'mfa_token' => 'required|string|size:6'
        ];

        return $this->validator->validate($credentials, $rules);
    }

    private function verifyUser(array $credentials): User
    {
        $user = $this->users->findByUsername($credentials['username']);
        
        if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
            $this->auditFailedLogin($credentials);
            throw new AuthException('Invalid credentials');
        }

        if ($user->isLocked() || $user->isDisabled()) {
            $this->auditBlockedAccess($user);
            throw new AuthException('Account access denied');
        }

        return $user;
    }

    private function verifyMFA(User $user, array $credentials): bool
    {
        $mfaService = $this->getMFAService($user->getMFAType());
        
        if (!$mfaService->verify($user, $credentials['mfa_token'])) {
            $this->auditFailedMFA($user);
            throw new AuthException('Invalid MFA token');
        }

        return true;
    }

    private function createSecureToken(User $user, SecurityContext $context): string
    {
        $token = $this->tokens->create([
            'user_id' => $user->id,
            'context' => $context->toArray(),
            'permissions' => $user->getPermissions(),
            'expires_at' => now()->addMinutes(config('auth.token_ttl'))
        ]);

        $this->cacheUserToken($user->id, $token);
        return $token;
    }

    private function verifySession(User $user, array $tokenData): bool
    {
        if ($user->getSessionVersion() !== $tokenData['session_version']) {
            throw new SecurityException('Session invalidated');
        }

        if (!$this->verifyDeviceFingerprint($tokenData['fingerprint'])) {
            $this->auditSuspiciousAccess($user, $tokenData);
            throw new SecurityException('Invalid device fingerprint');
        }

        return true;
    }

    private function checkPermission(User $user, string $permission): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        
        $this->auditDeniedAccess($user, $permission);
        return false;
    }

    private function invalidateToken(string $token): void
    {
        $this->tokens->revoke($token);
        $this->cache->tags(['auth.tokens'])->forget($token);
    }
}
