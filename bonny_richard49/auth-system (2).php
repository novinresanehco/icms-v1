<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Audit\AuditLoggerInterface;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private AuditLoggerInterface $audit;
    private TokenManager $tokenManager;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        AuditLoggerInterface $audit,
        TokenManager $tokenManager
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->tokenManager = $tokenManager;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            // Validate credentials format
            $this->validateCredentials($credentials);
            
            // Rate limiting check
            $this->checkRateLimit($credentials['ip_address']);

            // Verify primary authentication
            $user = $this->verifyPrimaryAuth($credentials);
            
            // Verify 2FA if enabled
            if ($user->hasTwoFactorEnabled()) {
                $this->verifyTwoFactor($user, $credentials['two_factor_code']);
            }

            // Generate tokens
            $tokens = $this->tokenManager->generateTokenPair($user);
            
            // Log successful authentication
            $this->audit->logAuthentication($user, 'success');
            
            return new AuthResult($user, $tokens, true);

        } catch (\Exception $e) {
            $this->handleAuthFailure($credentials, $e);
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        $required = ['email', 'password', 'ip_address'];
        foreach ($required as $field) {
            if (!isset($credentials[$field])) {
                throw new AuthenticationException("Missing required field: {$field}");
            }
        }
    }

    protected function checkRateLimit(string $ipAddress): void
    {
        $attempts = $this->cache->increment("auth_attempts:{$ipAddress}");
        
        if ($attempts > 5) { // Max 5 attempts per 15 minutes
            $this->audit->logSecurityEvent('rate_limit_exceeded', ['ip' => $ipAddress]);
            throw new RateLimitException('Too many authentication attempts');
        }
    }

    protected function verifyPrimaryAuth(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isLocked()) {
            throw new AccountLockedException('Account is locked');
        }

        return $user;
    }

    protected function verifyTwoFactor(User $user, ?string $code): void
    {
        if (!$code || !$this->tokenManager->verifyTwoFactorCode($user, $code)) {
            throw new TwoFactorException('Invalid two-factor code');
        }
    }

    protected function handleAuthFailure(array $credentials, \Exception $e): void
    {
        // Log the failure
        $this->audit->logAuthentication([
            'email' => $credentials['email'],
            'ip' => $credentials['ip_address']
        ], 'failure', [
            'reason' => $e->getMessage(),
            'exception' => get_class($e)
        ]);

        // Increment failed attempts for the account if it exists
        if ($user = User::where('email', $credentials['email'])->first()) {
            $this->handleFailedAttempt($user);
        }
    }

    protected function handleFailedAttempt(User $user): void
    {
        $attempts = $this->cache->increment("failed_attempts:{$user->id}");
        
        if ($attempts >= 5) { // Lock account after 5 failed attempts
            $user->lock();
            $this->audit->logSecurityEvent('account_locked', [
                'user_id' => $user->id,
                'reason' => 'exceeded_failed_attempts'
            ]);
        }
    }

    public function validateSession(string $token): bool
    {
        try {
            $session = $this->tokenManager->verifyToken($token);
            
            if ($this->isSessionValid($session)) {
                // Extend session if needed
                $this->extendSession($session);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            $this->audit->logSecurityEvent('invalid_session', [
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function isSessionValid(Session $session): bool
    {
        return !$session->isExpired() && 
               !$session->isRevoked() && 
               $this->validateSessionConstraints($session);
    }

    protected function validateSessionConstraints(Session $session): bool
    {
        // Validate IP hasn't changed dramatically (basic location check)
        if (!$this->security->validateIPChange($session)) {
            return false;
        }

        // Validate user account is still active
        if (!$session->user->isActive()) {
            return false;
        }

        // Validate security policies haven't changed
        return $this->security->validateSecurityPolicies($session);
    }

    protected function extendSession(Session $session): void
    {
        if ($session->shouldExtend()) {
            $this->tokenManager->extendSession($session);
        }
    }
}
