<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\SecurityException;

class SecurityManager implements SecurityManagerInterface 
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

    public function validateSecureOperation(SecurityOperation $operation): OperationResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateRequest($operation);
            $this->checkPermissions($operation);
            $this->enforceRateLimits($operation);

            // Execute with monitoring 
            $result = $this->executeSecureOperation($operation);

            // Verify result
            $this->validateResult($result);
            $this->auditSuccess($operation, $result);

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollback();
            $this->handleSecurityFailure($e, $operation);
            throw new SecurityException('Operation failed security validation', 0, $e);
            
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateRequest(SecurityOperation $operation): void
    {
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        if (!$this->validator->validateContext($operation->getContext())) {
            throw new ValidationException('Invalid operation context');
        }
    }

    private function checkPermissions(SecurityOperation $operation): void
    {
        if (!$this->accessControl->hasPermission($operation->getUser(), $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($operation);
            throw new AccessDeniedException('Insufficient permissions');
        }
    }

    private function enforceRateLimits(SecurityOperation $operation): void
    {
        $key = 'rate_limit:' . $operation->getRateLimitKey();
        
        $attempts = Cache::get($key, 0);
        if ($attempts >= $operation->getRateLimit()) {
            throw new RateLimitExceededException();
        }
        
        Cache::increment($key);
        Cache::expire($key, 60);
    }

    private function executeSecureOperation(SecurityOperation $operation): OperationResult
    {
        return $operation->execute();
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleSecurityFailure(\Exception $e, SecurityOperation $operation): void
    {
        $this->auditLogger->logFailure($e, $operation);
        $this->metrics->incrementFailureCount();
        
        if ($e instanceof SecurityException) {
            // Additional handling for security-specific failures
            $this->accessControl->handleSecurityFailure($operation->getUser());
        }
    }

    private function recordMetrics(SecurityOperation $operation, float $duration): void
    {
        $this->metrics->recordOperation([
            'type' => $operation->getType(),
            'duration' => $duration,
            'user' => $operation->getUser()->id,
            'success' => !DB::transactionLevel(),
            'memory' => memory_get_peak_usage(true)
        ]);
    }
}
