<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\SecurityException;
use App\Core\Monitoring\MetricsCollector;

/**
 * Core security system implementing Critical Control Framework
 */
class CoreSecuritySystem implements SecurityInterface 
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

    /**
     * Execute critical operation with comprehensive protection
     *
     * @throws SecurityException
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        // Start monitoring and transaction
        $monitoringId = $this->metrics->startOperation();
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            
            // Validate result
            $this->validateResult($result);
            
            // Commit and log success
            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->metrics->endOperation($monitoringId);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermission())) {
            $this->auditLogger->logUnauthorizedAccess($operation);
            throw new AccessDeniedException();
        }

        // Verify security constraints
        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityConstraintException();
        }
    }

    private function executeWithMonitoring(
        CriticalOperation $operation,
        string $monitoringId
    ): OperationResult {
        return $this->metrics->trackOperation(
            $monitoringId,
            fn() => $operation->execute()
        );
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        // Log detailed failure information
        $this->auditLogger->logFailure($operation, $e, [
            'trace' => $e->getTraceAsString(),
            'context' => $operation->getContext(),
            'system_state' => $this->metrics->getSystemState()
        ]);

        // Execute recovery procedures
        $this->executeRecoveryProcedures($operation);
    }

    private function executeRecoveryProcedures(CriticalOperation $operation): void
    {
        try {
            // Attempt to restore system to safe state
            $operation->rollback();
            $this->auditLogger->logRecovery($operation);
        } catch (\Exception $e) {
            // Log recovery failure but don't throw
            Log::critical('Recovery failed', [
                'operation' => $operation->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
