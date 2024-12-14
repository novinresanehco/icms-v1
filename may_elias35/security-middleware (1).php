<?php

namespace App\Http\Middleware;

use App\Core\Security\SecurityManager;
use App\Core\Validation\RequestValidator;
use App\Core\Audit\AuditLogger;
use Illuminate\Http\Request;

class SecurityMiddleware
{
    private SecurityManager $security;
    private RequestValidator $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        RequestValidator $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function handle(Request $request, \Closure $next)
    {
        try {
            // Validate request
            $this->validateRequest($request);
            
            // Check security headers
            $this->validateSecurityHeaders($request);
            
            // Validate authentication
            $this->validateAuthentication($request);
            
            // Check authorization
            $this->validateAuthorization($request);
            
            // Add security context
            $request = $this->addSecurityContext($request);
            
            // Log successful validation
            $this->audit->logSecurityCheck($request);
            
            return $next($request);
            
        } catch (\Exception $e) {
            $this->handleSecurityFailure($e, $request);
            throw $e;
        }
    }

    private function validateRequest(Request $request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new SecurityValidationException('Invalid request format');
        }

        if (!$this->validator->validateInput($request->all())) {
            throw new SecurityValidationException('Invalid input data');
        }
    }

    private function validateSecurityHeaders(Request $request): void
    {
        $headers = $request->headers->all();
        
        if (!isset($headers['x-request-id'])) {
            throw new SecurityHeaderException('Missing request ID');
        }

        if (!isset($headers['x-security-token'])) {
            throw new SecurityHeaderException('Missing security token');
        }

        $this->security->validateSecurityHeaders($headers);
    }

    private function validateAuthentication(Request $request): void
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            throw new AuthenticationException('No authentication token provided');
        }

        $this->security->validateToken($token);
    }

    private function validateAuthorization(Request $request): void
    {
        $context = $this->security->getSecurityContext($request);
        
        if (!$this->security->isAuthorized($context, $request->getRequestUri())) {
            throw new AuthorizationException('Access denied');
        }
    }

    private function addSecurityContext(Request $request): Request
    {
        $context = $this->security->createSecurityContext($request);
        return $request->merge(['security_context' => $context]);
    }

    private function handleSecurityFailure(\Exception $e, Request $request): void
    {
        $this->audit->logSecurityFailure($e, [
            'request_id' => $request->header('x-request-id'),
            'uri' => $request->getRequestUri(),
            'method' => $request->getMethod(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);
    }
}
