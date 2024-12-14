<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        ValidationService $validator,
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
     * Execute a protected operation with full security controls
     */
    public function executeSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateContext($context);
            $this->checkPermissions($context);
            
            // Execute with monitoring
            $result = $operation();
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage());
        }
    }

    private function validateContext(SecurityContext $context): void 
    {
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request context');
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->accessControl->hasPermission($context)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        Log::error('Security operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}
