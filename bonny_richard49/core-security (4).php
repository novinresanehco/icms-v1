<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

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

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $operation();
            
            // Verify result
            $this->verifyResult($result);
            
            // Commit and log
            DB::commit();
            $this->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->recordMetrics($context, microtime(true) - $startTime);
        }
    }

    private function validateOperation(SecurityContext $context): void
    {
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request data');
        }

        if (!$this->accessControl->hasPermission($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new SecurityException('Insufficient permissions');
        }

        if (!$this->accessControl->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->verifyResult($result)) {
            throw new SecurityException('Result validation failed');
        }
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        
        // Handle critical security failures
        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e, $context);
        }
    }

    private function handleSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        Log::critical('Security failure detected', [
            'exception' => $e->getMessage(),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function recordMetrics(SecurityContext $context, float $duration): void
    {
        // Record operation metrics for monitoring
        $metrics = [
            'operation' => $context->getOperation(),
            'duration' => $duration,
            'timestamp' => time(),
            'success' => true
        ];
        
        $this->auditLogger->logMetrics($metrics);
    }
}
