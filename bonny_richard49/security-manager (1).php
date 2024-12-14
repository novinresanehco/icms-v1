<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Events\SecurityEvent;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Executes a critical operation with full security controls
     *
     * @throws SecurityException
     */
    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result, $context);
            
            DB::commit();
            $this->auditLogger->logSuccess($context);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed security checks', 0, $e);
        }
    }

    private function validateOperation(SecurityContext $context): void 
    {
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request data');
        }

        if (!$this->validator->checkPermissions($context->getUser(), $context->getResource())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        if (!$this->encryption->verifyIntegrity($context->getData())) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function executeWithMonitoring(callable $operation, SecurityContext $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation();
            
            $this->auditLogger->logExecution([
                'duration' => microtime(true) - $startTime,
                'context' => $context,
                'success' => true
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->auditLogger->logExecution([
                'duration' => microtime(true) - $startTime,
                'context' => $context,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function verifyResult($result, SecurityContext $context): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }

        if (!$this->encryption->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        $this->auditLogger->logAccess($context);
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        
        if ($e instanceof SecurityException) {
            event(new SecurityEvent($e, $context));
        }
    }
}
