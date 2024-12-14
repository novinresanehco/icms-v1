<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\Core\Security\SecurityContext;
use App\Core\Security\EncryptionService;
use App\Core\Logging\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Security\TokenManager;

class AuthenticationService implements AuthenticationInterface
{
    private const TOKEN_LIFETIME = 900; // 15 minutes
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes

    private UserRepository $users;
    private TokenManager $tokenManager;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    public function __construct(
        UserRepository $users,
        TokenManager $tokenManager,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->users = $users;
        $this->tokenManager = $tokenManager;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials, array $mfaToken = null): AuthResult
    {
        try {
            // Check for account lockout
            $this->checkLockout($credentials['email']);

            // Validate primary credentials
            $user = $this->validateCredentials($credentials);

            // Enforce MFA if enabled
            if ($user->hasMfaEnabled()) {
                $this->validateMfaToken($user, $mfaToken);
            }

            // Generate auth token and session
            $token = $this->generateAuthToken($user);
            $session = $this->establishSession($user, $token);

            // Log successful authentication
            $this->auditLogger->logAuthentication($user->id, true);

            // Reset failed attempts
            $this->resetFailedAttempts($credentials['email']);

            return new AuthResult(true, $token, $session);

        } catch (AuthenticationException $e) {
            $this->handleFailedAttempt($credentials['email']);
            $this->auditLogger->logAuthentication($credentials['email'], false, $e->getMessage());
            throw $e;
        }
    }

    public function validateSession(string $token): SecurityContext
    {
        $session = $this->tokenManager->validateToken($token);
        
        if (!$session || $session->isExpired()) {
            throw new SessionExpiredException('Invalid or expired session');
        }

        $user = $this->users->find($session->getUserId());
        if (!$user) {
            throw new AuthenticationException('User not found');
        }

        // Extend session if needed
        if ($session->shouldExtend()) {
            $session = $this->tokenManager->extendSession($session);
        }

        return new SecurityContext($user, $session);
    }

    public function logout(string $token): void
    {
        try {
            $session = $this->tokenManager->validateToken($token);
            if ($session) {
                $this->tokenManager->revokeToken($token);
                $this->auditLogger->logLogout($session->getUserId());
            }
        } catch (\Exception $e) {
            $this->auditLogger->logError('Logout failed', ['token' => $token, 'error' => $e->getMessage()]);
        }
    }

    private function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new AuthenticationException('Account is inactive');
        }

        return $user;
    }

    private function validateMfaToken(User $user, ?array $mfaToken): void
    {
        if (!$mfaToken) {
            throw new MfaRequiredException('MFA token required');
        }

        if (!$this->tokenManager->validateMfaToken($user->id, $mfaToken['code'])) {
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    private function generateAuthToken(User $user): string
    {
        return $this->tokenManager->createToken([
            'user_id' => $user->id,
            'roles' => $user->getRoles(),
            'permissions' => $user->getPermissions(),
        ], self::TOKEN_LIFETIME);
    }

    private function establishSession(User $user, string $token): Session
    {
        return $this->tokenManager->createSession(
            $user->id,
            $token,
            $this->getSessionMetadata()
        );
    }

    private function checkLockout(string $email): void
    {
        $attempts = $this->getFailedAttempts($email);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockoutTime = $this->getLockoutTime($email);
            if ($lockoutTime > time()) {
                throw new AccountLockedException('Account is locked due to too many failed attempts');
            }
            // Reset attempts after lockout period
            $this->resetFailedAttempts($email);
        }
    }

    private function handleFailedAttempt(string $email): void
    {
        $attempts = $this->incrementFailedAttempts($email);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->setLockoutTime($email);
        }
    }

    private function getSessionMetadata(): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => time()
        ];
    }
}
