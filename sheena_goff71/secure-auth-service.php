<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use App\Core\Exceptions\SecurityException;
use App\Core\Interfaces\AuthServiceInterface;

class AuthenticationService implements AuthServiceInterface 
{
    private const TOKEN_LENGTH = 64;
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    
    protected TokenManager $tokenManager;
    protected AuditLogger $auditLogger;
    protected CacheManager $cache;
    
    public function __construct(
        TokenManager $tokenManager,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->tokenManager = $tokenManager;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult
    {
        $this->validateCredentials($credentials);
        
        if ($this->isLocked($credentials['username'])) {
            throw new SecurityException('Account temporarily locked');
        }

        try {
            DB::beginTransaction();
            
            $user = $this->validateUser($credentials);
            $token = $this->generateSecureToken($user);
            
            $this->recordSuccessfulLogin($user);
            $this->resetFailedAttempts($credentials['username']);
            
            DB::commit();
            
            return new AuthResult([
                'token' => $token,
                'user' => $user,
                'expires' => $this->getTokenExpiry()
            ]);
            
        } catch (SecurityException $e) {
            DB::rollBack();
            $this->handleFailedAttempt($credentials['username']);
            throw $e;
        }
    }

    public function validateToken(string $token): bool
    {
        try {
            $tokenData = $this->tokenManager->decode($token);
            
            if (!$this->isTokenValid($tokenData)) {
                throw new SecurityException('Invalid token');
            }

            if ($this->isTokenRevoked($tokenData->jti)) {
                throw new SecurityException('Token revoked');
            }

            return true;
            
        } catch (\Exception $e) {
            $this->auditLogger->logSecurityEvent('token_validation_failed', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        $required = ['username', 'password'];
        
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                throw new SecurityException("Missing required field: {$field}");
            }
        }
    }

    protected function validateUser(array $credentials): User
    {
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new SecurityException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new SecurityException('Account disabled');
        }

        return $user;
    }

    protected function generateSecureToken(User $user): string
    {
        $tokenData = [
            'uid' => $user->id,
            'jti' => Str::random(self::TOKEN_LENGTH),
            'iat' => time(),
            'exp' => $this->getTokenExpiry()
        ];

        return $this->tokenManager->encode($tokenData);
    }

    protected function isTokenValid(object $tokenData): bool
    {
        return $tokenData->exp > time() &&
               $this->validateTokenUser($tokenData->uid);
    }

    protected function validateTokenUser(int $userId): bool
    {
        $user = User::find($userId);
        return $user && $user->isActive();
    }

    protected function isTokenRevoked(string $tokenId): bool
    {
        return $this->cache->has("revoked_token:{$tokenId}");
    }

    protected function handleFailedAttempt(string $username): void
    {
        $attempts = $this->incrementFailedAttempts($username);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->lockAccount($username);
        }
        
        $this->auditLogger->logSecurityEvent('login_failed', [
            'username' => $username,
            'attempts' => $attempts
        ]);
    }

    protected function incrementFailedAttempts(string $username): int
    {
        $key = "login_attempts:{$username}";
        $attempts = $this->cache->increment($key, 1, self::LOCKOUT_TIME);
        
        if ($attempts === 1) {
            $this->cache->put($key, 1, self::LOCKOUT_TIME);
        }
        
        return $attempts;
    }

    protected function lockAccount(string $username): void
    {
        $this->cache->put(
            "account_locked:{$username}", 
            true, 
            self::LOCKOUT_TIME
        );
        
        $this->auditLogger->logSecurityEvent('account_locked', [
            'username' => $username,
            'duration' => self::LOCKOUT_TIME
        ]);
    }

    protected function isLocked(string $username): bool
    {
        return $this->cache->has("account_locked:{$username}");
    }

    protected function resetFailedAttempts(string $username): void
    {
        $this->cache->forget("login_attempts:{$username}");
        $this->cache->forget("account_locked:{$username}");
    }

    protected function recordSuccessfulLogin(User $user): void
    {
        $user->last_login = now();
        $user->save();
        
        $this->auditLogger->logSecurityEvent('login_success', [
            'user_id' => $user->id,
            'username' => $user->username
        ]);
    }

    protected function getTokenExpiry(): int
    {
        return time() + config('auth.token_lifetime', 3600);
    }
}
