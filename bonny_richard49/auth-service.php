<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\Encryption\EncryptionService;
use App\Core\Auth\Events\{LoginAttemptEvent, LoginSuccessEvent, LoginFailureEvent};
use App\Core\Auth\Exceptions\{AuthenticationException, TwoFactorRequiredException};

/**
 * Critical Authentication Service
 * Handles all authentication operations with comprehensive security controls
 */
class AuthenticationService implements AuthenticationInterface
{
    private EncryptionService $encryption;
    private TokenManager $tokenManager;
    private TwoFactorManager $twoFactor;
    private SessionManager $sessions;
    private AuditLogger $auditLogger;

    public function __construct(
        EncryptionService $encryption,
        TokenManager $tokenManager,
        TwoFactorManager $twoFactor,
        SessionManager $sessions,
        AuditLogger $auditLogger
    ) {
        $this->encryption = $encryption;
        $this->tokenManager = $tokenManager;
        $this->twoFactor = $twoFactor;
        $this->sessions = $sessions;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Authenticate user with multi-factor verification
     * 
     * @throws AuthenticationException
     * @throws TwoFactorRequiredException
     */
    public function authenticate(array $credentials): AuthResult
    {
        // Start transaction for atomic authentication
        DB::beginTransaction();
        
        try {
            // Log authentication attempt
            $this->auditLogger->logAttempt($credentials['username']);
            
            // Validate credentials
            $user = $this->validateCredentials($credentials);
            
            // Check for brute force attempts
            $this->checkBruteForceProtection($credentials['username']);
            
            // Verify two-factor if enabled
            if ($user->hasTwoFactorEnabled()) {
                $this->verifyTwoFactor($user, $credentials);
            }
            
            // Generate secure token and session
            $token = $this->tokenManager->generateSecureToken($user);
            $session = $this->sessions->createSecureSession($user, $token);
            
            // Log successful authentication
            $this->auditLogger->logSuccess($user);
            
            DB::commit();
            
            return new AuthResult($user, $token, $session);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failure
            $this->auditLogger->logFailure(
                $credentials['username'],
                $e->getMessage()
            );
            
            throw new AuthenticationException(
                'Authentication failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Validate user credentials with rate limiting
     */
    private function validateCredentials(array $credentials): User
    {
        // Apply rate limiting
        $this->applyRateLimit($credentials['username']);
        
        // Find and validate user
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
            throw new AuthenticationException('Invalid credentials');
        }
        
        return $user;
    }

    /**
     * Verify two-factor authentication
     */
    private function verifyTwoFactor(User $user, array $credentials): void
    {
        if (!isset($credentials['two_factor_token'])) {
            throw new TwoFactorRequiredException($user);
        }

        if (!$this->twoFactor->verify($user, $credentials['two_factor_token'])) {
            throw new AuthenticationException('Invalid two-factor token');
        }
    }

    /**
     * Check for brute force attempts
     */
    private function checkBruteForceProtection(string $username): void
    {
        $attempts = Cache::get("auth_attempts:{$username}", 0);
        
        if ($attempts >= 5) {
            throw new AuthenticationException('Account temporarily locked');
        }
        
        Cache::increment("auth_attempts:{$username}");
        Cache::put("auth_attempts:{$username}", $attempts + 1, 300); // 5 minutes
    }

    /**
     * Apply rate limiting to prevent abuse
     */
    private function applyRateLimit(string $username): void
    {
        $key = "auth_ratelimit:" . md5($username . request()->ip());
        
        if (Cache::has($key)) {
            throw new AuthenticationException('Rate limit exceeded');
        }
        
        Cache::put($key, true, 2); // 2 second rate limit
    }

    /**
     * Verify password securely
     */
    private function verifyPassword(User $user, string $password): bool
    {
        return password_verify(
            $this->encryption->hashPassword($password),
            $user->password_hash
        );
    }

    /**
     * Validate active session
     * 
     * @throws AuthenticationException
     */
    public function validateSession(string $token): SessionValidationResult
    {
        try {
            // Verify token integrity
            $payload = $this->tokenManager->verifyToken($token);
            
            // Validate session
            $session = $this->sessions->validateSession($payload->sessionId);
            
            // Check for session expiry
            if ($session->isExpired()) {
                throw new AuthenticationException('Session expired');
            }
            
            // Extend session if needed
            if ($session->needsExtension()) {
                $session = $this->sessions->extendSession($session);
            }
            
            return new SessionValidationResult($session, $payload);
            
        } catch (\Exception $e) {
            throw new AuthenticationException(
                'Session validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Terminate user session securely
     */
    public function logout(string $token): void
    {
        try {
            $payload = $this->tokenManager->verifyToken($token);
            $this->sessions->terminateSession($payload->sessionId);
            $this->tokenManager->revokeToken($token);
            
        } catch (\Exception $e) {
            Log::warning('Logout failed', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
        }
    }
}
