<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Contracts\AuditServiceInterface;
use App\Core\Exceptions\SecurityException;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditServiceInterface $audit;
    
    public function __construct(
        ValidationServiceInterface $validator,
        AuditServiceInterface $audit
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
    }

    /**
     * Execute critical operation with comprehensive security controls
     */ 
    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Pre-execution validation
        $this->validateContext($context);

        // Audit start of operation
        $operationId = $this->audit->startOperation($context);

        DB::beginTransaction();

        try {
            // Execute with monitoring 
            $result = $this->executeWithMonitoring($operation, $operationId);

            // Validate result
            $this->validateResult($result);

            DB::commit();
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId); 
            throw $e;
        }
    }

    /**
     * Validate operation context meets all security requirements
     */
    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid security context');
        }
    }

    /**
     * Execute operation with comprehensive monitoring
     */
    private function executeWithMonitoring(callable $operation, string $operationId): mixed 
    {
        try {
            return $operation();
        } finally {
            $this->audit->trackOperation($operationId);
        }
    }

    /**
     * Validate operation result meets security requirements
     */
    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    /**
     * Handle operation failure with full auditing
     */
    private function handleFailure(\Throwable $e, array $context, string $operationId): void
    {
        $this->audit->logFailure($e, $context, $operationId);
    }
}
