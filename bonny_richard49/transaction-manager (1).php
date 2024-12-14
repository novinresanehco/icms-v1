<?php

namespace App\Core\Data;

use App\Core\Exceptions\TransactionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Critical transaction management
 * Ensures absolute data consistency
 */
class TransactionManager
{
    private ValidationService $validator;
    private AuditService $audit;
    private BackupService $backup;

    public function __construct(
        ValidationService $validator,
        AuditService $audit,
        BackupService $backup
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->backup = $backup;
    }

    /**
     * Execute critical transaction with full protection
     * @throws TransactionException on any consistency violation
     */
    public function executeTransaction(callable $operation, array $context): mixed
    {
        // Create pre-transaction snapshot
        $snapshot = $this->backup->createDataSnapshot();
        
        // Start transaction monitoring
        $transactionId = $this->audit->startTransaction($context);
        
        DB::beginTransaction();
        
        try {
            // Execute with validation
            $result = $this->executeWithValidation($operation, $context);
            
            // Verify data consistency
            $this->verifyDataConsistency($result);
            
            // Verify system constraints
            $this->verifySystemConstraints();
            
            DB::commit();
            
            // Clear affected cache
            $this->invalidateCache($context);
            
            $this->audit->commitTransaction($transactionId);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Restore data if needed
            $this->backup->restoreSnapshot($snapshot);
            
            $this->audit->rollbackTransaction($transactionId, $e);
            
            throw new TransactionException(
                'Transaction failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function executeWithValidation(callable $operation, array $context): mixed
    {
        // Pre-execution validation
        $this->validator->validateTransactionContext($context);
        
        $result = $operation();
        
        // Post-execution validation
        $this->validator->validateTransactionResult($result);
        
        return $result;
    }

    protected function verifyDataConsistency($result): void
    {
        if (!$this->validator->verifyDataIntegrity($result)) {
            throw new TransactionException('Data consistency check failed');
        }
    }

    protected function verifySystemConstraints(): void
    {
        if (!$this->validator->verifySystemConstraints()) {
            throw new TransactionException('System constraints violated');
        }
    }

    protected function invalidateCache(array $context): void
    {
        foreach ($context['cache_keys'] ?? [] as $key) {
            Cache::forget($key);
        }
    }
}
