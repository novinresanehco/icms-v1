<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\AuthenticationInterface;

class AuthenticationManager implements AuthenticationInterface
{
    private TokenManager $tokenManager;
    private MFAService $mfaService;
    private SessionManager $sessionManager;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;

    public function __construct(
        TokenManager $tokenManager,
        MFAService $mfaService,
        SessionManager $sessionManager,
        AuditLogger $auditLogger,
        SecurityConfig $config
    ) {
        $this->tokenManager = $tokenManager;
        $this->mfaService = $mfaService;
        $this->sessionManager = $sessionManager;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function authenticate(AuthRequest $request): AuthResult 
    {
        DB::beginTransaction();
        try {
            // Initial credential validation
            $user = $this->validateCredentials($request->getCredentials());
            
            // MFA verification if enabled
            if ($this->config->isMFARequired()) {
                $this->verifyMFA($user, $request->getMFAToken());
            }
            
            // Create authenticated session
            $session = $this->sessionManager->createSession([
                'user_id' => $user->id,
                'ip' => $request->getIp(),
                'user_agent' => $request->getUserAgent()
            ]);

            // Generate access token
            $token = $this->tokenManager->generateToken($user, $session->id);

            DB::commit();

            // Log successful authentication
            $this->auditLogger->logAuthentication($user, true);

            return new AuthResult($user, $token, $session);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $request);
            throw $e;
        }
    }

    private function validateCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->auditLogger->logFailedLogin($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isSuspended() || $user->isLocked()) {
            throw new AccountLockedException('Account is locked or suspended');
        }

        return $user;
    }

    private function verifyMFA(User $user, string $token): void
    {
        if (!$this->mfaService->verifyToken($user, $token)) {
            $this->auditLogger->logFailedMFA($user);
            throw new MFAVerificationException('Invalid MFA token');
        }
    }

    public function validateSession(string $token): SecurityContext
    {
        try {
            // Verify token authenticity and expiration
            $payload = $this->tokenManager->verifyToken($token);
            
            // Get current session
            $session = $this->sessionManager->getSession($payload['session_id']);
            
            // Verify session validity
            if (!$session->isValid()) {
                throw new InvalidSessionException('Session is no longer valid');
            }
            
            // Verify user status
            $user = User::findOrFail($payload['user_id']);
            if (!$user->isActive()) {
                throw new AccountStatusException('User account is not active');
            }

            // Update session activity
            $this->sessionManager->touchSession($session->id);

            return new SecurityContext($user, $session);

        } catch (\Exception $e) {
            $this->handleSessionValidationFailure($e, $token);
            throw $e;
        }
    }

    public function enforceRateLimit(string $key, int $maxAttempts, int $decayMinutes): void
    {
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            throw new RateLimitExceededException(
                "Too many attempts. Please try again in $decayMinutes minutes."
            );
        }

        Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));
    }

    private function handleAuthFailure(\Exception $e, AuthRequest $request): void
    {
        // Log the failure
        $this->auditLogger->logAuthFailure($e, $request);

        // Increment failed attempts counter
        $key = 'auth.failures:' . $request->getIp();
        $attempts = Cache::increment($key);

        // Lock account if threshold exceeded
        if ($attempts >= $this->config->getMaxFailedAttempts()) {
            $this->lockAccount($request->getCredentials()['email']);
        }
    }

    private function handleSessionValidationFailure(\Exception $e, string $token): void
    {
        // Log the failure
        $this->auditLogger->logSessionValidationFailure($e, $token);

        // Invalidate compromised session if necessary
        if ($e instanceof SecurityException) {
            $this->sessionManager->invalidateSession($token);
        }
    }

    private function lockAccount(string $email): void
    {
        DB::transaction(function() use ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->lock();
                $this->auditLogger->logAccountLock($user);
            }
        });
    }
}
