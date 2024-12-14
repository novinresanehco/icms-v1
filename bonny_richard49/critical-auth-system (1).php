<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Events\{AuthenticationEvent, SecurityEvent};

class CriticalAuthenticationSystem implements AuthenticationInterface 
{
    private SecurityManagerInterface $security;
    private TokenManager $tokens;
    private AuditLogger $auditLogger;
    private int $maxAttempts = 3;
    private int $lockoutTime = 900; // 15 minutes

    public function __construct(
        SecurityManagerInterface $security,
        TokenManager $tokens,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->tokens = $tokens;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Authenticate user with multi-factor verification
     *
     * @throws AuthenticationException
     */
    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($credentials),
            function() use ($credentials) {
                // Check for lockout
                $this->checkLockout($credentials['username']);

                // Verify primary credentials
                $user = $this->verifyCredentials($credentials);
                
                // Verify MFA if enabled
                if ($user->hasMfa()) {
                    $this->verifyMfaToken($user, $credentials['mfa_token']);
                }

                // Generate secure session
                $session = $this->createSecureSession($user);

                // Log successful authentication
                $this->auditLogger->logAuthentication($user, true);

                // Reset failed attempts
                $this->resetFailedAttempts($credentials['username']);

                return new AuthResult($user, $session);
            }
        );
    }

    /**
     * Verify user credentials with rate limiting
     */
    protected function verifyCredentials(array $credentials): User 
    {
        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedAttempt($credentials['username']);
            throw new AuthenticationException('Invalid credentials');
        }

        // Verify account status
        if (!$user->isActive()) {
            throw new AccountLockedException('Account is locked or inactive');
        }

        return $user;
    }

    /**
     * Verify MFA token
     */
    protected function verifyMfaToken(User $user, string $token): void 
    {
        if (!$this->tokens->verifyMfaToken($user, $token)) {
            $this->auditLogger->logSecurityEvent(
                SecurityEvent::MFA_FAILURE,
                ['user_id' => $user->id]
            );
            throw new MfaException('Invalid MFA token');
        }
    }

    /**
     * Create secure session with strict parameters
     */
    protected function createSecureSession(User $user): Session 
    {
        $session = new Session([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addMinutes(15)
        ]);

        $session->token = $this->tokens->generateSecureToken();
        $session->save();

        return $session;
    }

    /**
     * Handle failed authentication attempt
     */
    protected function handleFailedAttempt(string $username): void 
    {
        $key = $this->getAttemptsCacheKey($username);
        $attempts = Cache::get($key, 0) + 1;
        
        Cache::put($key, $attempts, now()->addMinutes(15));

        if ($attempts >= $this->maxAttempts) {
            $this->lockoutUser($username);
            throw new AccountLockedException(
                'Account locked due to too many failed attempts'
            );
        }

        $this->auditLogger->logFailedLogin($username, $attempts);
    }

    /**
     * Lock out user account
     */
    protected function lockoutUser(string $username): void 
    {
        Cache::put(
            $this->getLockoutCacheKey($username),
            true,
            now()->addSeconds($this->lockoutTime)
        );

        $this->auditLogger->logSecurityEvent(
            SecurityEvent::ACCOUNT_LOCKOUT,
            ['username' => $username]
        );
    }

    /**
     * Check if user is locked out
     */
    protected function checkLockout(string $username): void 
    {
        if (Cache::get($this->getLockoutCacheKey($username))) {
            throw new AccountLockedException('Account is temporarily locked');
        }
    }

    /**
     * Reset failed attempts counter
     */
    protected function resetFailedAttempts(string $username): void 
    {
        Cache::forget($this->getAttemptsCacheKey($username));
    }

    protected function getAttemptsCacheKey(string $username): string 
    {
        return "auth.attempts:{$username}";
    }

    protected function getLockoutCacheKey(string $username): string 
    {
        return "auth.lockout:{$username}";
    }
}
