<?php

namespace App\Core\Security;

use App\Core\Logging\AuditLogger;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Protection\BackupManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private BackupManager $backup;
    
    public function __construct(
        ValidationService $validator,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        BackupManager $backup
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->backup = $backup;
    }

    /**
     * Executes a critical operation with comprehensive protection and monitoring
     *
     * @throws SecurityException If security validation fails
     * @throws ValidationException If input validation fails
     * @return OperationResult The result of the operation
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Create backup point
        $backupId = $this->backup->createBackupPoint();
        
        // Start performance monitoring
        $startTime = microtime(true);

        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            
            // Restore from backup if needed
            $this->backup->restoreFromPoint($backupId);
            
            $this->handleFailure($operation, $context, $e);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Record metrics
            $this->recordMetrics($operation, microtime(true) - $startTime);
            
            // Cleanup backup
            $this->backup->cleanupBackupPoint($backupId);
        }
    }

    /**
     * Validates all aspects of the operation before execution
     */
    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    /**
     * Executes the operation with comprehensive monitoring
     */
    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies the integrity of the operation result
     */
    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    /**
     * Handles operation failures with comprehensive logging
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logOperationFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );
    }

    /**
     * Records comprehensive metrics for the operation
     */
    private function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }
}
