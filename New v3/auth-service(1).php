<?php

namespace App\Core\Security;

class AuthenticationService implements AuthenticationServiceInterface
{
    private TokenManager $tokenManager;
    private SessionManager $sessionManager;
    private UserSecurityManager $userSecurity;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;
    private MfaManager $mfaManager;

    public function __construct(
        TokenManager $tokenManager,
        SessionManager $sessionManager,
        UserSecurityManager $userSecurity,
        AuditLogger $auditLogger,
        SecurityConfig $config,
        MetricsCollector $metrics,
        MfaManager $mfaManager
    ) {
        $this->tokenManager = $tokenManager;
        $this->sessionManager = $sessionManager;
        $this->userSecurity = $userSecurity;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
        $this->metrics = $metrics;
        $this->mfaManager = $mfaManager;
    }

    public function authenticate(AuthenticationRequest $request): AuthenticationResult
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            $this->validateRequest($request);
            
            $user = $this->userSecurity->verifyCredentials($request->getCredentials());
            
            if ($user->requiresMfa() && !$request->hasMfaToken()) {
                return $this->initiateMfaChallenge($user);
            }
            
            if ($request->hasMfaToken()) {
                $this->verifyMfaToken($user, $request->getMfaToken());
            }

            $session = $this->sessionManager->createSession($user, $request->getContext());
            $token = $this->tokenManager->generateToken($user, $session);

            $this->auditLogger->logSuccessfulAuth($user, $request->getContext());
            
            DB::commit();
            
            $this->metrics->recordAuthSuccess($user->getId(), microtime(true) - $startTime);
            
            return new AuthenticationResult($token, $session);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleAuthFailure($e, $request);
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    public function validateSession(string $sessionId, string $token): SessionValidation 
    {
        try {
            $session = $this->sessionManager->validateSession($sessionId);
            $tokenValidation = $this->tokenManager->validateToken($token, $session);
            
            if ($session->requiresRenewal()) {
                return $this->renewSession($session, $token);
            }

            if ($tokenValidation->requiresRefresh()) {
                return $this->refreshToken($session, $token);
            }

            return new SessionValidation($session, $tokenValidation);
            
        } catch (\Exception $e) {
            $this->auditLogger->logSessionValidationFailure($sessionId, $e);
            throw new InvalidSessionException('Session validation failed', 0, $e);
        }
    }

    public function initiatePasswordReset(PasswordResetRequest $request): PasswordResetResult
    {
        try {
            $user = $this->userSecurity->findUserByIdentifier($request->getIdentifier());
            
            $this->validateResetEligibility($user);
            
            $token = $this->tokenManager->generateResetToken($user);
            $this->userSecurity->markResetInitiated($user);
            
            $this->auditLogger->logPasswordResetInitiated($user);
            
            return new PasswordResetResult($token);
            
        } catch (\Exception $e) {
            $this->handleResetFailure($e, $request);
            throw new PasswordResetException('Password reset failed', 0, $e);
        }
    }

    public function completePasswordReset(string $token, string $newPassword): void
    {
        try {
            $resetClaim = $this->tokenManager->validateResetToken($token);
            $user = $this->userSecurity->findUserById($resetClaim->getUserId());
            
            $this->userSecurity->updatePassword($user, $newPassword);
            $this->sessionManager->invalidateAllSessions($user);
            
            $this->auditLogger->logPasswordResetComplete($user);
            
        } catch (\Exception $e) {
            $this->handleResetCompletionFailure($e, $token);
            throw new PasswordResetException('Password reset completion failed', 0, $e);
        }
    }

    public function logout(string $sessionId): void
    {
        try {
            $session = $this->sessionManager->findSession($sessionId);
            
            $this->sessionManager->invalidateSession($session);
            $this->tokenManager->revokeSessionTokens($session);
            
            $this->auditLogger->logLogout($session->getUser());
            
        } catch (\Exception $e) {
            $this->auditLogger->logLogoutFailure($sessionId, $e);
            throw new LogoutException('Logout failed', 0, $e);
        }
    }

    private function validateRequest(AuthenticationRequest $request): void
    {
        if (!$request->isValid()) {
            throw new InvalidRequestException('Invalid authentication request');
        }

        if ($this->userSecurity->isLocked($request->getCredentials()->getUsername())) {
            throw new AccountLockedException('Account is locked');
        }
    }

    private function initiateMfaChallenge(User $user): MfaChallenge
    {
        $challenge = $this->mfaManager->generateChallenge($user);
        $this->auditLogger->logMfaChallengeInitiated($user);
        return new MfaChallenge($challenge);
    }

    private function verifyMfaToken(User $user, string $token): void
    {
        if (!$this->mfaManager->validateToken($user, $token)) {
            $this->auditLogger->logMfaFailed($user);
            throw new MfaException('MFA validation failed');
        }
    }

    private function handleAuthFailure(\Exception $e, AuthenticationRequest $request): void
    {
        $this->metrics->recordAuthFailure();
        $this->auditLogger->logAuthFailure($request->getCredentials(), $e);

        if ($e instanceof InvalidCredentialsException) {
            $this->userSecurity->recordFailedAttempt($request->getCredentials()->getUsername());
        }
    }

    private function validateResetEligibility(User $user): void
    {
        if ($user->isLocked()) {
            throw new AccountLockedException('Account is locked');
        }

        if (!$this->userSecurity->canInitiateReset($user)) {
            throw new ResetNotAllowedException('Reset not allowed at this time');
        }
    }

    private function renewSession(Session $session, string $token): SessionValidation
    {
        $newSession = $this->sessionManager->renewSession($session);
        $newToken = $this->tokenManager->refreshToken($token, $newSession);
        
        $this->auditLogger->logSessionRenewal($session->getUser());
        
        return new SessionValidation($newSession, new TokenValidation($newToken));
    }

    private function refreshToken(Session $session, string $token): SessionValidation
    {
        $newToken = $this->tokenManager->refreshToken($token, $session);
        $this->auditLogger->logTokenRefresh($session->getUser());
        
        return new SessionValidation($session, new TokenValidation($newToken));
    }
}
