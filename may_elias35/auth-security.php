namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface 
{
    private UserRepository $users;
    private TokenService $tokens;
    private MFAService $mfa;
    private AuditLogger $auditLogger;
    private SessionManager $sessions;
    private RateLimiter $rateLimiter;

    public function __construct(
        UserRepository $users,
        TokenService $tokens,
        MFAService $mfa,
        AuditLogger $auditLogger,
        SessionManager $sessions,
        RateLimiter $rateLimiter
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->mfa = $mfa;
        $this->auditLogger = $auditLogger;
        $this->sessions = $sessions;
        $this->rateLimiter = $rateLimiter;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        // Check rate limiting
        if (!$this->rateLimiter->attempt('auth', $credentials['ip'])) {
            $this->auditLogger->logRateLimitExceeded('authentication', $credentials['ip']);
            throw new AuthenticationException('Rate limit exceeded');
        }

        DB::beginTransaction();
        try {
            // Validate credentials
            $user = $this->validateCredentials($credentials);
            
            // Check MFA if enabled
            if ($user->hasMFAEnabled()) {
                $this->verifyMFA($user, $credentials['mfa_code'] ?? null);
            }

            // Generate secure session
            $session = $this->sessions->create([
                'user_id' => $user->id,
                'ip' => $credentials['ip'],
                'user_agent' => $credentials['user_agent'],
                'expires_at' => now()->addMinutes(config('auth.session_lifetime'))
            ]);

            // Generate auth token
            $token = $this->tokens->generate($user, $session);

            // Log successful authentication
            $this->auditLogger->logAuthentication($user, $session, true);

            DB::commit();

            return new AuthResult($user, $token, $session);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failed attempt
            $this->auditLogger->logAuthentication(null, null, false, [
                'error' => $e->getMessage(),
                'ip' => $credentials['ip']
            ]);

            throw new AuthenticationException('Authentication failed: ' . $e->getMessage());
        }
    }

    private function validateCredentials(array $credentials): User 
    {
        $user = $this->users->findByEmail($credentials['email']);

        if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isLocked()) {
            throw new AuthenticationException('Account is locked');
        }

        return $user;
    }

    private function verifyPassword(User $user, string $password): bool 
    {
        if (!Hash::check($password, $user->password)) {
            // Increment failed attempts
            $this->users->incrementFailedAttempts($user);
            
            // Lock account if threshold reached
            if ($user->failed_attempts >= config('auth.max_attempts')) {
                $this->users->lockAccount($user);
                $this->auditLogger->logAccountLocked($user);
            }
            
            return false;
        }

        // Reset failed attempts on successful verification
        $this->users->resetFailedAttempts($user);
        return true;
    }

    private function verifyMFA(User $user, ?string $code): void 
    {
        if (!$code || !$this->mfa->verify($user, $code)) {
            throw new MFAException('Invalid MFA code');
        }
    }

    public function validateSession(string $token): SessionValidationResult 
    {
        $session = $this->sessions->findByToken($token);

        if (!$session || $session->isExpired()) {
            throw new SessionException('Invalid or expired session');
        }

        // Verify session security
        $this->verifySessionSecurity($session);

        // Extend session if needed
        if ($this->shouldExtendSession($session)) {
            $this->sessions->extend($session);
        }

        return new SessionValidationResult($session->user, $session);
    }

    private function verifySessionSecurity(Session $session): void 
    {
        // Verify IP hasn't changed dramatically (if enabled)
        if (config('auth.verify_ip') && !IpUtils::checkIp($session->ip)) {
            throw new SecurityException('IP address changed');
        }

        // Verify user-agent consistency
        if ($session->user_agent !== request()->userAgent()) {
            throw new SecurityException('User agent changed');
        }

        // Check if session has been invalidated
        if ($this->sessions->isInvalidated($session->id)) {
            throw new SecurityException('Session has been invalidated');
        }
    }

    private function shouldExtendSession(Session $session): bool 
    {
        return $session->shouldBeExtended();
    }

    public function logout(string $token): void 
    {
        DB::transaction(function () use ($token) {
            $session = $this->sessions->findByToken($token);
            
            if ($session) {
                // Invalidate session
                $this->sessions->invalidate($session);
                
                // Revoke auth tokens
                $this->tokens->revokeAllForSession($session);
                
                // Log logout
                $this->auditLogger->logLogout($session->user, $session);
            }
        });
    }
}
