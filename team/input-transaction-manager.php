namespace App\Core\Input\Transaction;

class InputTransactionManager
{
    private TransactionStore $store;
    private LockManager $lockManager;
    private StateTracker $stateTracker;
    private JournalManager $journalManager;

    public function __construct(
        TransactionStore $store,
        LockManager $lockManager,
        StateTracker $stateTracker,
        JournalManager $journalManager
    ) {
        $this->store = $store;
        $this->lockManager = $lockManager;
        $this->stateTracker = $stateTracker;
        $this->journalManager = $journalManager;
    }

    public function begin(): Transaction
    {
        $transaction = new Transaction(
            id: Uuid::generate(),
            state: TransactionState::ACTIVE,
            startTime: time()
        );

        $this->store->save($transaction);
        $this->journalManager->logBegin($transaction);

        return $transaction;
    }

    public function commit(Transaction $transaction): void
    {
        $lock = $this->lockManager->acquire($transaction->getId());

        try {
            $this->validateTransaction($transaction);
            $this->stateTracker->checkpoint();
            
            $transaction->setState(TransactionState::COMMITTED);
            $this->store->update($transaction);
            
            $this->journalManager->logCommit($transaction);
            $this->lockManager->release($lock);
        } catch (\Exception $e) {
            $this->handleCommitFailure($transaction, $e);
            throw $e;
        }
    }

    public function rollback(Transaction $transaction): void
    {
        $lock = $this->lockManager->acquire($transaction->getId());

        try {
            $this->stateTracker->restore($transaction->getInitialState());
            
            $transaction->setState(TransactionState::ROLLED_BACK);
            $this->store->update($transaction);
            
            $this->journalManager->logRollback($transaction);
            $this->lockManager->release($lock);
        } catch (\Exception $e) {
            $this->handleRollbackFailure($transaction, $e);
            throw $e;
        }
    }

    private function validateTransaction(Transaction $transaction): void
    {
        if ($transaction->getState() !== TransactionState::ACTIVE) {
            throw new InvalidTransactionStateException(
                "Transaction {$transaction->getId()} is not active"
            );
        }
    }

    private function handleCommitFailure(Transaction $transaction, \Exception $e): void
    {
        $transaction->setState(TransactionState::FAILED);
        $this->store->update($transaction);
        $this->journalManager->logError($transaction, $e);
    }

    private function handleRollbackFailure(Transaction $transaction, \Exception $e): void
    {
        $this->journalManager->logError($transaction, $e);
        throw new RollbackFailureException(
            "Failed to rollback transaction {$transaction->getId()}",
            0,
            $e
        );
    }
}

class LockManager
{
    private array $locks = [];

    public function acquire(string $resourceId): Lock
    {
        if (isset($this->locks[$resourceId])) {
            throw new ResourceLockedException("Resource $resourceId is already locked");
        }

        $lock = new Lock($resourceId, time());
        $this->locks[$resourceId] = $lock;
        
        return $lock;
    }

    public function release(Lock $lock): void
    {
        unset($this->locks[$lock->getResourceId()]);
    }
}

class StateTracker
{
    private array $checkpoints = [];

    public function checkpoint(): void
    {
        $this->checkpoints[] = $this->captureState();
    }

    public function restore(array $state): void
    {
        $this->applyState($state);
    }

    private function captureState(): array
    {
        return [
            'timestamp' => time(),
            'memory' => memory_get_usage(),
            'data' => $this->gatherStateData()
        ];
    }

    private function applyState(array $state): void
    {
        foreach ($state['data'] as $key => $value) {
            $this->restoreStateItem($key, $value);
        }
    }
}

class JournalManager
{
    private StorageAdapter $storage;

    public function logBegin(Transaction $transaction): void
    {
        $this->writeJournalEntry(
            'BEGIN',
            $transaction,
            ['initial_state' => $transaction->getInitialState()]
        );
    }

    public function logCommit(Transaction $transaction): void
    {
        $this->writeJournalEntry(
            'COMMIT',
            $transaction,
            ['final_state' => $transaction->getFinalState()]
        );
    }

    public function logRollback(Transaction $transaction): void
    {
        $this->writeJournalEntry(
            'ROLLBACK',
            $transaction,
            ['restore_point' => $transaction->getInitialState()]
        );
    }

    public function logError(Transaction $transaction, \Exception $error): void
    {
        $this->writeJournalEntry(
            'ERROR',
            $transaction,
            [
                'error_message' => $error->getMessage(),
                'error_trace' => $error->getTraceAsString()
            ]
        );
    }

    private function writeJournalEntry(
        string $type,
        Transaction $transaction,
        array $data
    ): void {
        $entry = new JournalEntry(
            type: $type,
            transactionId: $transaction->getId(),
            data: $data,
            timestamp: time()
        );

        $this->storage->write($entry);
    }
}

class Transaction
{
    public function __construct(
        private string $id,
        private TransactionState $state,
        private int $startTime,
        private ?array $initialState = null,
        private ?array $finalState = null
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getState(): TransactionState
    {
        return $this->state;
    }

    public function setState(TransactionState $state): void
    {
        $this->state = $state;
    }

    public function getInitialState(): ?array
    {
        return $this->initialState;
    }

    public function getFinalState(): ?array
    {
        return $this->finalState;
    }
}

enum TransactionState: string
{
    case ACTIVE = 'active';
    case COMMITTED = 'committed';
    case ROLLED_BACK = 'rolled_back';
    case FAILED = 'failed';
}

class Lock
{
    public function __construct(
        private string $resourceId,
        private int $timestamp
    ) {}

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}

class JournalEntry
{
    public function __construct(
        private string $type,
        private string $transactionId,
        private array $data,
        private int $timestamp
    ) {}
}
