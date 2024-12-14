<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface, AuditInterface};
use App\Core\Services\{EncryptionService, AuditService, MonitoringService};
use App\Core\Exceptions\{SecurityException, ValidationException, SystemFailureException};
use App\Core\DataTransferObjects\{SecurityContext, OperationResult, ValidationResult};

class CriticalSecurityManager implements SecurityManagerInterface
{
    private ValidationInterface $validator;
    private EncryptionService $encryption;
    private AuditInterface $audit;
    private MonitoringService $monitor;

    public function __construct(
        ValidationInterface $validator,
        EncryptionService $encryption,
        AuditInterface $audit,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): OperationResult 
    {
        $operationId = $this->monitor->startOperation($context);
        $backupId = $this->createPreOperationBackup();

        DB::beginTransaction();
        
        try {
            // Pre-execution security validation
            $this->validateSecurityContext($context);
            
            // Execute operation with monitoring
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            // Post-execution validation
            $this->validateOperationResult($result);
            
            // Verify system state
            $this->verifySystemState();
            
            DB::commit();
            
            $this->audit->logSuccessfulOperation($context, $result);
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->handleOperationFailure($e, $context, $operationId, $backupId);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    protected function validateSecurityContext(SecurityContext $context): void
    {
        // Validate authentication
        if (!$this->validator->validateAuthentication($context->getAuthToken())) {
            throw new ValidationException('Invalid authentication');
        }

        // Validate authorization
        if (!$this->validator->validateAuthorization($context->getPermissions())) {
            throw new ValidationException('Insufficient permissions');
        }

        // Validate request integrity
        if (!$this->encryption->verifyIntegrity($context->getRequestData())) {
            throw new SecurityException('Request integrity validation failed');
        }
    }

    protected function executeWithMonitoring(callable $operation, string $operationId): OperationResult
    {
        return $this->monitor->trackExecution($operationId, function() use ($operation) {
            $startTime = microtime(true);
            
            try {
                $result = $operation();
                
                $this->monitor->recordMetrics($operationId, [
                    'execution_time' => microtime(true) - $startTime,
                    'memory_peak' => memory_get_peak_usage(true),
                    'status' => 'success'
                ]);
                
                return $result;
                
            } catch (\Throwable $e) {
                $this->monitor->recordMetrics($operationId, [
                    'execution_time' => microtime(true) - $startTime,
                    'memory_peak' => memory_get_peak_usage(true),
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ]);
                
                throw $e;
            }
        });
    }

    protected function validateOperationResult(OperationResult $result): void
    {
        if (!$this->validator->validateOperationResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function verifySystemState(): void
    {
        if (!$this->monitor->verifySystemState()) {
            throw new SystemFailureException('System state verification failed');
        }
    }

    protected function handleOperationFailure(
        \Throwable $e,
        SecurityContext $context,
        string $operationId,
        string $backupId
    ): void {
        // Log comprehensive failure details
        $this->audit->logOperationFailure($context, $e, [
            'operation_id' => $operationId,
            'backup_id' => $backupId,
            'system_state' => $this->monitor->captureSystemState(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        // Restore system state if needed
        if ($this->shouldRestoreState($e)) {
            $this->restoreFromBackup($backupId);
        }

        // Increase security measures
        $this->increaseSecurity($context);
    }

    protected function createPreOperationBackup(): string
    {
        // Implementation depends on backup service
        return 'backup-' . uniqid();
    }

    protected function shouldRestoreState(\Throwable $e): bool
    {
        return $e instanceof SystemFailureException 
            || $e instanceof SecurityException;
    }

    protected function restoreFromBackup(string $backupId): void
    {
        // Implementation depends on backup service
    }

    protected function increaseSecurity(SecurityContext $context): void
    {
        // Implement additional security measures
        // Such as increasing monitoring, reducing thresholds, etc.
    }
}
