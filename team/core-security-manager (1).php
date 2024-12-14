<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{AuditService, ValidationService};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private AuditService $auditService;
    private ValidationService $validationService;
    private array $securityConfig;

    public function __construct(
        AuditService $auditService,
        ValidationService $validationService,
        array $securityConfig
    ) {
        $this->auditService = $auditService;
        $this->validationService = $validationService;
        $this->securityConfig = $securityConfig;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $operationId = $this->auditService->startOperation($context);

        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->auditService->logSuccess($operationId, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function validateOperation(array $context): void 
    {
        if (!$this->validationService->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validationService->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    protected function executeWithProtection(callable $operation, array $context): mixed
    {
        return $operation();
    }

    protected function verifyResult($result): void
    {
        if (!$this->validationService->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function handleFailure(\Throwable $e, array $context, string $operationId): void
    {
        $this->auditService->logFailure($operationId, $e, $context);

        Log::critical('Security operation failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}
