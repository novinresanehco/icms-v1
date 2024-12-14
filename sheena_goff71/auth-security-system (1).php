<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\{SecurityManager, EncryptionService};
use App\Core\Auth\Events\{AuthenticationEvent, SecurityEvent};
use App\Core\Auth\Exceptions\{AuthenticationException, SecurityViolationException};

class AuthenticationManager
{
    protected SecurityManager $security;
    protected EncryptionService $encryption;
    protected TokenManager $tokenManager;
    protected AuditLogger $auditLogger;
    
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const TOKEN_LIFETIME = 3600; // 1 hour
    
    public function __construct(
        SecurityManager $security,
        EncryptionService $encryption,
        TokenManager $tokenManager,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->encryption = $encryption;
        $this->tokenManager = $tokenManager;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Authenticate user with multi-factor authentication
     * 
     * @throws AuthenticationException
     * @throws SecurityViolationException
     */
    public function authenticate(array $credentials, string $mfaCode): AuthResult
    {
        return $this->security->executeCriticalOperation(function() use ($credentials, $mfaCode) {
            // Validate attempt limits
            $this->validateAttempts($credentials['email']);
            
            // Primary authentication
            $user = $this->validateCredentials($credentials);
            
            // Secondary authentication (MFA)
            $this->validateMfaCode($user, $mfaCode);
            
            // Generate secure session
            $session = $this->establishSecureSession($user);
            
            // Log successful authentication
            $this->auditLogger->logAuthSuccess($user);
            
            return new AuthResult($user, $session);
        }, ['context' => 'authentication', 'ip' => request()->ip()]);
    }

    /**
     * Validate user credentials with rate limiting
     */
    protected function validateCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedAttempt($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    /**
     * Validate MFA code with time-based verification
     */
    protected function validateMfaCode(User $user, string $code): void
    {
        if (!$this->verifyMfaCode($user, $code)) {
            $this->auditLogger->logMfaFailure($user);
            throw new SecurityViolationException('Invalid MFA code');
        }
    }

    /**
     * Establish secure session with encryption and token management
     */
    protected function establishSecureSession(User $user): Session
    {
        // Generate secure token
        $token = $this->tokenManager->generateSecureToken();
        
        // Create encrypted session
        $session = new Session([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addSeconds(self::TOKEN_LIFETIME),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
        
        // Store session with encryption
        $this->storeSecureSession($session);
        
        return $session;
    }

    /**
     * Validate login attempts with rate limiting
     */
    protected function validateAttempts(string $email): void
    {
        $attempts = Cache::get("auth.attempts.$email", 0);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->auditLogger->logExcessiveAttempts($email);
            throw new SecurityViolationException('Account locked due to excessive attempts');
        }
    }

    /**
     * Handle failed authentication attempt
     */
    protected function handleFailedAttempt(string $email): void
    {
        $attempts = Cache::increment("auth.attempts.$email");
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put("auth.lockout.$email", true, self::LOCKOUT_TIME);
        }
        
        $this->auditLogger->logAuthFailure($email);
    }

    /**
     * Verify MFA code with time-based algorithm
     */
    protected function verifyMfaCode(User $user, string $code): bool
    {
        return $this->tokenManager->verifyTimeBasedCode(
            $user->mfa_secret,
            $code
        );
    }

    /**
     * Store session securely with encryption
     */
    protected function storeSecureSession(Session $session): void
    {
        $encryptedData = $this->encryption->encrypt($session->toArray());
        Cache::put(
            "auth.session.{$session->token}",
            $encryptedData,
            self::TOKEN_LIFETIME
        );
    }

    /**
     * Validate active session with security checks
     */
    public function validateSession(string $token): ?User
    {
        $encryptedSession = Cache::get("auth.session.$token");
        
        if (!$encryptedSession) {
            return null;
        }
        
        $sessionData = $this->encryption->decrypt($encryptedSession);
        
        // Validate session integrity and expiration
        if (!$this->validateSessionIntegrity($sessionData)) {
            $this->auditLogger->logSessionViolation($sessionData);
            throw new SecurityViolationException('Invalid session integrity');
        }
        
        return User::find($sessionData['user_id']);
    }

    /**
     * Emergency session termination
     */
    public function terminateAllSessions(): void
    {
        $this->security->executeCriticalOperation(function() {
            Cache::tags(['auth.sessions'])->flush();
            $this->auditLogger->logEmergencyTermination();
        }, ['context' => 'emergency_termination']);
    }
}
