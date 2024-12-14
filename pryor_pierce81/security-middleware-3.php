<?php

namespace App\Core\Security\Middleware;

class CriticalSecurityMiddleware
{
    private $security;
    private $monitor;
    
    public function handle(Request $request, \Closure $next): Response
    {
        try {
            // Validate security headers
            $this->validateHeaders($request);
            
            // Check authentication
            $this->validateAuth($request);
            
            // Verify request integrity
            $this->verifyIntegrity($request);
            
            return $next($request);
            
        } catch (SecurityException $e) {
            return $this->handleSecurityFailure($e);
        }
    }

    private function validateHeaders(Request $request): void
    {
        foreach (SecurityConfig::CRITICAL_SETTINGS['headers'] as $header => $value) {
            if (!$this->headerIsValid($request, $header, $value)) {
                throw new SecurityHeaderException("Invalid security header: $header");
            }
        }
    }

    private function headerIsValid(Request $request, string $header, string $value): bool
    {
        return $request->header($header) === $value;
    }
}
