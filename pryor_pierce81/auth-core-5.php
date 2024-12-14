<?php

namespace App\Core\Security\Auth;

class CriticalAuthManager
{
    private $config;
    private $tokenService;
    private $validator;
    private $monitor;

    public function authenticate(Request $request): AuthResult
    {
        $this->monitor->startAuth();

        try {
            // Validate credentials
            if (!$this->validator->validateCredentials($request->credentials)) {
                throw new AuthException('Invalid credentials');
            }

            // Strict security checks
            $this->enforceSecurityPolicy($request);

            // Generate secure token
            $token = $this->tokenService->generateSecureToken($request);

            $this->monitor->authSuccess();
            return new AuthResult($token);

        } catch (\Exception $e) {
            $this->monitor->authFailure($e);
            throw $e;
        }
    }

    private function enforceSecurityPolicy(Request $request): void
    {
        if (!$this->validator->checkRateLimit($request)) {
            throw new SecurityException('Rate limit exceeded');
        }

        if (!$this->validator->checkIPAllowed($request)) {
            throw new SecurityException('IP not allowed');
        }

        if ($this->detectSuspiciousActivity($request)) {
            throw new SecurityException('Suspicious activity detected');
        }
    }
}
