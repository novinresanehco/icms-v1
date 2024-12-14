<?php

namespace App\Core\Security;

/**
 * Core security manager handling all critical security operations
 * with comprehensive logging and failure protection.
 */
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

    /**
     * Validates and executes a critical operation with comprehensive protection
     *
     * @param CriticalOperation $operation The operation to execute
     * @param SecurityContext $context Security context including user and permissions
     * @throws SecurityException If any security validation fails
     * @throws ValidationException If input validation fails
     * @return OperationResult The result of the operation
     */
    public function executeCriticalOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): OperationResult {
        // Start transaction and metrics collection
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
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
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Record metrics
            $this->recordMetrics($operation, microtime(true) - $startTime);
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
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
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
        // Create monitoring context
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            // Execute with monitoring
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            // Validate result
            if (!$result->isValid()) {
                throw new OperationException('Operation produced invalid result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
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
