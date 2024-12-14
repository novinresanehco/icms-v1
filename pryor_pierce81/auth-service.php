<?php

namespace App\Core\Auth;

class CriticalAuthService
{
    private $encryptor;
    private $monitor;
    private $tokenManager;

    public function authenticate(AuthRequest $request): AuthResult
    {
        $operationId = $this->monitor->startAuth();

        try {
            // Validate credentials securely
            $this->validateCredentials($request);

            // Check rate limiting
            if (!$this->checkRateLimit($request)) {
                throw new RateLimitException();
            }

            // Generate secure token
            $token = $this->tokenManager->generateSecureToken($request);

            $this->monitor->authSuccess($operationId);
            return new AuthResult($token);

        } catch (\Exception $e) {
            $this->monitor->authFailure($operationId, $e);
            throw $e;
        }
    }

    private function validateCredentials(AuthRequest $request): void
    {
        if (!$this->verifyPassword($request->password)) {
            $this->monitor->failedAttempt($request);
            throw new AuthenticationException();
        }
    }

    private function verifyPassword(string $password): bool
    {
        return password_verify(
            $password,
            $this->encryptor->hash($password)
        );
    }
}
