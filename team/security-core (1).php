<?php

namespace App\Core\Security;

use App\Core\Contracts\{SecurityManagerInterface, ValidationInterface, AuditInterface};
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationInterface $validator;
    private AuditInterface $auditor;
    private CacheManager $cache;

    public function __construct(
        ValidationInterface $validator,
        AuditInterface $auditor,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->cache = $cache;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Create monitoring session
        $monitorId = $this->startMonitoring($context);
        
        // Begin transaction
        DB::beginTransaction();
        
        try {
            // Validate operation context
            $this->validateContext($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $monitorId);
            
            // Verify result
            $this->validateResult($result);
            
            // Commit transaction
            DB::commit();
            
            // Log success
            $this->auditor->logSuccess($context, $monitorId);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Rollback on any error
            DB::rollBack();
            
            // Log failure with full context
            $this->handleFailure($e, $context, $monitorId);
            
            throw $e;
            
        } finally {
            // Always stop monitoring
            $this->stopMonitoring($monitorId);
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithMonitoring(callable $operation, string $monitorId): mixed
    {
        return $this->cache->remember("operation.$monitorId", 60, function() use ($operation) {
            return $operation();
        });
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(\Throwable $e, array $context, string $monitorId): void
    {
        // Log critical error
        Log::critical('Security operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'monitor_id' => $monitorId
        ]);

        // Record failure in audit log
        $this->auditor->logFailure($e, $context, $monitorId);

        // Clear related cache
        $this->cache->forget("operation.$monitorId");
    }

    private function startMonitoring(array $context): string
    {
        $monitorId = uniqid('op_', true);
        $this->auditor->startOperation($monitorId, $context);
        return $monitorId;
    }

    private function stopMonitoring(string $monitorId): void
    {
        $this->auditor->endOperation($monitorId);
    }
}
