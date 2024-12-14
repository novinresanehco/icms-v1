<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{
    AuthenticationService,
    AuthorizationService,
    ValidationService
};

class SecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function validateAccess(Request $request): ValidationResult 
    {
        // Transaction protection for all security operations
        return DB::transaction(function() use ($request) {
            try {
                // Multi-layered validation
                $validatedRequest = $this->validator->validateRequest($request);
                $authenticatedUser = $this->auth->validateAuthentication($request);
                $this->authz->checkPermissions($authenticatedUser, $validatedRequest);

                // Audit logging
                $this->auditLogger->logSuccessfulAccess($authenticatedUser, $validatedRequest);

                return new ValidationResult(true);

            } catch (SecurityException $e) {
                // Comprehensive security logging
                $this->auditLogger->logSecurityFailure($e);
                throw $e;
            }
        });
    }

    public function executeSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        return DB::transaction(function() use ($operation, $context) {
            // Pre-execution security checks
            $this->validatePreExecution($context);
            
            try {
                // Execute with monitoring
                $result = $operation();
                
                // Post-execution validation
                $this->validateResult($result);
                
                // Log success
                $this->auditLogger->logSuccessfulOperation($context);
                
                return $result;

            } catch (Exception $e) {
                $this->handleSecurityFailure($e, $context);
                throw $e;
            }
        });
    }

    private function validatePreExecution(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityValidationException('Invalid security context');
        }

        if (!$this->authz->checkPermissions($context->getUser(), $context->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Rate limiting check
        $this->validateRateLimit($context);
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityValidationException('Operation result validation failed');
        }
    }

    private function validateRateLimit(SecurityContext $context): void
    {
        $key = "rate_limit:{$context->getUser()->id}:{$context->getOperationType()}";
        
        $attempts = Cache::get($key, 0);
        if ($attempts >= $this->getRateLimit($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        Cache::increment($key);
        Cache::put($key, $attempts + 1, now()->addMinutes(5));
    }

    private function getRateLimit(SecurityContext $context): int
    {
        return match($context->getOperationType()) {
            'critical' => 10,
            'normal' => 100,
            default => 50
        };
    }

    private function handleSecurityFailure(Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logSecurityFailure($e, $context);
        
        if ($e instanceof SecurityException) {
            // Trigger security alerts for immediate response
            event(new SecurityAlertEvent($e, $context));
        }
    }
}
