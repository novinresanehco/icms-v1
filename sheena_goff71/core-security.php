<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\{SecurityManagerInterface, ValidationServiceInterface};

/**
 * Core security implementation managing all critical security operations
 */
class SecurityManager implements SecurityManagerInterface 
{
    private ValidationServiceInterface $validator;
    private AuthenticationService $auth;
    private AuditLogger $auditLogger;
    private string $encryptionKey;

    public function __construct(
        ValidationServiceInterface $validator,
        AuthenticationService $auth,
        AuditLogger $auditLogger,
        string $encryptionKey
    ) {
        $this->validator = $validator;
        $this->auth = $auth;
        $this->auditLogger = $auditLogger;
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Executes critical operation with full protection
     */
    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        // Pre-operation validation and setup
        $this->validateOperation($context);
        $transactionId = $this->startTransaction($context);

        try {
            // Execute with full monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Post-execution validation
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($transactionId, $context);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $transactionId);
            throw $e;
        }
    }

    /**
     * Validates operation context and permissions
     */
    protected function validateOperation(SecurityContext $context): void 
    {
        if (!$this->auth->validateRequest($context)) {
            throw new SecurityException('Invalid authentication');
        }

        if (!$this->auth->checkPermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }

        if (!$this->validator->validate($context->getData())) {
            throw new ValidationException('Invalid operation data');
        }
    }

    /**
     * Executes operation with monitoring
     */
    protected function executeWithProtection(callable $operation, SecurityContext $context): mixed
    {
        $startTime = microtime(true);

        try {
            return $operation();
        } finally {
            $this->logMetrics($startTime, $context);
        }
    }

    /**
     * Handles operation failure with logging and notifications
     */
    protected function handleFailure(\Throwable $e, SecurityContext $context, string $transactionId): void
    {
        $this->auditLogger->logFailure($e, $context, $transactionId);

        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e, $context);
        }

        Log::critical('Operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context->toArray(),
            'transaction' => $transactionId
        ]);
    }

    /**
     * Starts monitored transaction
     */
    private function startTransaction(SecurityContext $context): string
    {
        DB::beginTransaction();
        return $this->auditLogger->startTransaction($context);
    }

    /**
     * Validates operation result
     */
    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    /**
     * Records operation metrics
     */
    private function logMetrics(float $startTime, SecurityContext $context): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->auditLogger->logMetrics([
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'context' => $context->toArray()
        ]);
    }

    /**
     * Handles security-specific failures
     */
    private function handleSecurityFailure(SecurityException $e, SecurityContext $context): void
    {
        Cache::tags('security')->put(
            "failed_attempts:{$context->getUserId()}", 
            Cache::increment("failed_attempts:{$context->getUserId()}")
        );

        if ($this->shouldLockAccount($context)) {
            $this->auth->lockAccount($context->getUserId());
            $this->auditLogger->logAccountLock($context);
        }
    }
}
