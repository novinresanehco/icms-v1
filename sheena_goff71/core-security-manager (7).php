<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

class SecurityManager implements SecurityManagerInterface 
{
    private $validator;
    private $encryptor;
    private $auditLogger;
    private $accessControl;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryptor,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryptor = $encryptor;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation);
            
            // Verify result integrity
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

    protected function validateOperation(SecurityContext $context): void
    {
        // Validate user permissions
        if (!$this->accessControl->hasPermission($context->getUser(), $context->getRequiredPermission())) {
            throw new SecurityException('Insufficient permissions');
        }

        // Validate input data
        if (!$this->validator->validate($context->getData())) {
            throw new ValidationException('Invalid input data');
        }

        // Additional security checks
        $this->performSecurityChecks($context);
    }

    protected function monitorExecution(callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $executionTime = microtime(true) - $startTime;
            $this->monitorPerformance($executionTime);
        }
    }

    protected function verifyResult($result): void
    {
        if (!$this->validator->verifyResult($result)) {
            throw new SecurityException('Result validation failed');
        }
    }

    protected function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        
        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e);
        }
    }

    protected function performSecurityChecks(SecurityContext $context): void
    {
        // Rate limiting
        if (!$this->accessControl->checkRateLimit($context->getUser())) {
            throw new SecurityException('Rate limit exceeded');
        }

        // IP whitelist check if required
        if ($context->requiresIpCheck() && !$this->accessControl->isIpWhitelisted($context->getIp())) {
            throw new SecurityException('IP not whitelisted');
        }

        // Additional security validations
        if ($this->detectSuspiciousActivity($context)) {
            throw new SecurityException('Suspicious activity detected');
        }
    }

    protected function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof ValidationException ||
               $e->getCode() >= 500;
    }

    protected function handleCriticalFailure(\Exception $e): void
    {
        Log::critical('Critical security failure', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => now()
        ]);
        
        // Notify security team
        // Implement specific critical failure protocols
    }

    protected function monitorPerformance(float $executionTime): void
    {
        if ($executionTime > 1.0) { // 1 second threshold
            Log::warning('Slow security operation detected', [
                'execution_time' => $executionTime,
                'time' => now()
            ]);
        }
    }

    protected function detectSuspiciousActivity(SecurityContext $context): bool
    {
        // Implement suspicious activity detection
        // Pattern analysis, anomaly detection, etc.
        return false;
    }
}
