<?php

namespace App\Core\Security;

use App\Core\Auth\AuthenticationResult;
use App\Core\Auth\TokenManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Security\SecurityContext;
use App\Exceptions\AuthenticationException;

class AuthenticationService implements AuthInterface
{
    private TokenManager $tokenManager;
    private ValidationService $validator;
    private AuditLogger $audit;
    private array $securityConfig;

    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const TOKEN_LIFETIME = 3600; // 1 hour

    public function __construct(
        TokenManager $tokenManager,
        ValidationService $validator,
        AuditLogger $audit,
        array $securityConfig
    ) {
        $this->tokenManager = $tokenManager;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->securityConfig = $securityConfig;
    }

    public function authenticate(AuthenticationRequest $request): AuthenticationResult 
    {
        DB::beginTransaction();
        
        try {
            // Validate request
            $this->validateRequest($request);
            
            // Check attempt limits
            $this->checkAttemptLimits($request);
            
            // Perform authentication
            $result = $this->performAuthentication($request);
            
            // Generate secure token
            $token = $this->generateSecureToken($result);
            
            DB::commit();
            
            // Log success
            $this->logAuthenticationSuccess($request, $result);
            
            return new AuthenticationResult($result, $token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthenticationFailure($e, $request);
            throw $e;
        }
    }

    public function validateToken(string $token): SecurityContext
    {
        try {
            // Validate token format
            $this->validator->validateTokenFormat($token);
            
            // Verify token
            $tokenData = $this->tokenManager->verifyToken($token);
            
            // Build security context
            return $this->buildSecurityContext($tokenData);
            
        } catch (\Exception $e) {
            $this->handleTokenValidationFailure($e, $token);
            throw $e;
        }
    }

    public function refreshToken(string $token): string
    {
        DB::beginTransaction();
        
        try {
            // Validate current token
            $currentContext = $this->validateToken($token);
            
            // Generate new token
            $newToken = $this->tokenManager->refreshToken($token);
            
            // Validate new token
            $this->validator->validateTokenFormat($newToken);
            
            DB::commit();
            
            return $newToken;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTokenRefreshFailure($e, $token);
            throw $e;
        }
    }

    private function validateRequest(AuthenticationRequest $request): void
    {
        if (!$this->validator->validateAuthRequest($request)) {
            throw new AuthenticationException('Invalid authentication request');
        }
    }

    private function checkAttemptLimits(AuthenticationRequest $request): void
    {
        $attempts = $this->getAttemptCount($request);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->handleExcessiveAttempts($request);
            throw new AuthenticationException('Maximum attempts exceeded');
        }
    }

    private function performAuthentication(AuthenticationRequest $request): AuthResult
    {
        // Primary authentication
        $primaryResult = $this->performPrimaryAuthentication($request);
        
        // Two-factor authentication if enabled
        if ($this->requiresTwoFactor($request)) {
            $this->performTwoFactorAuthentication($request, $primaryResult);
        }
        
        return $primaryResult;
    }

    private function generateSecureToken(AuthResult $result): string
    {
        return $this->tokenManager->generateToken([
            'user_id' => $result->getUserId(),
            'permissions' => $result->getPermissions(),
            'expires_at' => now()->addSeconds(self::TOKEN_LIFETIME)
        ]);
    }

    private function buildSecurityContext(array $tokenData): SecurityContext
    {
        return new SecurityContext(
            $tokenData['user_id'],
            $tokenData['permissions'],
            $this->securityConfig
        );
    }

    private function handleAuthenticationFailure(\Exception $e, AuthenticationRequest $request): void
    {
        $this->incrementAttemptCount($request);
        
        $this->audit->logAuthenticationFailure([
            'request' => $request->getId(),
            'error' => $e->getMessage(),
            'attempts' => $this->getAttemptCount($request)
        ]);
    }

    private function handleTokenValidationFailure(\Exception $e, string $token): void
    {
        $this->audit->logTokenValidationFailure([
            'token_id' => $this->tokenManager->getTokenId($token),
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
    }

    private function handleExcessiveAttempts(AuthenticationRequest $request): void
    {
        Cache::put(
            "auth_lockout:{$request->getIdentifier()}",
            now()->addSeconds(self::LOCKOUT_TIME),
            self::LOCKOUT_TIME
        );
        
        $this->audit->logExcessiveAttempts([
            'identifier' => $request->getIdentifier(),
            'attempts' => self::MAX_ATTEMPTS,
            'lockout_until' => now()->addSeconds(self::LOCKOUT_TIME)
        ]);
    }

    private function getAttemptCount(AuthenticationRequest $request): int
    {
        return (int) Cache::get("auth_attempts:{$request->getIdentifier()}", 0);
    }

    private function incrementAttemptCount(AuthenticationRequest $request): void
    {
        Cache::increment("auth_attempts:{$request->getIdentifier()}", 1);
    }

    private function requiresTwoFactor(AuthenticationRequest $request): bool
    {
        return $this->securityConfig['two_factor_required'] ?? false;
    }
}
