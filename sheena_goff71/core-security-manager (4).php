<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{SecurityManagerInterface, ValidationServiceInterface};
use App\Core\Services\{EncryptionService, AuditLogger};
use App\Core\Security\AccessControl;

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    
    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Pre-operation validation
        $this->validateOperation($context);
        
        DB::beginTransaction();
        try {
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->accessControl->hasPermission($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException();
        }
    }

    private function executeWithMonitoring(callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation();
            
            $this->logPerformanceMetrics([
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_peak_usage(true)
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logPerformanceMetrics([
                'execution_time' => microtime(true) - $startTime,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function logPerformanceMetrics(array $metrics): void
    {
        $this->auditLogger->logPerformance($metrics);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'active_transactions' => DB::transactionLevel()
        ];
    }
}
