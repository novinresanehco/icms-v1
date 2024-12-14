<?php

namespace App\Core\Transaction;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\{
    TransactionInterface,
    ValidationInterface,
    AuditInterface
};

class TransactionManager implements TransactionInterface
{
    private ValidationInterface $validator;
    private AuditInterface $audit;

    public function __construct(
        ValidationInterface $validator,
        AuditInterface $audit
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function executeTransaction(callable $operation, array $context): mixed
    {
        // Pre-transaction validation
        $this->validateTransaction($context);
        
        // Create backup point if configured
        $backupId = $this->createBackupIfNeeded($context);
        
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Execute operation with monitoring
            $result = $this->executeWithMonitoring($operation, $context);
            
            // Validate transaction result
            $this->validateResult($result, $context);
            
            DB::commit();
            $this->logSuccess($context, $result, $startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Restore from backup if available
            if ($backupId) {
                $this->restoreFromBackup($backupId);
            }
            
            $this->handleFailure($e, $context);
            throw $e;
        } finally {
            $this->cleanup($backupId);
        }
    }

    protected function validateTransaction(array $context): void
    {
        if (!$this->validator->validateTransactionContext($context)) {
            throw new TransactionException('Invalid transaction context');
        }

        if (!$this->validator->checkTransactionLimits($context)) {
            throw new TransactionException('Transaction limits exceeded');
        }
    }

    protected function executeWithMonitoring(callable $operation, array $context): mixed
    {
        $monitoringId = $this->audit->startTransaction($context);

        try {
            return $operation();
        } finally {
            $this->audit->stopTransaction($monitoringId);
        }
    }

    protected function validateResult(mixed $result, array $context): void
    {
        if (!$this->validator->validateTransactionResult($result, $context)) {
            throw new TransactionException('Invalid transaction result');
        }
    }

    protected function createBackupIfNeeded(array $context): ?string
    {
        if ($context['requires_backup'] ?? false) {
            return $this->createBackupPoint($context);
        }
        return null;
    }

    protected function createBackupPoint(array $context): string
    {
        // Implementation depends on backup system
        return 'backup-' . uniqid();
    }

    protected function restoreFromBackup(string $backupId): void
    {
        // Implementation depends on backup system
    }

    protected function cleanup(?string $backupId): void
    {
        if ($backupId) {
            // Clean up backup files/data
        }
    }

    protected function logSuccess(array $context, mixed $result, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->audit->logTransactionSuccess($context, $result, $duration);
    }

    protected function handleFailure(\Throwable $e, array $context): void
    {
        $this->audit->logTransactionFailure($e, $context);

        if ($e instanceof TransactionException && $e->isCritical()) {
            $this->notifyAdmins($e, $context);
        }
    }

    protected function notifyAdmins(\Throwable $e, array $context): void
    {
        // Implementation depends on notification system
        // But must be handled without throwing exceptions
    }
}
