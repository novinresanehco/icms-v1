<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,  
        AccessControl $accessControl,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->metrics = $metrics;
    }

    public function validateRequest(Request $request): void
    {
        DB::beginTransaction();
        
        try {
            // Validate input 
            $this->validator->validate($request->all());

            // Check permissions
            $this->accessControl->checkPermissions(
                $request->user(),
                $request->getResourceId()
            );

            // Verify integrity
            $this->validator->verifyIntegrity($request->getContent());

            // Log access
            $this->auditLogger->logAccess($request);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $request);
            throw $e;
        }
    }

    public function processOperation(Operation $operation): Result 
    {
        $operationId = $this->metrics->startOperation($operation->getType());

        try {
            // Pre-execution validation
            $this->validateOperation($operation);

            // Execute with monitoring
            $result = $this->executeOperation($operation);

            // Verify result
            $this->validateResult($result);

            // Record metrics
            $this->metrics->recordSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            $this->metrics->recordFailure($operationId, $e);
            throw new SecurityException('Operation failed', 0, $e);
        }
    }

    private function validateOperation(Operation $operation): void 
    {
        // Validate input parameters
        if (!$this->validator->validateParameters($operation->getParameters())) {
            throw new ValidationException('Invalid operation parameters');
        }

        // Check authorization
        if (!$this->accessControl->isAuthorized($operation->getUser(), $operation->getType())) {
            throw new UnauthorizedException('Operation not permitted');
        }

        // Verify integrity
        if (!$this->validator->verifyIntegrity($operation->getData())) {
            throw new IntegrityException('Data integrity check failed'); 
        }

        // Rate limiting
        if (!$this->accessControl->checkRateLimit($operation->getUser(), $operation->getType())) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeOperation(Operation $operation): Result
    {
        // Create execution context
        $context = new ExecutionContext(
            $operation->getUser(),
            $operation->getType(),
            $operation->getParameters()
        );

        // Log start
        $this->auditLogger->logOperationStart($context);

        try {
            // Execute with monitoring
            $result = $operation->execute();

            // Log completion
            $this->auditLogger->logOperationComplete($context, $result);

            return $result;

        } catch (\Exception $e) {
            // Log failure
            $this->auditLogger->logOperationFailure($context, $e);
            throw $e;
        }
    }

    private function validateResult(Result $result): void
    {
        // Verify result integrity
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result validation failed');
        }

        // Check business rules
        if (!$this->validator->validateBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    private function handleFailure(\Exception $e, $context): void
    {
        // Log detailed failure information
        $this->auditLogger->logError($e, [
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        // Collect metrics
        $this->metrics->recordFailure(
            get_class($e),
            $context instanceof Request ? $context->getUri() : null
        );

        // Notify if critical
        if ($e instanceof CriticalException) {
            $this->notifyCriticalError($e, $context);
        }
    }

    private function notifyCriticalError(\Exception $e, $context): void
    {
        // Send immediate notification
        event(new CriticalErrorOccurred($e, $context));
    }
}
