<?php

namespace App\Core\Middleware;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationManager;
use App\Core\Monitoring\MonitoringService;

class CriticalOperationMiddleware
{
    private SecurityManager $security;
    private ValidationManager $validator;
    private MonitoringService $monitor;

    public function __construct(
        SecurityManager $security,
        ValidationManager $validator,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    public function handle($request, \Closure $next)
    {
        // Start monitoring
        $operationId = $this->monitor->startOperation();
        
        try {
            // Validate security context
            $this->security->validateRequest($request);
            
            // Validate input data
            $this->validator->validateInput($request->all());
            
            // Execute with monitoring
            $response = $this->executeWithMonitoring($next, $request);
            
            // Validate response
            $this->validator->validateResponse($response);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->handleFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function executeWithMonitoring(\Closure $next, $request)
    {
        return $this->monitor->track(function() use ($next, $request) {
            return $next($request);
        });
    }

    private function handleFailure(\Exception $e, string $operationId): void
    {
        Log::critical('Operation failed', [
            'operation_id' => $operationId,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class SecurityMiddleware
{
    private SecurityManager $security;
    private ValidationManager $validator;

    public function handle($request, \Closure $next)
    {
        // Verify authentication
        $this->security->verifyAuthentication($request);
        
        // Check authorization
        $this->security->checkAuthorization($request);
        
        // Validate security tokens
        $this->validator->validateSecurityTokens($request);
        
        // Track security metrics
        $this->security->trackMetrics($request);
        
        return $next($request);
    }
}

class PerformanceMiddleware
{
    private MonitoringService $monitor;
    private array $thresholds;

    public function handle($request, \Closure $next)
    {
        $startTime = microtime(true);
        
        try {
            $response = $next($request);
            
            $this->validatePerformance(microtime(true) - $startTime);
            
            return $response;
            
        } finally {
            $this->recordMetrics($startTime);
        }
    }

    private function validatePerformance(float $executionTime): void
    {
        if ($executionTime > $this->thresholds['execution_time']) {
            throw new PerformanceException('Performance threshold exceeded');
        }
    }
}

class ValidationMiddleware
{
    private ValidationManager $validator;
    private array $rules;

    public function handle($request, \Closure $next)
    {
        // Validate request data
        $this->validator->validateRequest(
            $request->all(),
            $this->getRulesForRoute($request->route())
        );
        
        $response = $next($request);
        
        // Validate response data
        $this->validator->validateResponse($response);
        
        return $response;
    }

    private function getRulesForRoute($route): array
    {
        return $this->rules[$route->getName()] ?? [];
    }
}

class ErrorHandlerMiddleware
{
    private MonitoringService $monitor;
    private ValidationManager $validator;

    public function handle($request, \Closure $next)
    {
        try {
            return $next($request);
        } catch (\Exception $e) {
            return $this->handleException($e, $request);
        }
    }

    private function handleException(\Exception $e, $request)
    {
        // Log error
        Log::critical('Request failed', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Return appropriate response
        return response()->json([
            'error' => 'Operation failed',
            'message' => $e->getMessage()
        ], 500);
    }
}

class CacheMiddleware
{
    private array $config;
    
    public function handle($request, \Closure $next)
    {
        $cacheKey = $this->generateCacheKey($request);
        
        if ($response = Cache::get($cacheKey)) {
            return $response;
        }
        
        $response = $next($request);
        
        if ($this->isCacheable($request)) {
            Cache::put($cacheKey, $response, $this->getTTL($request));
        }
        
        return $response;
    }

    private function generateCacheKey($request): string
    {
        return 'route:' . $request->path() . ':' . md5(serialize($request->all()));
    }

    private function isCacheable($request): bool
    {
        return !$request->isMethod('POST') && 
               !$request->isMethod('PUT') && 
               !$request->isMethod('DELETE');
    }
}
