<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface; 
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Exceptions\{SecurityException, ValidationException, UnauthorizedException};
use Illuminate\Support\Facades\DB;

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
     * Executes a critical operation with comprehensive protection
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
            throw new UnauthorizedException('Insufficient permissions for operation');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);  
            throw new RateLimitException('Rate limit exceeded for operation');
        }
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
                throw new OperationException('Operation produced invalid result');
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
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed'); 
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
