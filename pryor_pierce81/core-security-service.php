<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Exceptions\SecurityException;
use App\Interfaces\SecurityServiceInterface;

class SecurityService implements SecurityServiceInterface 
{
    private $encryptionService;
    private $auditService;
    
    public function __construct(
        EncryptionService $encryptionService,
        AuditService $auditService
    ) {
        $this->encryptionService = $encryptionService;
        $this->auditService = $auditService;
    }

    public function validateSecureOperation(callable $operation, array $context = []): mixed
    {
        $this->validateContext($context);
        
        try {
            DB::beginTransaction();
            
            // Create audit trail
            $auditId = $this->auditService->startOperation($context);
            
            // Execute operation with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            
            $this->auditService->completeOperation($auditId, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->handleSecurityFailure($e, $context);
            
            throw new SecurityException(
                'Security validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateContext(array $context): void
    {
        if (empty($context['user_id'])) {
            throw new SecurityException('User context required');
        }

        if (empty($context['permission'])) {
            throw new SecurityException('Permission context required');
        }
        
        // Validate access permissions
        if (!$this->hasPermission($context['user_id'], $context['permission'])) {
            $this->auditService->logUnauthorizedAccess($context);
            throw new SecurityException('Unauthorized access attempt');
        }
    }

    private function executeWithMonitoring(callable $operation): mixed 
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $executionTime = microtime(true) - $startTime;
            
            if ($executionTime > 1.0) { // 1 second threshold
                Log::warning('Long running secure operation detected', [
                    'execution_time' => $executionTime,
                    'memory_used' => memory_get_peak_usage(true)
                ]);
            }
        }
    }

    private function validateResult($result): void
    {
        if ($result === null) {
            throw new SecurityException('Operation produced null result');
        }

        if (is_array($result)) {
            $this->validateArrayResult($result);
        }

        // Add additional result validation as needed
    }

    private function validateArrayResult(array $result): void
    {
        foreach ($result as $key => $value) {
            if ($this->isSensitiveField($key) && !$this->encryptionService->isEncrypted($value)) {
                throw new SecurityException('Sensitive data must be encrypted');
            }
        }
    }

    private function handleSecurityFailure(\Throwable $e, array $context): void
    {
        Log::error('Security failure occurred', [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->auditService->logFailure($e, $context);
        
        if ($this->isSecurityBreach($e)) {
            $this->handleSecurityBreach($context);
        }
    }

    private function isSecurityBreach(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof AuthenticationException ||
               str_contains(strtolower($e->getMessage()), 'security');
    }

    private function handleSecurityBreach(array $context): void
    {
        // Implement security breach protocols
        // This could include:
        // - Blocking user/IP
        // - Notifying security team
        // - Increasing monitoring
    }
}
