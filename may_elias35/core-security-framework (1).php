<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private ValidationService $validator;
    private AuditService $audit;
    private EncryptionService $encryption;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        ValidationService $validator,
        AuditService $audit,
        EncryptionService $encryption
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->encryption = $encryption;
    }

    public function validateSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateRequest($context);
            $this->checkPermissions($context);
            
            // Execute with monitoring
            $result = $this->executeSecureOperation($operation);
            
            // Verify operation result
            $this->verifyResult($result);
            
            // Commit and audit
            DB::commit();
            $this->audit->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        } finally {
            // Record metrics
            $this->recordMetrics($context, microtime(true) - $startTime);
        }
    }

    private function validateRequest(SecurityContext $context): void
    {
        // Validate all input
        if (!$this->validator->validateInput($context->getInput())) {
            throw new ValidationException('Invalid input data');
        }

        // Verify authentication
        if (!$this->auth->validateAuthentication($context->getUser())) {
            throw new AuthenticationException('Invalid authentication');
        }

        // Check rate limits
        if (!$this->auth->checkRateLimit($context->getUser())) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function checkPermissions(SecurityContext $context): void 
    {
        if (!$this->authz->hasPermission(
            $context->getUser(),
            $context->getRequiredPermission()
        )) {
            $this->audit->logUnauthorizedAccess($context);
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    private function executeSecureOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            throw new OperationException(
                'Operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        // Log failure with full context
        $this->audit->logFailure($e, $context, [
            'trace' => $e->getTraceAsString(),
            'input' => $context->getInput(),
            'user' => $context->getUser()->id,
            'timestamp' => now()
        ]);

        // Notify security team if needed
        if ($e instanceof SecurityException) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function recordMetrics(SecurityContext $context, float $duration): void
    {
        $this->audit->recordMetrics([
            'operation' => $context->getOperationType(),
            'duration' => $duration,
            'user' => $context->getUser()->id,
            'timestamp' => now()
        ]);
    }
}
