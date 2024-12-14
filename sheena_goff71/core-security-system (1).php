<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Log, DB};
use App\Exceptions\SecurityException;

class CoreSecurityManager
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result 
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new SecurityException('Insufficient permissions');
        }

        if (!$this->accessControl->checkRateLimit($operation->getRateLimitKey())) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor($operation);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new SecurityException('Invalid operation result');
            }

            return $result;
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new SecurityException('Business rule validation failed');
        }
    }

    private function logSuccess(CriticalOperation $operation, OperationResult $result): void
    {
        $this->auditLogger->logSuccess(
            $operation,
            $result,
            ['execution_time' => microtime(true)]
        );
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        $this->auditLogger->logFailure(
            $operation,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->notifyFailure($operation, $e);
    }

    private function recordMetrics(CriticalOperation $operation, float $executionTime): void
    {
        Metrics::record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'connections' => DB::connection()->select('show status like "Threads_connected"')
        ];
    }
}
