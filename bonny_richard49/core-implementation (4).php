<?php

namespace App\Core\Foundation;

use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditServiceInterface,
    MonitoringServiceInterface
};
use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Core foundation for all CMS operations ensuring security, validation and audit compliance
 */
abstract class CriticalOperation
{
    protected SecurityManagerInterface $security;
    protected ValidationServiceInterface $validator;
    protected AuditServiceInterface $audit;
    protected MonitoringServiceInterface $monitor;
    
    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        AuditServiceInterface $audit,
        MonitoringServiceInterface $monitor
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->monitor = $monitor;
    }

    /**
     * Execute operation with comprehensive protection and monitoring
     *
     * @throws SecurityException
     * @throws ValidationException 
     */
    final public function execute(array $data, SecurityContext $context): OperationResult 
    {
        // Start monitoring
        $operationId = $this->monitor->startOperation();
        
        // Begin transaction
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($data);
            $this->security->validateAccess($context);
            
            // Execute core logic with monitoring
            $result = $this->monitor->trackExecution(function() use ($data) {
                return $this->executeCore($data);
            });
            
            // Validate result
            $this->validateResult($result);
            
            // Commit and audit
            DB::commit();
            $this->audit->logSuccess($operationId, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($e, $operationId, $context);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Core operation logic - must be implemented by concrete classes
     */
    abstract protected function executeCore(array $data): OperationResult;

    /**
     * Validate operation input
     */
    protected function validateOperation(array $data): void
    {
        $validationResult = $this->validator->validate($data, $this->getValidationRules());
        
        if (!$validationResult->isValid()) {
            throw new ValidationException($validationResult->getErrors());
        }
    }

    /**
     * Validate operation result
     */
    protected function validateResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Operation produced invalid result');
        }
    }

    /**
     * Handle operation failure
     */
    protected function handleFailure(\Exception $e, string $operationId, SecurityContext $context): void
    {
        // Log failure
        $this->audit->logFailure($operationId, $e, $context);
        
        // Notify relevant parties
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
        
        // Log detailed error info
        Log::error('Operation failed', [
            'operation' => $operationId,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context->toArray()
        ]);
    }

    /**
     * Get validation rules for operation
     */
    abstract protected function getValidationRules(): array;
}

/**
 * Base repository implementing critical data access patterns
 */
abstract class CriticalRepository
{
    protected ValidationServiceInterface $validator;
    protected AuditServiceInterface $audit;
    
    protected function executeQuery(callable $query): mixed
    {
        return DB::transaction(function() use ($query) {
            $result = $query();
            
            if ($this->requiresValidation()) {
                $this->validateQueryResult($result);
            }
            
            return $result;
        });
    }
    
    protected function validateQueryResult($result): void
    {
        $validation = $this->validator->validateQueryResult($result);
        
        if (!$validation->isValid()) {
            throw new QueryValidationException($validation->getErrors());
        }
    }
    
    protected function requiresValidation(): bool
    {
        return true; // Override in concrete classes if needed
    }
}

/**
 * Service layer base implementing core business logic patterns
 */
abstract class CriticalService
{
    protected SecurityManagerInterface $security;
    protected ValidationServiceInterface $validator;
    protected AuditServiceInterface $audit;
    
    protected function executeBusinessLogic(callable $logic, SecurityContext $context): mixed
    {
        // Validate access
        $this->security->validateAccess($context);
        
        return DB::transaction(function() use ($logic, $context) {
            $result = $logic();
            
            // Audit trail
            $this->audit->logBusinessOperation($context);
            
            return $result;
        });
    }
}
