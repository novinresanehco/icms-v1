<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function executeSecureOperation(SecurityContext $context, callable $operation): mixed
    {
        DB::beginTransaction();
        $operationId = $this->metrics->startOperation($context);

        try {
            $this->validateContext($context);
            $this->checkPermissions($context);
            
            $result = $this->monitorExecution($operationId, $operation);
            
            $this->validateResult($result);
            $this->logSuccess($context, $result);
            
            DB::commit();
            return $result;

        } catch (SecurityException $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context, $operationId);
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSystemFailure($e, $context, $operationId);
            throw new SecurityException('Operation failed', 0, $e);
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$this->validator->validateInput($context->getInput())) {
            $this->auditLogger->logValidationFailure($context);
            throw new ValidationException('Invalid input');
        }

        if (!$this->validator->verifyIntegrity($context->getData())) {
            $this->auditLogger->logIntegrityFailure($context);
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->accessControl->hasPermission($context->getUser(), $context->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException('Insufficient permissions');
        }

        if (!$this->accessControl->checkRateLimit($context->getUser(), $context->getOperation())) {
            $this->auditLogger->logRateLimitExceeded($context);
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function monitorExecution(string $operationId, callable $operation): mixed
    {
        return $this->metrics->trackExecution($operationId, function() use ($operation) {
            return $operation();
        });
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleSecurityFailure(
        SecurityException $e,
        SecurityContext $context,
        string $operationId
    ): void {
        $this->auditLogger->logSecurityFailure(
            $e,
            $context,
            $operationId,
            $this->metrics->getOperationMetrics($operationId)
        );

        $this->notifySecurityTeam($e, $context);
    }

    private function handleSystemFailure(
        \Exception $e,
        SecurityContext $context,
        string $operationId
    ): void {
        $this->auditLogger->logSystemFailure(
            $e,
            $context,
            $operationId,
            $this->metrics->getOperationMetrics($operationId)
        );

        if ($this->isRecoverable($e)) {
            $this->initiateRecovery($context);
        } else {
            $this->notifyAdministrators($e, $context);
        }
    }

    private function isRecoverable(\Exception $e): bool
    {
        return !($e instanceof CriticalException);
    }

    private function initiateRecovery(SecurityContext $context): void
    {
        // Implement recovery logic
    }

    private function logSuccess(SecurityContext $context, $result): void
    {
        $this->auditLogger->logSuccess($context, $result);
    }
}
