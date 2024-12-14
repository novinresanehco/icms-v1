<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\{SecurityManagerInterface, ValidatorInterface};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface
{
    private ValidatorInterface $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    
    public function __construct(
        ValidatorInterface $validator,
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
     */
    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            $this->checkPermissions($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            
            // Validate result
            $this->validateResult($result);
            
            // Log success and commit
            $this->logSuccess($context, $result, microtime(true) - $startTime);
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), previous: $e);
        }
    }

    private function validateOperation(SecurityContext $context): void
    {
        if (!$this->validator->validate($context->getData())) {
            $this->auditLogger->logValidationFailure($context);
            throw new ValidationException('Invalid operation data');
        }

        if ($this->detectSuspiciousActivity($context)) {
            $this->auditLogger->logSuspiciousActivity($context);
            throw new SecurityException('Suspicious activity detected');
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->accessControl->hasPermission($context->getUser(), $context->getRequiredPermission())) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Throwable $e, SecurityContext $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function detectSuspiciousActivity(SecurityContext $context): bool
    {
        return $this->accessControl->checkRateLimit($context) || 
               $this->accessControl->detectAnomalies($context);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'time' => microtime(true)
        ];
    }
}
