<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Security\SecurityManager;
use App\Core\Security\DataProtection\DataProtectionService;
use App\Core\Security\Audit\SecurityAudit;
use App\Core\Exceptions\{SecurityException, ValidationException};

class CMSSecurity
{
    private SecurityManager $security;
    private DataProtectionService $protection;
    private SecurityAudit $audit;
    private array $config;

    public function __construct(
        SecurityManager $security,
        DataProtectionService $protection,
        SecurityAudit $audit,
        array $config
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->validateRequest($request);
            $this->enforceSecurityHeaders($request);
            $context = $this->createSecurityContext($request);
            
            $this->validateAuthentication($context);
            $this->validateAuthorization($context);
            $this->enforceRateLimit($context);
            
            $request->attributes->set('security_context', $context);
            $this->audit->logRequest($request, $context);
            
            $response = $next($request);
            
            $this->validateResponse($response, $context);
            $this->audit->logResponse($response, $context);
            
            return $this->secureResponse($response, $context);
            
        } catch (\Exception $e) {
            $this->handleSecurityFailure($e, $request);
            throw $e;
        }
    }

    protected function validateRequest(Request $request): void
    {
        if (!$this->isValidRoute($request)) {
            throw new SecurityException('Invalid route access attempted');
        }

        if ($this->detectMaliciousRequest($request)) {
            throw new SecurityException('Malicious request detected');
        }

        if (!$this->validateCSRFToken($request)) {
            throw new SecurityException('CSRF token validation failed');
        }
    }

    protected function enforceSecurityHeaders(Request $request): void
    {
        foreach ($this->config['security_headers'] as $header => $value) {
            $request->headers->set($header, $value);
        }

        if (!$request->secure() && !$this->isExemptFromSSL($request)) {
            throw new SecurityException('SSL required');
        }
    }

    protected function createSecurityContext(Request $request): array
    {
        return [
            'user' => $request->user(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'route' => $request->route()->getName(),
            'method' => $request->method(),
            'timestamp' => now()->toIso8601String(),
            'session_id' => session()->getId(),
            'request_id' => uniqid('req_', true)
        ];
    }

    protected function validateAuthentication(array $context): void
    {
        if (!$this->security->isAuthenticated($context)) {
            throw new SecurityException('Authentication required');
        }

        if ($this->detectAuthenticationAnomaly($context)) {
            throw new SecurityException('Authentication anomaly detected');
        }
    }

    protected function validateAuthorization(array $context): void
    {
        if (!$this->security->isAuthorized($context)) {
            throw new SecurityException('Unauthorized access attempt');
        }

        if (!$this->validatePermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    protected function enforceRateLimit(array $context): void
    {
        $key = $this->generateRateLimitKey($context);
        $limit = $this->getRateLimit($context);

        if ($this->isRateLimitExceeded($key, $limit)) {
            throw new SecurityException('Rate limit exceeded');
        }

        $this->incrementRateLimit($key);
    }

    protected function validateResponse($response, array $context): void
    {
        if (!$this->isValidResponse($response)) {
            throw new SecurityException('Invalid response format');
        }

        if ($this->detectDataLeakage($response, $context)) {
            throw new SecurityException('Potential data leakage detected');
        }
    }

    protected function secureResponse($response, array $context): mixed
    {
        foreach ($this->config['security_headers'] as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $this->protection->secureResponse($response, $context);
    }

    protected function handleSecurityFailure(\Exception $e, Request $request): void
    {
        $this->audit->logSecurityFailure($e, [
            'request' => $request->all(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->triggerEmergencyProtocol($e);
        }
    }

    private function isValidRoute(Request $request): bool
    {
        return $request->route() && 
               in_array($request->route()->getName(), $this->config['allowed_routes']);
    }

    private function detectMaliciousRequest(Request $request): bool
    {
        return $this->security->detectMaliciousPatterns($request);
    }

    private function validateCSRFToken(Request $request): bool
    {
        return $request->isMethodSafe() || 
               $this->security->validateCSRFToken($request);
    }

    private function isExemptFromSSL(Request $request): bool
    {
        return in_array($request->path(), $this->config['ssl_exempt_routes']);
    }
}
