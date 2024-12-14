<?php

namespace App\Core\State;

class StateManager implements StateInterface
{
    private SecurityManager $security;
    private TransactionManager $transactions;
    private StateStore $store;
    private LockManager $locks;
    private AuditLogger $logger;

    public function beginTransaction(): TransactionContext
    {
        return $this->security->executeCriticalOperation(
            new BeginTransactionOperation(
                $this->transactions,
                $this->store,
                $this->locks
            )
        );
    }

    public function commit(TransactionContext $context): void
    {
        $this->security->executeCriticalOperation(
            new CommitTransactionOperation(
                $context,
                $this->transactions,
                $this->store,
                $this->locks
            )
        );
    }

    public function rollback(TransactionContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RollbackTransactionOperation(
                $context,
                $this->transactions,
                $this->store,
                $this->locks
            )
        );
    }
}

class TransactionManager
{
    private StateStore $store;
    private LockManager $locks;
    private array $transactions = [];

    public function begin(): TransactionContext
    {
        $context = new TransactionContext(
            $this->generateTransactionId(),
            $this->store->getSnapshot()
        );

        $this->transactions[$context->getId()] = $context;
        return $context;
    }

    public function commit(TransactionContext $context): void
    {
        if (!$this->isActive($context)) {
            throw new TransactionException('Invalid transaction');
        }

        $this->store->applyChanges($context->getChanges());
        $this->cleanup($context);
    }

    public function rollback(TransactionContext $context): void
    {
        if (!$this->isActive($context)) {
            throw new TransactionException('Invalid transaction');
        }

        $this->store->restore($context->getSnapshot());
        $this->cleanup($context);
    }

    private function cleanup(TransactionContext $context): void
    {
        $this->locks->releaseAll($context);
        unset($this->transactions[$context->getId()]);
    }

    private function generateTransactionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

class BeginTransactionOperation implements CriticalOperation
{
    private TransactionManager $transactions;
    private StateStore $store;
    private LockManager $locks;

    public function execute(): TransactionContext
    {
        $context = $this->transactions->begin();
        $this->locks->acquireGlobal($context);
        return $context;
    }

    public function getRequiredPermissions(): array
    {
        return ['state.transaction'];
    }
}

class CommitTransactionOperation implements CriticalOperation
{
    private TransactionContext $context;
    private TransactionManager $transactions;
    private StateStore $store;
    private LockManager $locks;

    public function execute(): void
    {
        $this->validateTransaction();
        $this->transactions->commit($this->context);
    }

    private function validateTransaction(): void
    {
        if (!$this->context->isValid()) {
            throw new TransactionException('Invalid transaction state');
        }

        if (!$this->locks->validate($this->context)) {
            throw new TransactionException('Transaction locks invalid');
        }
    }
}

class StateStore
{
    private array $state = [];
    private array $snapshots = [];

    public function set(string $key, $value, TransactionContext $context): void
    {
        $this->validateAccess($key, $context);
        $context->recordChange($key, $this->state[$key] ?? null, $value);
        $this->state[$key] = $value;
    }

    public function get(string $key, TransactionContext $context): mixed
    {
        $this->validateAccess($key, $context);
        return $this->state[$key] ?? null;
    }

    public function getSnapshot(): array
    {
        return array_map(fn($value) => clone $value, $this->state);
    }

    public function restore(array $snapshot): void
    {
        $this->state = $snapshot;
    }

    private function validateAccess(string $key, TransactionContext $context): void
    {
        if (!$context->hasAccess($key)) {
            throw new StateAccessException("Access denied to state key: $key");
        }
    }
}

class LockManager
{
    private array $locks = [];
    private AuditLogger $logger;

    public function acquire(string $resource, TransactionContext $context): bool
    {
        if (isset($this->locks[$resource]) && 
            $this->locks[$resource] !== $context->getId()) {
            return false;
        }

        $this->locks[$resource] = $context->getId();
        $this->logger->logLockAcquisition($resource, $context);
        return true;
    }

    public function release(string $resource, TransactionContext $context): void
    {
        if ($this->validate($resource, $context)) {
            unset($this->locks[$resource]);
            $this->logger->logLockRelease($resource, $context);
        }
    }

    public function validate(string $resource, TransactionContext $context): bool
    {
        return isset($this->locks[$resource]) && 
               $this->locks[$resource] === $context->getId();
    }
}

class TransactionContext
{
    private string $id;
    private array $snapshot;
    private array $changes = [];
    private array $locks = [];
    private bool $valid = true;

    public function recordChange(string $key, $oldValue, $newValue): void
    {
        $this->changes[$key] = [
            'old' => $oldValue,
            'new' => $newValue,
            'time' => microtime(true)
        ];
    }

    public function hasAccess(string $key): bool
    {
        return in_array($key, $this->locks);
    }

    public function invalidate(): void
    {
        $this->valid = false;
    }
}
