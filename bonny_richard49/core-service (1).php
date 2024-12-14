<?php

namespace App\Core\Service;

use App\Core\Interfaces\{
    CriticalServiceInterface,
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface
};
use App\Core\Models\{
    OperationResult,
    ServiceContext,
    ValidationResult
};
use Illuminate\Support\Facades\DB;

/**
 * Critical base service implementing core protection protocols
 */
abstract class CriticalBaseService implements CriticalServiceInterface 
{
    protected SecurityManagerInterface $security;
    protected ValidationServiceInterface $validator;
    protected AuditLoggerInterface $logger;
    protected array $validationRules;
    protected array $securityRules;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        AuditLoggerInterface $logger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->validationRules = $this->getValidationRules();
        $this->securityRules = $this->getSecurityRules();
    }

    /**
     * Executes critical service operation with comprehensive protection
     *
     * @throws ServiceException
     */
    protected function executeCriticalOperation(
        string $operation,
        callable $action,
        ServiceContext $context
    ): OperationResult {
        // Start monitoring
        $operationId = $this->startOperationMonitoring($operation, $context);

        DB::beginTransaction();

        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);

            // Verify security context
            $this->security->validateContext($context);

            // Execute with monitoring
            $result = $this->executeWithMonitoring(
                $operation,
                $action,
                $context,
                $operationId
            );

            // Verify result
            $this->verifyResult($result, $context);

            // Commit transaction
            DB::commit();

            // Log success
            $this->logSuccess($operation, $result, $context);

            return new OperationResult(true, $result);

        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();

            // Handle failure
            $this->handleOperationFailure($e, $operation, $context);

            throw new ServiceException(
                "Critical service operation failed: {$operation}",
                previous: $e
            );
        } finally {
            // Stop monitoring
            $this->stopOperationMonitoring($operationId);
        }
    }

    /**
     * Validates service operation
     */
    protected function validateOperation(
        string $operation,
        ServiceContext $context
    ): ValidationResult {
        $validationResult = $this->validator->validateOperation(
            $operation,
            $context,
            $this->validationRules
        );

        if (!$validationResult->isValid()) {
            throw new ValidationException(
                'Operation validation failed: ' . $validationResult->getErrors()
            );
        }

        return $validationResult;
    }

    /**
     * Executes operation with monitoring
     */
    protected function executeWithMonitoring(
        string $operation,
        callable $action,
        ServiceContext $context,
        string $operationId
    ) {
        return $this->logger->trackOperation(
            $operationId,
            fn() => $action()
        );
    }

    /**
     * Verifies operation result
     */
    protected function verifyResult($result, ServiceContext $context): void
    {
        if (!$this->validator->verifyServiceResult($result, $context)) {
            throw new ValidationException('Service result verification failed');
        }
    }

    /**
     * Start operation monitoring
     */
    protected function startOperationMonitoring(
        string $operation,
        ServiceContext $context
    ): string {
        return $this->logger->startOperation([
            'operation' => $operation,
            'service' => static::class,
            'context' => $context->toArray()
        ]);
    }

    /**
     * Stop operation monitoring
     */
    protected function stopOperationMonitoring(string $operationId): void
    {
        $this->logger->stopOperation($operationId);
    }

    /**
     * Handles operation failure with logging
     */
    protected function handleOperationFailure(
        \Throwable $e,
        string $operation,
        ServiceContext $context
    ): void {
        $this->logger->logFailure([
            'operation' => $operation,
            'service' => static::class,
            'context' => $context->toArray(),
            'exception' => $e
        ]);
    }

    /**
     * Logs successful operation
     */
    protected function logSuccess(
        string $operation,
        $result,
        ServiceContext $context
    ): void {
        $this->logger->logSuccess([
            'operation' => $operation,
            'service' => static::class,
            'context' => $context->toArray(),
            'result' => $result
        ]);
    }

    /**
     * Gets validation rules for service
     */
    abstract protected function getValidationRules(): array;

    /**
     * Gets security rules for service
     */
    abstract protected function getSecurityRules(): array;
}
