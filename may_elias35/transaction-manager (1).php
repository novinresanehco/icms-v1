<?php

namespace App\Core\Transaction;

class TransactionManager
{
    private ConnectionManager $connectionManager;
    private StateTracker $stateTracker;
    private TransactionLogger $logger;
    private LockManager $lockManager;

    public function __construct(
        ConnectionManager $connectionManager,
        StateTracker $stateTracker,
        TransactionLogger $logger,
        LockManager $lockManager
    ) {
        $this->connectionManager = $connectionManager;
        $this->stateTracker = $stateTracker;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
    }

    public function begin(): Transaction
    {
        $transaction = new Transaction($this->generateTransactionId());
        
        foreach ($this->connectionManager->getConnections() as $connection) {
            $connection->beginTransaction();
        }
        
        $this->stateTracker->trackTransaction($transaction);
        $this->logger->logBegin($transaction);
        
        return $transaction;
    }

    public function commit(Transaction $transaction): void
    {
        $this->validateTransaction($transaction);
        
        try {
            foreach ($this->connectionManager->getConnections() as $connection) {
                $connection->commit();
            }
            
            $this->stateTracker->markCommitted($transaction);
            $this->logger->logCommit($transaction);
            
        } catch (\Exception $e) {
            $this->handleCommitFailure($transaction, $e);
            throw $e;
        }
    }

    public function rollback(Transaction $transaction): void
    {
        $this->validateTransaction($transaction);
        
        try {
            foreach ($this->connectionManager->getConnections() as $connection) {
                $connection->rollBack();
            }
            
            $this->stateTracker->markRolledBack($transaction);
            $this->logger->logRollback($transaction);
            
        } catch (\Exception $e) {
            $this->logger->logRollbackFailure($transaction, $e);
            throw $e;
        }
    }

    public function executeInTransaction(callable $callback): mixed
    {
        $transaction = $this->begin();
        
        try {
            $result = $callback($transaction);
            $this->commit($transaction);
            return $result;
            
        } catch (\Exception $e) {
            $this->rollback($transaction);
            throw $e;
        }
    }

    protected function validateTransaction(Transaction $transaction): void
    {
        if (!$this->stateTracker->isActive($transaction)) {
            throw new InvalidTransactionException(
                "Transaction {$transaction->getId()} is not active"
            );
        }
    }

    protected function handleCommitFailure(Transaction $transaction, \Exception $e): void
    {
        $this->stateTracker->markFailed($transaction);
        $this->logger->logCommitFailure($transaction, $e);
        
        try {
            $this->rollback($transaction);
        } catch (\Exception $rollbackException) {
            $this->logger->logRollbackFailure($transaction, $rollbackException);
        }
    }

    protected function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }
}

class Transaction
{
    private string $id;
    private array $operations = [];
    private array $savepoints = [];

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function addOperation(Operation $operation): void
    {
        $this->operations[] = $operation;
    }

    public function createSavepoint(string $name): void
    {
        $this->savepoints[$name] = count($this->operations);
    }

    public function rollbackToSavepoint(string $name): void
    {
        if (!isset($this->savepoints[$name])) {
            throw new SavepointNotFoundException($name);
        }

        $this->operations = array_slice(
            $this->operations,
            0,
            $this->savepoints[$name]
        );
    }

    public function getOperations(): array
    {
        return $this->operations;
    }
}

class StateTracker
{
    private array $transactions = [];

    public function trackTransaction(Transaction $transaction): void
    {
        $this->transactions[$transaction->getId()] = [
            'transaction' => $transaction,
            'status' => TransactionStatus::ACTIVE,
            'start_time' => microtime(true)
        ];
    }

    public function isActive(Transaction $transaction): bool
    {
        return isset($this->transactions[$transaction->getId()]) &&
               $this->transactions[$transaction->getId()]['status'] === TransactionStatus::ACTIVE;
    }

    public function markCommitted(Transaction $transaction): void
    {
        $this->updateStatus($transaction, TransactionStatus::COMMITTED);
    }

    public function markRolledBack(Transaction $transaction): void
    {
        $this->updateStatus($transaction, TransactionStatus::ROLLED_BACK);
    }

    public function markFailed(Transaction $transaction): void
    {
        $this->updateStatus($transaction, TransactionStatus::FAILED);
    }

    protected function updateStatus(Transaction $transaction, string $status): void
    {
        if (isset($this->transactions[$transaction->getId()])) {
            $this->transactions[$transaction->getId()]['status'] = $status;
            $this->transactions[$transaction->getId()]['end_time'] = microtime(true);
        }
    }

    public function getTransactionInfo(Transaction $transaction): ?array
    {
        return $this->transactions[$transaction->getId()] ?? null;
    }
}

class TransactionLogger
{
    private LoggerInterface $logger;

    public function logBegin(Transaction $transaction): void
    {
        $this->logger->info('Transaction started', [
            'transaction_id' => $transaction->getId(),
            'timestamp' => time()
        ]);
    }

    public function logCommit(Transaction $transaction): void
    {
        $this->logger->info('Transaction committed', [
            'transaction_id' => $transaction->getId(),
            'operations' => count($transaction->getOperations()),
            'timestamp' => time()
        ]);
    }

    public function logRollback(Transaction $transaction): void
    {
        $this->logger->info('Transaction rolled back', [
            'transaction_id' => $transaction->getId(),
            'operations' => count($transaction->getOperations()),
            'timestamp' => time()
        ]);
    }

    public function logCommitFailure(Transaction $transaction, \Exception $e): void
    {
        $this->logger->error('Transaction commit failed', [
            'transaction_id' => $transaction->getId(),
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }

    public function logRollbackFailure(Transaction $transaction, \Exception $e): void
    {
        $this->logger->error('Transaction rollback failed', [
            'transaction_id' => $transaction->getId(),
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }
}

interface TransactionStatus
{
    const ACTIVE = 'active';
    const COMMITTED = 'committed';
    const ROLLED_BACK = 'rolled_back';
    const FAILED = 'failed';
}

class Operation
{
    private string $type;
    private string $table;
    private array $data;
    private ?array $conditions;

    public function __construct(
        string $type,
        string $table,
        array $data,
        ?array $conditions = null
    ) {
        $this->type = $type;
        $this->table = $table;
        $this->data = $data;
        $this->conditions = $conditions;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getConditions(): ?array
    {
        return $this->conditions;
    }
}
