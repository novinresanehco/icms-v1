<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\{TransactionException, SecurityException};

class TransactionManager implements TransactionManagerInterface
{
    private SecurityContext $context;
    private AuditLogger $auditLogger;
    private FailsafeManager $failsafe;
    private array $config;
    private array $activeTransactions = [];

    public function execute(callable $operation, array $options = []): mixed
    {
        $transactionId = $this->generateTransactionId();
        $startTime = microtime(true);
        
        try {
            $this->validateContext();
            $this->beginTransaction($transactionId);
            
            $result = $this->executeWithProtection($operation, $transactionId);
            
            $this->commitTransaction($transactionId);
            $this->recordSuccess($transactionId, microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleTransactionFailure($e, $transactionId);
            throw $e;
        }
    }

    public function beginTransaction(string $transactionId): void
    {
        if ($this->isTransactionActive($transactionId)) {
            throw new TransactionException('Transaction already active');
        }

        DB::beginTransaction();
        
        $this->activeTransactions[$transactionId] = [
            'start_time' => microtime(true),
            'user_id' => $this->context->getUserId(),
            'savepoint' => $this->createSavepoint()
        ];
        
        $this->auditLogger->logTransactionStart($transactionId);
    }

    public function commitTransaction(string $transactionId): void
    {
        $this->validateTransaction($transactionId);
        
        try {
            $this->verifyTransactionIntegrity($transactionId);
            DB::commit();
            
            $this->removeSavepoint($transactionId);
            unset($this->activeTransactions[$transactionId]);
            
            $this->auditLogger->logTransactionCommit($transactionId);
            
        } catch (\Throwable $e) {
            $this->handleCommitFailure($e, $transactionId);
            throw $e;
        }
    }

    public function rollbackTransaction(string $transactionId): void
    {
        if (!$this->isTransactionActive($transactionId)) {
            return;
        }

        try {
            DB::rollBack();
            
            $this->restoreFromSavepoint($transactionId);
            unset($this->activeTransactions[$transactionId]);
            
            $this->auditLogger->logTransactionRollback($transactionId);
            
        } catch (\Throwable $e) {
            $this->handleRollbackFailure($e, $transactionId);
            throw $e;
        }
    }

    private function executeWithProtection(callable $operation, string $transactionId): mixed
    {
        $this->monitorTransactionStart($transactionId);
        
        try {
            $result = $operation();
            
            $this->validateResult($result);
            $this->monitorTransactionEnd($transactionId);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->monitorTransactionFailure($transactionId, $e);
            throw $e;
        }
    }

    private function validateContext(): void
    {
        if (!$this->context->isValid()) {
            throw new SecurityException('Invalid security context');
        }

        if ($this->detectAnomalous()) {
            throw new SecurityException('Anomalous transaction activity');
        }
    }

    private function validateTransaction(string $transactionId): void
    {
        if (!$this->isTransactionActive($transactionId)) {
            throw new TransactionException('No active transaction');
        }

        if ($this->isTransactionStale($transactionId)) {
            $this->handleStaleTransaction($transactionId);
            throw new TransactionException('Stale transaction detected');
        }
    }

    private function validateResult($result): void
    {
        if ($result === null && !$this->config['allow_null_results']) {
            throw new TransactionException('Null result not allowed');
        }
    }

    private function isTransactionActive(string $transactionId): bool
    {
        return isset($this->activeTransactions[$transactionId]);
    }

    private function isTransactionStale(string $transactionId): bool
    {
        $transaction = $this->activeTransactions[$transactionId];
        $timeout = $this->config['transaction_timeout'] ?? 30;
        
        return (microtime(true) - $transaction['start_time']) > $timeout;
    }

    private function createSavepoint(): string
    {
        $savepoint = 'sp_' . uniqid();
        DB::unprepared("SAVEPOINT {$savepoint}");
        return $savepoint;
    }

    private function restoreFromSavepoint(string $transactionId): void
    {
        $savepoint = $this->activeTransactions[$transactionId]['savepoint'];
        DB::unprepared("ROLLBACK TO SAVEPOINT {$savepoint}");
    }

    private function removeSavepoint(string $transactionId): void
    {
        $savepoint = $this->activeTransactions[$transactionId]['savepoint'];
        DB::unprepared("RELEASE SAVEPOINT {$savepoint}");
    }

    private function generateTransactionId(): string
    {
        return hash('sha256', uniqid('txn_', true));
    }

    private function detectAnomalous(): bool
    {
        $key = "transaction_count_{$this->context->getUserId()}";
        $limit = $this->config['transaction_limit'] ?? 1000;
        $window = 3600;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, $window);
        }
        
        return $current > $limit;
    }

    private function monitorTransactionStart(string $transactionId): void
    {
        $this->failsafe->monitorTransaction($transactionId, [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ]);
    }

    private function monitorTransactionEnd(string $transactionId): void
    {
        $this->failsafe->completeTransaction($transactionId, [
            'end_time' => microtime(true),
            'memory_end' => memory_get_usage(true)
        ]);
    }

    private function monitorTransactionFailure(string $transactionId, \Throwable $e): void
    {
        $this->failsafe->failTransaction($transactionId, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleTransactionFailure(\Throwable $e, string $transactionId): void
    {
        $this->rollbackTransaction($transactionId);
        
        $this->auditLogger->logTransactionFailure([
            'transaction_id' => $transactionId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleCommitFailure(\Throwable $e, string $transactionId): void
    {
        $this->auditLogger->logCommitFailure([
            'transaction_id' => $transactionId,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        $this->rollbackTransaction($transactionId);
    }

    private function handleRollbackFailure(\Throwable $e, string $transactionId): void
    {
        $this->auditLogger->logRollbackFailure([
            'transaction_id' => $transactionId,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        $this->failsafe->emergencyRecovery($transactionId);
    }

    private function handleStaleTransaction(string $transactionId): void
    {
        $this->auditLogger->logStaleTransaction($transactionId);
        $this->rollbackTransaction($transactionId);
    }
}
