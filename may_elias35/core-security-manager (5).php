<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\SecurityException;
use App\Core\Interfaces\SecurityManagerInterface;

class CoreSecurityManager implements SecurityManagerInterface 
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

    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $this->validateOperation($operation, $context);
            $result = $this->executeWithProtection($operation, $context);
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        if (!$this->validator->validateInput($operation->getData(), $operation->getValidationRules())) {
            throw new ValidationException('Operation input validation failed');
        }

        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions for operation');
        }

        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): OperationResult
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
        if (!$this->validator->verifyResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        if (!$result->isValid()) {
            throw new OperationException('Operation produced invalid result');
        }
    }

    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void
    {
        $this->auditLogger->logFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->getSystemState()
            ]
        );

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );
    }

    private function recordMetrics(CriticalOperation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    private function getSystemState(): array 
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg(),
            'time' => microtime(true)
        ];
    }
}
