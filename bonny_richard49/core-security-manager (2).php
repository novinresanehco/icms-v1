<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Exceptions\{SecurityException, ValidationException};
use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface};

/**
 * Core Security Manager - Critical Component
 * Handles all security operations with comprehensive protection
 */
class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    /**
     * Execute critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with protection
            $result = $this->executeWithMonitoring($operation);
            
            // Verify result
            $this->verifyResult($result);
            
            // Commit and log
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Record metrics
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    protected function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context)) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    protected function executeWithMonitoring(CriticalOperation $operation): OperationResult
    {
        // Create monitoring context
        $monitor = new OperationMonitor($operation);
        
        try {
            // Execute with monitoring
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    protected function verifyResult(OperationResult $result): void
    {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result validation failed');
        }

        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    protected function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log detailed failure information
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

        // Notify relevant parties
        $this->notifyFailure($operation, $context, $e);

        // Execute recovery procedures if needed
        $this->executeFailureRecovery($operation, $context, $e);
    }

    protected function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        // Record comprehensive metrics
        MetricsCollector::record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg(),
            'db_connections' => DB::connection()->select('show processlist'),
            'cache_stats' => Cache::getMemcached()->getStats(),
            'time' => microtime(true)
        ];
    }
}
