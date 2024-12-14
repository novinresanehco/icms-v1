// App/Http/Middleware/CriticalSecurityMiddleware.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Security\{SecurityManager, ValidationService};
use App\Exceptions\SecurityViolationException;

class CriticalSecurityMiddleware
{
    private SecurityManager $security;
    private ValidationService $validator;

    public function __construct(SecurityManager $security, ValidationService $validator)
    {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            // Validate request integrity
            $this->validateRequest($request);
            
            // Verify security headers
            $this->verifySecurityHeaders($request);
            
            // Validate authentication state
            $this->validateAuthentication($request);
            
            // Verify authorization
            $this->verifyAuthorization($request);
            
            // Execute with monitoring
            $response = $this->executeWithMonitoring($request, $next);
            
            // Validate response
            $this->validateResponse($response);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->handleSecurityViolation($e, $request);
            throw new SecurityViolationException('Security validation failed', 0, $e);
        }
    }

    private function validateRequest(Request $request): void
    {
        if (!$this->validator->validateRequestIntegrity($request)) {
            throw new SecurityViolationException('Request integrity validation failed');
        }
    }

    private function verifySecurityHeaders(Request $request): void
    {
        $requiredHeaders = [
            'X-Frame-Options',
            'X-XSS-Protection',
            'X-Content-Type-Options',
            'Strict-Transport-Security'
        ];

        foreach ($requiredHeaders as $header) {
            if (!$request->headers->has($header)) {
                throw new SecurityViolationException("Missing required security header: {$header}");
            }
        }
    }

    private function validateAuthentication(Request $request): void
    {
        if (!$this->security->verifyAuthenticationState($request)) {
            throw new SecurityViolationException('Authentication validation failed');
        }
    }

    private function verifyAuthorization(Request $request): void
    {
        if (!$this->security->verifyAuthorization($request)) {
            throw new SecurityViolationException('Authorization verification failed');
        }
    }

    private function executeWithMonitoring(Request $request, Closure $next)
    {
        return $this->security->executeWithMonitoring(
            fn() => $next($request),
            [
                'route' => $request->route()->getName(),
                'method' => $request->method(),
                'ip' => $request->ip()
            ]
        );
    }

    private function validateResponse($response): void
    {
        if (!$this->validator->validateResponseIntegrity($response)) {
            throw new SecurityViolationException('Response integrity validation failed');
        }
    }

    private function handleSecurityViolation(\Exception $e, Request $request): void
    {
        $this->security->logSecurityViolation($e, [
            'request' => [
                'url' => $request->url(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user' => $request->user()?->id
            ],
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}

// App/Http/Middleware/PerformanceMonitoringMiddleware.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Infrastructure\{PerformanceMonitor, MetricsCollector};
use App\Core\Cache\CacheManager;

class PerformanceMonitoringMiddleware
{
    private PerformanceMonitor $monitor;
    private MetricsCollector $metrics;
    private CacheManager $cache;

    public function __construct(
        PerformanceMonitor $monitor,
        MetricsCollector $metrics,
        CacheManager $cache
    ) {
        $this->monitor = $monitor;
        $this->metrics = $metrics;
        $this->cache = $cache;
    }

    public function handle(Request $request, Closure $next)
    {
        // Start monitoring
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        try {
            // Check system state
            $this->verifySystemState();
            
            // Execute request with monitoring
            $response = $this->executeWithMonitoring($request, $next);
            
            // Collect and analyze metrics
            $this->collectMetrics($request, $response, $startTime, $memoryStart);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->handlePerformanceIssue($e, $request);
            throw $e;
        }
    }

    private function verifySystemState(): void
    {
        $systemMetrics = $this->metrics->collectSystemMetrics();
        
        if ($systemMetrics['cpu_usage'] > 80 || $systemMetrics['memory_usage'] > 85) {
            throw new SystemOverloadException('System resources critically high');
        }
    }

    private function executeWithMonitoring(Request $request, Closure $next)
    {
        return $this->monitor->trackOperation(
            fn() => $next($request),
            [
                'route' => $request->route()->getName(),
                'method' => $request->method()
            ]
        );
    }

    private function collectMetrics(
        Request $request,
        $response,
        float $startTime,
        int $memoryStart
    ): void {
        $executionTime = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage(true) - $memoryStart;
        
        $this->metrics->record([
            'execution_time' => $executionTime,
            'memory_usage' => $memoryUsage,
            'route' => $request->route()->getName(),
            'status_code' => $response->status(),
            'timestamp' => now()
        ]);
    }

    private function handlePerformanceIssue(\Exception $e, Request $request): void
    {
        $this->monitor->logPerformanceIssue($e, [
            'request' => [
                'url' => $request->url(),
                'method' => $request->method()
            ],
            'system_state' => $this->metrics->collectSystemMetrics()
        ]);
    }
}

// App/Http/Middleware/DataIntegrityMiddleware.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Security\{IntegrityValidator, ValidationService};
use App\Core\Cache\CacheManager;

class DataIntegrityMiddleware
{
    private IntegrityValidator $validator;
    private ValidationService $validation;
    private CacheManager $cache;

    public function handle(Request $request, Closure $next)
    {
        // Validate request data
        $this->validateRequestData($request);
        
        // Execute with integrity checks
        $response = $this->executeWithIntegrityChecks($request, $next);
        
        // Validate response data
        $this->validateResponseData($response);
        
        return $response;
    }

    private function validateRequestData(Request $request): void
    {
        if (!$this->validator->validateRequestData($request->all())) {
            throw new DataIntegrityException('Request data validation failed');
        }
    }

    private function executeWithIntegrityChecks(Request $request, Closure $next)
    {
        return $this->validator->executeWithIntegrity(
            fn() => $next($request),
            ['context' => 'request_execution']
        );
    }

    private function validateResponseData($response): void
    {
        if (!$this->validator->validateResponseData($response->getContent())) {
            throw new DataIntegrityException('Response data validation failed');
        }
    }
}