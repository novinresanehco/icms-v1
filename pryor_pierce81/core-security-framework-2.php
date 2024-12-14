<?php

namespace App\Core\Security;

use App\Core\Exceptions\SecurityException;
use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private CacheManager $cache;

    public function __construct(
        ValidationService $validator,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->cache = $cache;
    }

    public function validateCriticalOperation(array $context): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperationContext($context);
            
            // Check permissions and rate limits
            $this->verifyAccess($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection(function() use ($context) {
                return $this->processOperation($context);
            });
            
            // Validate result integrity
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Security validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateOperationContext(array $context): void
    {
        if (!$this->validator->validate($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    private function verifyAccess(array $context): void 
    {
        if (!$this->accessControl->hasPermission($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException();
        }

        if (!$this->accessControl->checkRateLimit($context)) {
            $this->auditLogger->logRateLimitExceeded($context);
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(callable $operation)
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $endTime = microtime(true);
            $this->recordMetrics([
                'execution_time' => $endTime - $startTime,
                'memory_peak' => memory_get_peak_usage(true)
            ]);
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        $this->cache->invalidateRelated($context);
    }

    private function recordMetrics(array $metrics): void
    {
        // Record performance metrics
    }
}
