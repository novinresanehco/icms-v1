<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService, 
    AuditService
};
use Illuminate\Support\Facades\{DB, Log};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    /**
     * Execute a critical operation with comprehensive security controls
     *
     * @param callable $operation The operation to execute
     * @param array $context Operation context including user and request data
     * @throws SecurityException If any security validation fails
     * @return mixed Operation result
     */
    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Validate operation context
            $this->validateContext($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and audit
            DB::commit();
            $this->auditSuccess($context, $result, $startTime);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $startTime);
            throw $e;
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateRequest($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkPermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }

        // Additional security validations
        $this->validator->validateSecurityConstraints($context);
    }

    private function executeWithProtection(callable $operation, array $context): mixed
    {
        // Encrypt sensitive data
        $secureContext = $this->encryption->encryptSensitiveData($context);
        
        // Execute with monitoring
        $result = $operation($secureContext);
        
        // Decrypt response if needed
        return $this->encryption->decryptSensitiveData($result);
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result verification failed');
        }
    }

    private function auditSuccess(array $context, $result, float $startTime): void
    {
        $this->audit->logSuccess($context, [
            'duration' => microtime(true) - $startTime,
            'result_hash' => hash('sha256', serialize($result))
        ]);
    }

    private function handleFailure(\Throwable $e, array $context, float $startTime): void
    {
        // Comprehensive failure logging
        $this->audit->logFailure($e, $context, [
            'duration' => microtime(true) - $startTime,
            'trace' => $e->getTraceAsString()
        ]);

        // Alert security team for potential threats
        if ($e instanceof SecurityException) {
            Log::critical('Security violation detected', [
                'exception' => $e->getMessage(),
                'context' => $context
            ]);
        }
    }
}
