<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode as Middleware;
use App\Core\Security\{AuditService, AccessControlService};
use Illuminate\Http\Request;
use Closure;

class SecurityMiddleware
{
    protected AuditService $auditService;
    protected AccessControlService $accessControl;
    
    public function __construct(
        AuditService $auditService,
        AccessControlService $accessControl
    ) {
        $this->auditService = $auditService;
        $this->accessControl = $accessControl;
    }

    public function handle(Request $request, Closure $next)
    {
        $this->validateRequest($request);
        $this->enforceSecurityHeaders($request);
        $this->checkRateLimits($request);
        
        $response = $next($request);
        
        $this->auditRequest($request, $response);
        $this->addSecurityHeaders($response);
        
        return $response;
    }

    protected function validateRequest(Request $request): void
    {
        // Validate request signature
        if ($request->hasHeader('X-Signature')) {
            $this->validateSignature($request);
        }

        // Check CSRF token
        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request);
        }

        // Validate content type
        if ($request->isJson()) {
            $this->validateJsonStructure($request);
        }
    }

    protected function enforceSecurityHeaders(Request $request): void
    {
        $secureHeaders = [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ];

        foreach ($secureHeaders as $header => $value) {
            if (!$request->headers->has($header)) {
                $request->headers->set($header, $value);
            }
        }
    }

    protected function checkRateLimits(Request $request): void
    {
        $key = sprintf('rate_limit:%s:%s', 
            $request->ip(),
            md5($request->path())
        );

        $hits = cache()->increment($key);
        
        if (!$hits) {
            cache()->put($key, 1, now()->addMinutes(1));
        } elseif ($hits > config('security.rate_limit', 60)) {
            $this->auditService->logSecurityEvent('rate_limit_exceeded', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'hits' => $hits
            ]);
            
            abort(429, 'Too Many Requests');
        }
    }

    protected function validateSignature(Request $request): void
    {
        $signature = $request->header('X-Signature');
        $payload = $request->getContent();
        
        if (!$this->accessControl->validateSignature($signature, $payload)) {
            $this->auditService->logSecurityEvent('invalid_signature', [
                'ip' => $request->ip()
            ]);
            
            abort(401, 'Invalid request signature');
        }
    }

    protected function validateCsrfToken(Request $request): void
    {
        if (!$this->accessControl->validateCsrfToken($request)) {
            $this->auditService->logSecurityEvent('csrf_validation_failed', [
                'ip' => $request->ip()
            ]);
            
            abort(403, 'CSRF token validation failed');
        }
    }

    protected function validateJsonStructure(Request $request): void
    {
        if (!$this->isValidJson($request->getContent())) {
            $this->auditService->logSecurityEvent('invalid_json', [
                'ip' => $request->ip()
            ]);
            
            abort(400, 'Invalid JSON structure');
        }
    }

    protected function auditRequest(Request $request, $response): void
    {
        $this->auditService->logSecurityEvent('http_request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => $response->status()
        ]);
    }

    protected function addSecurityHeaders($response): void
    {
        $response->headers->add([
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';",
            'Permission-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Embedder-Policy' => 'require-corp',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin'
        ]);
    }

    protected function isValidJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
