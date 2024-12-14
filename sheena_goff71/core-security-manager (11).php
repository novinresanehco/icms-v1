<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Security\Models\SecurityContext;
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
    }

    public function executeSecureOperation(Operation $operation, SecurityContext $context): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(Operation $operation, SecurityContext $context): void
    {
        if (!$this->validator->validateInput($operation->getData(), $operation->getRules())) {
            throw new ValidationException('Invalid operation input');
        }

        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException();
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(Operation $operation, SecurityContext $context): OperationResult
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity verification failed');
        }
    }

    private function handleFailure(Operation $operation, SecurityContext $context, \Exception $e): void
    {
        $this->auditLogger->logFailure($operation, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->getFailureContext()
        ]);

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );
    }

    private function recordMetrics(Operation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }
}
