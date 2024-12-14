<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\SecurityException;
use App\Core\Contracts\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface,
    MonitoringServiceInterface
};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditLoggerInterface $auditLogger;
    private MonitoringServiceInterface $monitor;
    private array $config;

    public function __construct(
        ValidationServiceInterface $validator,
        AuditLoggerInterface $auditLogger,
        MonitoringServiceInterface $monitor,
        array $config
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed 
    {
        // Generate unique operation ID for tracking
        $operationId = $this->generateOperationId();
        
        // Start monitoring
        $this->monitor->startOperation($operationId, $context);

        // Begin transaction
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);

            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $operationId);

            // Verify result integrity
            $this->validateResult($result);
            
            // Commit if all validations pass
            DB::commit();
            
            // Log successful operation
            $this->auditLogger->logSuccess($operationId, $context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();
            
            // Log failure with full context
            $this->auditLogger->logFailure($operationId, $e, $context);
            
            // Handle error appropriately
            $this->handleOperationFailure($e, $context);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            // Always stop monitoring
            $this->monitor->stopOperation($operationId);
        }
    }

    private function validateOperation(array $context): void
    {
        // Validate operation context
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        // Check security constraints
        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        // Verify current system state
        if (!$this->validator->verifySystemState()) {
            throw new SecurityException('System state invalid for operation');
        }
    }

    private function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        return $this->monitor->track($operationId, function() use ($operation) {
            return $operation();
        });
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleOperationFailure(\Throwable $e, array $context): void
    {
        // Log critical error with full context
        Log::critical('Critical operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->getSystemState()
        ]);

        // Execute any configured failure handlers
        foreach ($this->config['failure_handlers'] ?? [] as $handler) {
            try {
                $handler($e, $context);
            } catch (\Throwable $handlerError) {
                Log::error('Failure handler error', [
                    'handler' => get_class($handler),
                    'error' => $handlerError->getMessage()
                ]);
            }
        }

        // Trigger emergency protocols if configured
        if ($this->config['emergency_protocol_enabled'] ?? false) {
            $this->triggerEmergencyProtocol($e, $context);
        }
    }

    private function triggerEmergencyProtocol(\Throwable $e, array $context): void
    {
        try {
            // Notify emergency response team
            $this->notifyEmergencyTeam($e, $context);
            
            // Execute emergency procedures
            $this->executeEmergencyProcedures($e);
            
            // Log emergency protocol execution
            Log::emergency('Emergency protocol executed', [
                'error' => $e->getMessage(),
                'context' => $context
            ]);
        } catch (\Throwable $emergencyError) {
            // Log but don't throw to avoid masking original error
            Log::critical('Emergency protocol failed', [
                'error' => $emergencyError->getMessage()
            ]);
        }
    }

    private function generateOperationId(): string 
    {
        return uniqid('op_', true);
    }

    private function notifyEmergencyTeam(\Throwable $e, array $context): void
    {
        // Implementation depends on notification system
        // Must be handled without throwing exceptions
    }

    private function executeEmergencyProcedures(\Throwable $e): void
    {
        // Implementation depends on system requirements
        // Must be handled without throwing exceptions
    }
}
