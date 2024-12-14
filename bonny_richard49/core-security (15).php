<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityServiceInterface;
use App\Core\Exceptions\SecurityException;
use App\Core\Services\{
    ValidationService,
    AuditService,
    CacheService,
    EncryptionService
};

class SecurityManager implements SecurityServiceInterface 
{
    private ValidationService $validator;
    private AuditService $audit;
    private CacheService $cache;
    private EncryptionService $encryption;

    public function __construct(
        ValidationService $validator,
        AuditService $audit, 
        CacheService $cache,
        EncryptionService $encryption
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->encryption = $encryption;
    }

    public function processCriticalOperation(callable $operation, array $context): mixed
    {
        $operationId = uniqid('op_', true);
        
        // Start monitoring
        $this->audit->startOperationMonitoring($operationId, $context);
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute in transaction
            $result = DB::transaction(function() use ($operation, $context, $operationId) {
                // Execute with monitoring
                $result = $operation();
                
                // Validate result
                $this->validateResult($result);
                
                return $result;
            });
            
            // Log success
            $this->audit->logSuccess($operationId, $context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Log failure with full context
            $this->audit->logFailure($operationId, $context, $e);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // End monitoring
            $this->audit->endOperationMonitoring($operationId);
        }
    }

    protected function validateOperation(array $context): void
    {
        // Validate input data
        if (!$this->validator->validate($context['input'] ?? [])) {
            throw new SecurityException('Invalid input data');
        }

        // Validate permissions
        if (!$this->hasPermission($context['user'] ?? null, $context['permission'] ?? null)) {
            throw new SecurityException('Insufficient permissions');
        }

        // Rate limiting
        $key = 'rate_limit:' . ($context['user']->id ?? 'anonymous');
        if (!$this->cache->increment($key, 1, 60)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Invalid operation result');
        }
    }

    protected function hasPermission($user, $permission): bool
    {
        if (!$user || !$permission) {
            return false;
        }

        return $user->hasPermission($permission);
    }

    public function encrypt(string $data): string
    {
        return $this->encryption->encrypt($data);
    }

    public function decrypt(string $encrypted): string
    {
        return $this->encryption->decrypt($encrypted);
    }

    public function hash(string $data): string
    {
        return password_hash($data, PASSWORD_ARGON2ID, [
            'memory_cost' => 2048,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
}
