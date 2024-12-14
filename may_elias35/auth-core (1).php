<?php

namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private TokenManager $tokenManager;
    private ValidationService $validator;
    private SecurityManager $security;
    private SessionManager $sessions;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function __construct(
        TokenManager $tokenManager,
        ValidationService $validator,
        SecurityManager $security,
        SessionManager $sessions,
        AuditLogger $logger,
        CacheManager $cache
    ) {
        $this->tokenManager = $tokenManager;
        $this->validator = $validator;
        $this->security = $security;
        $this->sessions = $sessions;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $this->validator->validateCredentials($credentials);
            $this->checkFailureThreshold($credentials['username']);
            
            $user = $this->verifyCredentials($credentials);
            $this->validateUserStatus($user);
            
            if ($this->requiresMFA($user)) {
                return $this->initiateMultiFactorAuth($user);
            }
            
            $token = $this->createSession($user);
            $this->logger->logSuccessfulAuth($user);
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            $this->handleAuthFailure($credentials['username'], $e);
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    public function verifyMFA(string $userId, string $code): AuthResult
    {
        try {
            $user = $this->security->getUser($userId);
            $this->validateMFACode($user, $code);
            
            $token = $this->createSession($user);
            $this->logger->logSuccessfulMFA($user);
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            $this->handleMFAFailure($userId, $e);
            throw new MFAException('MFA verification failed', 0, $e);
        }
    }

    public function validateSession(string $token): SessionResult
    {
        try {
            $session = $this->sessions->validate($token);
            $this->validateSessionSecurity($session);
            
            $this->sessions->refresh($session);
            $this->logger->logSessionValidation($session);
            
            return new SessionResult($session);
            
        } catch (\Exception $e) {
            $this->handleSessionFailure($token, $e);
            throw new SessionException('Session validation failed', 0, $e);
        }
    }

    private function verifyCredentials(array $credentials): User
    {
        $user = $this->security->verifyUser(
            $credentials['username'],
            $credentials['password']
        );
        
        if (!$user) {
            throw new InvalidCredentialsException('Invalid credentials');
        }
        
        return $user;
    }

    private function validateUserStatus(User $user): void
    {
        if (!$user->isActive()) {
            throw new InactiveUserException('User account is inactive');
        }
        
        if ($user->isLocked()) {
            throw new LockedUserException('User account is locked');
        }
        
        if ($user->requiresPasswordChange()) {
            throw new PasswordChangeRequiredException('Password change required');
        }
    }

    private function createSession(User $user): string
    {
        $sessionData = [
            'user_id' => $user->getId(),
            'roles' => $user->getRoles(),
            'permissions' => $user->getPermissions(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
        
        $token = $this->tokenManager->createToken($sessionData);
        $this->sessions->create($token, $sessionData);
        
        return $token;
    }

    private function validateSessionSecurity(Session $session): void
    {
        if (!$this->security->validateIPAddress($session)) {
            throw new SecurityException('IP address mismatch');
        }
        
        if (!$this->security->validateUserAgent($session)) {
            throw new SecurityException('User agent mismatch');
        }
    }

    private function checkFailureThreshold(string $username): void
    {
        $failures = $this->cache->get("auth_failures:{$username}") ?? 0;
        
        if ($failures >= config('auth.max_failures')) {
            $this->logger->logExcessiveFailures($username);
            throw new AccountLockedException('Account temporarily locked');
        }
    }

    private function handleAuthFailure(string $username, \Exception $e): void
    {
        $failures = $this->cache->increment("auth_failures:{$username}");
        $this->logger->logAuthFailure($username, $e);
        
        if ($failures >= config('auth.max_failures')) {
            $this->security->lockAccount($username);
            $this->logger->logAccountLock($username);
        }
    }

    private function validateMFACode(User $user, string $code): void
    {
        if (!$this->security->verifyMFACode($user, $code)) {
            throw new InvalidMFACodeException('Invalid MFA code');
        }
    }

    private function handleSessionFailure(string $token, \Exception $e): void
    {
        $this->sessions->invalidate($token);
        $this->logger->logSessionFailure($token, $e);
        
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($token, $e);
        }
    }
}
