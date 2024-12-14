<?php

namespace App\Core\Auth;

class AuthenticationSystem implements AuthenticationInterface 
{
    private AuthProviderInterface $provider;
    private SessionManager $sessions;
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    public function authenticate(AuthRequest $request): AuthResult 
    {
        try {
            // Rate limiting check
            if (!$this->rateLimiter->attempt($request->getIdentifier())) {
                $this->auditLogger->logRateLimit($request);
                throw new RateLimitException('Too many attempts');
            }

            // Pre-auth security checks
            $this->security->validateAuthRequest($request);

            // Multi-factor validation
            $this->validateMfaIfRequired($request);

            // Core authentication
            $result = $this->provider->authenticate($request->getCredentials());
            
            if (!$result->isSuccessful()) {
                $this->handleFailedAttempt($request);
                return $result;
            }

            // Session creation with security context
            $session = $this->sessions->create(
                $result->getUser(),
                $request->getContext()
            );

            // Audit successful login
            $this->auditLogger->logSuccessfulAuth($result->getUser(), $request);

            return new AuthResult(
                success: true,
                user: $result->getUser(),
                session: $session
            );

        } catch (\Exception $e) {
            $this->handleAuthError($e, $request);
            throw $e;
        }
    }

    public function validateSession(string $token): SessionValidationResult 
    {
        $session = $this->sessions->validate($token);

        if (!$session->isValid()) {
            $this->auditLogger->logInvalidSession($token);
            throw new InvalidSessionException();
        }

        if ($this->security->requiresRevalidation($session)) {
            return new SessionValidationResult(
                valid: false,
                requiresReauth: true
            );
        }

        // Extend session if needed
        if ($session->shouldExtend()) {
            $this->sessions->extend($session);
        }

        return new SessionValidationResult(
            valid: true,
            session: $session
        );
    }

    public function refreshToken(string $refreshToken): TokenRefreshResult 
    {
        if (!$this->sessions->validateRefreshToken($refreshToken)) {
            throw new InvalidRefreshTokenException();
        }

        return $this->sessions->refreshAccessToken($refreshToken);
    }

    public function logout(string $token): void 
    {
        $session = $this->sessions->get($token);
        
        if ($session) {
            $this->sessions->invalidate($session);
            $this->auditLogger->logLogout($session->getUser());
        }
    }

    private function validateMfaIfRequired(AuthRequest $request): void 
    {
        if ($this->security->requiresMfa($request)) {
            $mfaResult = $this->provider->verifyMfa(
                $request->getMfaToken(),
                $request->getCredentials()
            );

            if (!$mfaResult->isValid()) {
                $this->auditLogger->logFailedMfa($request);
                throw new MfaValidationException();
            }
        }
    }

    private function handleFailedAttempt(AuthRequest $request): void 
    {
        $this->rateLimiter->increment($request->getIdentifier());
        $this->auditLogger->logFailedAttempt($request);

        if ($this->rateLimiter->shouldBlock($request->getIdentifier())) {
            $this->security->blockSuspiciousActivity(
                $request->getIdentifier(),
                $request->getContext()
            );
        }
    }

    private function handleAuthError(\Exception $e, AuthRequest $request): void 
    {
        $this->auditLogger->logAuthError($e, $request);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityEvent(
                $e,
                $request->getContext()
            );
        }
    }
}
