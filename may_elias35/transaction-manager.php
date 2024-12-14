<?php

namespace App\Core\Transaction;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring;
use App\Core\Audit\AuditLogger;

class TransactionManager implements TransactionInterface
{
    private SecurityManager $security;
    private Monitoring $monitor;
    private AuditLogger $audit;

    private const MAX_RETRIES = 3;
    private const TIMEOUT = 5000; // ms
    private const LOCK_TIMEOUT = 10; // seconds

    public function __construct(
        SecurityManager $security,
        Monitoring $monitor,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->audit = $audit;
    }

    public function executeTransaction(Transaction $transaction): TransactionResult
    {
        $transactionId = $this->monitor->startTransaction();
        
        try {
            // Pre-transaction validation
            $this->validateTransaction($transaction);
            
            // Acquire locks
            $this->acquireLocks($transaction);
            
            // Begin transaction
            DB::beginTransaction();
            
            // Execute with retry logic
            $result = $this->executeWithRetry($transaction);
            
            // Validate result
            $this->validateResult($result);
            
            // Commit transaction
            DB::commit();
            
            // Release locks
            $this->releaseLocks($transaction);
            
            // Log success
            $this->audit->logTransactionSuccess($transaction);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $transaction);
            throw $e;
        } finally {
            $this->monitor->endTransaction($transactionId);
        }
    }

    private function validateTransaction(Transaction $transaction): void
    {
        if (!$this->security->validateTransaction($transaction)) {
            throw new SecurityException('Transaction security validation failed');
        }

        if (!$this->validateIntegrity($transaction)) {
            throw new IntegrityException('Transaction integrity validation failed');
        }

        if ($this->isTransactionLocked($transaction)) {
            throw new LockException('Transaction is locked');
        }
    }

    private function executeWithRetry(Transaction $transaction): TransactionResult
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->executeProtected($transaction);
            } catch (RetryableException $e) {
                $lastError = $e;
                $attempt++;
                
                if ($attempt >= self::MAX_RETRIES) {
                    throw new TransactionException(
                        'Transaction failed after max retries',
                        previous: $e
                    );
                }
                
                $this->handleRetry($attempt, $transaction);
            }
        }

        throw new TransactionException(
            'Transaction execution failed',
            previous: $lastError
        );
    }

    private function executeProtected(Transaction $transaction): TransactionResult
    {
        $startTime = microtime(true);
        
        try {
            // Execute transaction operations
            $result = $transaction->execute();
            
            // Check execution time
            if ((microtime(true) - $startTime) * 1000 > self::TIMEOUT) {
                throw new TimeoutException('Transaction timeout exceeded');
            }
            
            return $result;

        } catch (\Exception $e) {
            $this->handleExecutionFailure($e, $transaction);
            throw $e;
        }
    }

    private function validateResult(TransactionResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Invalid transaction result');
        }

        if (!$this->validateResultIntegrity($result)) {
            throw new IntegrityException('Result integrity validation failed');
        }

        if (!$this->security->validateResult($result)) {
            throw new SecurityException('Security validation failed');
        }
    }

    private function acquireLocks(Transaction $transaction): void
    {
        foreach ($transaction->getLockRequirements() as $lock) {
            if (!$this->acquireLock($lock, self::LOCK_TIMEOUT)) {
                throw new LockException("Failed to acquire lock: {$lock}");
            }
        }
    }

    private function releaseLocks(Transaction $transaction): void
    {
        foreach ($transaction->getLockRequirements() as $lock) {
            $this->releaseLock($lock);
        }
    }

    private function validateIntegrity(Transaction $transaction): bool
    {
        return hash_equals(
            $transaction->getChecksum(),
            $this->calculateChecksum($transaction)
        );
    }

    private function validateResultIntegrity(TransactionResult $result): bool
    {
        return hash_equals(
            $result->getChecksum(),
            $this->calculateResultChecksum($result)
        );
    }

    private function calculateChecksum(Transaction $transaction): string
    {
        return hash('sha256', serialize($transaction->getData()));
    }

    private function calculateResultChecksum(TransactionResult $result): string
    {
        return hash('sha256', serialize($result->getData()));
    }

    private function handleTransactionFailure(\Exception $e, Transaction $transaction): void
    {
        // Log failure
        $this->audit->logTransactionFailure(
            'transaction_failed',
            [
                'transaction_id' => $transaction->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );

        // Release locks
        $this->releaseLocks($transaction);

        // Update monitoring metrics
        $this->monitor->recordTransactionFailure($transaction);

        // Handle specific failures
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }

    private function handleExecutionFailure(\Exception $e, Transaction $transaction): void
    {
        $this->audit->logExecutionFailure(
            'execution_failed',
            [
                'transaction_id' => $transaction->getId(),
                'error' => $e->getMessage()
            ]
        );

        $this->monitor->recordExecutionFailure($transaction);
    }

    private function handleRetry(int $attempt, Transaction $transaction): void
    {
        $this->audit->logRetry(
            'transaction_retry',
            [
                'transaction_id' => $transaction->getId(),
                'attempt' => $attempt
            ]
        );

        usleep(100000 * pow(2, $attempt));
    }

    private function isTransactionLocked(Transaction $transaction): bool
    {
        foreach ($transaction->getLockRequirements() as $lock) {
            if ($this->isLocked($lock)) {
                return true;
            }
        }
        return false;
    }

    private function acquireLock(string $lock, int $timeout): bool
    {
        $start = time();
        while (!$this->tryLock($lock)) {
            if (time() - $start >= $timeout) {
                return false;
            }
            usleep(100000); // 100ms
        }
        return true;
    }

    private function tryLock(string $lock): bool
    {
        return Cache::add(
            "lock:{$lock}",
            true,
            self::LOCK_TIMEOUT
        );
    }

    private function releaseLock(string $lock): void
    {
        Cache::forget("lock:{$lock}");
    }

    private function isLocked(string $lock): bool
    {
        return Cache::has("lock:{$lock}");
    }
}
