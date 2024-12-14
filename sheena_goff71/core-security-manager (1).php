<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoreSecurityManager implements SecurityManagerInterface 
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

    /**
     * Executes critical operations with comprehensive protection
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid input data');
        }

        if (!$this->accessControl->hasPermission($operation->getRequiredPermission())) {
            throw new AccessDeniedException('Insufficient permissions');
        }

        if (!$this->accessControl->checkRateLimit($operation->getRateLimitKey())) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        // Create monitoring context
        $monitor = new OperationMonitor($operation);
        
        return $monitor->execute(function() use ($operation) {
            return $operation->execute();
        });
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result->getData())) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void
    {
        // Detailed failure logging
        $this->auditLogger->logFailure($e, $operation, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->captureSystemState()
        ]);

        // Notify relevant parties if needed
        if ($this->config->get('notifications.enabled')) {
            $this->notifyFailure($e, $operation);
        }
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }
}
