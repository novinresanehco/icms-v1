<?php

namespace App\Core\Middleware;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationManager;

class CriticalOperationMiddleware
{
    private SecurityContext $security;
    private ValidationManager $validator;
    private AuditManager $audit;

    public function handle($request, Closure $next)
    {
        // Validate security context
        $this->validateSecurityContext($request);

        // Validate operation
        $this->validateOperation($request);

        // Execute with protection
        $response = $this->executeProtected($next, $request);

        // Validate response
        $this->validateResponse($response);

        return $response;
    }

    private function validateSecurityContext($request): void
    {
        if (!$this->security->isValid()) {
            throw new SecurityContextException();
        }
    }

    private function validateOperation($request): void
    {
        $operation = $request->operation();
        
        if (!$this->validator->validateCriticalOperation($operation)) {
            throw new ValidationException();
        }
    }

    private function executeProtected($next, $request)
    {
        return DB::transaction(function() use ($next, $request) {
            return $next($request);
        });
    }

    private function validateResponse($response): void
    {
        if (!$this->validator->validateResponse($response)) {
            throw new ResponseValidationException();
        }
    }
}

class SecurityMiddleware
{
    private SecurityManager $security;
    private AuditLogger $audit;

    public function handle($request, Closure $next)
    {
        // Verify authentication
        $this->verifyAuthentication($request);

        // Check permissions
        $this->checkPermissions($request);

        // Execute with audit
        return $this->executeWithAudit($next, $request);
    }

    private function verifyAuthentication($request): void
    {
        if (!$this->security->verifyAuthentication($request)) {
            throw new AuthenticationException();
        }
    }

    private function checkPermissions($request): void
    {
        if (!$this->security->checkPermissions($request)) {
            throw new AuthorizationException();
        }
    }

    private function executeWithAudit($next, $request)
    {
        $this->audit->logAccess($request);
        
        try {
            $response = $next($request);
            $this->audit->logSuccess($request, $response);
            return $response;
        } catch (\Exception $e) {
            $this->audit->logFailure($request, $e);
            throw $e;
        }
    }
}

class ValidationMiddleware
{
    private ValidationManager $validator;
    private SecurityContext $security;

    public function handle($request, Closure $next)
    {
        // Validate input
        $this->validateInput($request);

        // Execute with validation
        $response = $this->executeWithValidation($next, $request);

        // Validate output
        $this->validateOutput($response);

        return $response;
    }

    private function validateInput($request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new InputValidationException();
        }
    }

    private function executeWithValidation($next, $request)
    {
        return $this->security->executeSecure(fn() => $next($request));
    }

    private function validateOutput($response): void
    {
        if (!$this->validator->validateResponse($response)) {
            throw new OutputValidationException();
        }
    }
}

class MonitoringMiddleware
{
    private MonitoringService $monitor;
    private MetricsCollector $metrics;

    public function handle($request, Closure $next)
    {
        $startTime = microtime(true);
        $monitoringId = $this->monitor->startOperation();

        try {
            $response = $next($request);
            
            $this->recordSuccess($monitoringId, $startTime);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->recordFailure($monitoringId, $e);
            throw $e;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function recordSuccess(string $id, float $startTime): void
    {
        $this->metrics->recordSuccess(
            $id,
            microtime(true) - $startTime
        );
    }

    private function recordFailure(string $id, \Exception $e): void
    {
        $this->metrics->recordFailure($id, $e);
    }
}
