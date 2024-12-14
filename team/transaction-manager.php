<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\DB;

class TransactionManager
{
    private SecurityManager $security;
    private MonitoringService $monitor;

    private int $transactionLevel = 0;
    private array $savepoints = [];
    private array $rollbackHandlers = [];

    /**
     * Begin transaction with protection
     */
    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            DB::beginTransaction();
        } else {
            $savepoint = $this->createSavepoint();
            DB::createSavepoint($savepoint);
            $this->savepoints[] = $savepoint;
        }

        $this->transactionLevel++;
    }

    /**
     * Commit transaction
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new TransactionException('No active transaction');
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            try {
                DB::commit();
                $this->executeCommitHandlers();
            } catch (\Throwable $e) {
                $this->handleTransactionError($e, 'commit');
                throw $e;
            }
        } else {
            array_pop($this->savepoints);
        }
    }

    /**
     * Rollback transaction
     */
    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            throw new TransactionException('No active transaction');
        }

        try {
            if ($this->transactionLevel === 1) {
                DB::rollBack();
                $this->executeRollbackHandlers();
            } else {
                $savepoint = array_pop($this->savepoints);
                DB::rollbackToSavepoint($savepoint);
            }
        } catch (\Throwable $e) {
            $this->handleTransactionError($e, 'rollback');
            throw $e;
        } finally {
            $this->transactionLevel--;
        }
    }

    /**
     * Add rollback handler
     */
    public function onRollback(callable $handler): void
    {
        $this->rollbackHandlers[] = $handler;
    }

    /**
     * Execute critical operation in transaction
     */
    public function execute(callable $operation, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation('transaction');

        try {
            $this->begin();

            $result = $operation();

            $this->commit();

            return $result;

        } catch (\Throwable $e) {
            $this->rollback();
            $this->handleTransactionError($e, 'execute', $context);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Create unique savepoint name
     */
    private function createSavepoint(): string
    {
        return 'sp_' . uniqid();
    }

    /**
     * Execute rollback handlers
     */
    private function executeRollbackHandlers(): void
    {
        foreach ($this->rollbackHandlers as $handler) {
            try {
                $handler();
            } catch (\Throwable $e) {
                $this->handleTransactionError($e, 'rollback_handler');
            }
        }
        $this->rollbackHandlers = [];
    }

    /**
     * Execute commit handlers
     */
    private function executeCommitHandlers(): void
    {
        $this->rollbackHandlers = [];
    }

    /**
     * Handle transaction errors
     */
    private function handleTransactionError(\Throwable $e, string $operation, array $context = []): void
    {
        $this->monitor->recordFailure('transaction', [
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('transaction_failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }
}
