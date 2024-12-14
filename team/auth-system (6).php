<?php

namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    protected TokenService $tokens;
    protected EncryptionService $encryption;
    protected AuditLogger $logger;
    protected CacheManager $cache;
    protected MetricsCollector $metrics;

    public function authenticate(AuthRequest $request): AuthResult
    {
        DB::beginTransaction();
        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);

            $user = $this->validatePrimaryCredentials($request);
            $this->validateMfaToken($request, $user);
            
            $session = $this->createSecureSession($user, $request);
            
            DB::commit();
            $this->logger->logSuccessfulAuth($user);
            
            return new AuthResult($session, $user);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $request);
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    protected function validateRequest(AuthRequest $request): void
    {
        if (!$request->hasValidStructure()) {
            throw new ValidationException('Invalid auth request structure');
        }

        if ($this->isBlockedIp($request->getIpAddress())) {
            throw new SecurityException('IP address blocked');
        }
    }

    protected function checkRateLimit(AuthRequest $request): void
    {
        $key = "auth_attempts:{$request->getIdentifier()}";
        
        if ($this->cache->increment($key, 1, 3600) > 5) {
            $this->metrics->increment('auth.rate_limit_exceeded');
            throw new RateLimitException('Too many authentication attempts');
        }
    }

    protected function validatePrimaryCredentials(AuthRequest $request): User
    {
        $user = $this->findUser($request->getIdentifier());
        
        if (!$user || !$this->verifyPassword($user, $request->getPassword())) {
            $this->metrics->increment('auth.invalid_credentials');
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    protected function validateMfaToken(AuthRequest $request, User $user): void
    {
        if ($user->mfaRequired() && !$this->tokens->verify($request->getMfaToken(), $user)) {
            $this->metrics->increment('auth.invalid_mfa');
            throw new MfaException('Invalid MFA token');
        }
    }

    protected function createSecureSession(User $user, AuthRequest $request): Session
    {
        $session = new Session([
            'user_id' => $user->id,
            'ip_address' => $request->getIpAddress(),
            'user_agent' => $request->getUserAgent(),
            'expires_at' => now()->addMinutes(30),
            'token' => $this->tokens->generate()
        ]);

        $session->save();
        
        $this->cache->put(
            "session:{$session->token}",
            $session->toArray(),
            $session->expires_at
        );

        return $session;
    }

    protected function handleAuthFailure(\Exception $e, AuthRequest $request): void
    {
        $this->logger->logAuthFailure($e, [
            'identifier' => $request->getIdentifier(),
            'ip' => $request->getIpAddress(),
            'user_agent' => $request->getUserAgent()
        ]);

        if ($e instanceof SecurityException) {
            $this->handleSecurityViolation($e, $request);
        }
    }

    protected function handleSecurityViolation(SecurityException $e, AuthRequest $request): void
    {
        if ($this->isHighRiskViolation($e)) {
            $this->blockIp($request->getIpAddress());
            event(new SecurityAlertEvent($e, $request));
        }
    }

    protected function verifyPassword(User $user, string $password): bool
    {
        return $this->encryption->verifyHash(
            $password,
            $user->password_hash,
            $user->password_salt
        );
    }

    protected function findUser(string $identifier): ?User
    {
        return $this->cache->remember(
            "user:$identifier",
            300,
            fn() => User::where('email', $identifier)->first()
        );
    }

    protected function isBlockedIp(string $ip): bool
    {
        return $this->cache->has("blocked_ip:$ip");
    }

    protected function blockIp(string $ip): void
    {
        $this->cache->put("blocked_ip:$ip", true, now()->addHours(24));
    }

    protected function isHighRiskViolation(SecurityException $e): bool
    {
        return in_array($e->getCode(), [
            SecurityErrorCodes::BRUTE_FORCE_ATTEMPT,
            SecurityErrorCodes::MFA_BYPASS_ATTEMPT,
            SecurityErrorCodes::SESSION_HIJACKING_ATTEMPT
        ]);
    }
}
