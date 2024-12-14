<?php

namespace App\Core\Notification\Analytics\Transaction;

class TransactionManager
{
    private array $transactions = [];
    private array $locks = [];
    private array $logs = [];

    public function beginTransaction(string $name): string
    {
        $transactionId = $this->generateTransactionId();
        
        $this->transactions[$transactionId] = [
            'name' => $name,
            'status' => 'active',
            'start_time' => microtime(true),
            'operations' => []
        ];

        return $transactionId;
    }

    public function commit(string $transactionId): void
    {
        if (!$this->validateTransaction($transactionId)) {
            throw new \InvalidArgumentException("Invalid transaction ID: {$transactionId}");
        }

        $this->transactions[$transactionId]['status'] = 'committed';
        $this->transactions[$transactionId]['end_time'] = microtime(true);
        
        $this->releaseLocks($transactionId);
        $this->logTransaction($transactionId, 'commit');
    }

    public function rollback(string $transactionId): void
    {
        if (!$this->validateTransaction($transactionId)) {
            throw new \InvalidArgumentException("Invalid transaction ID: {$transactionId}");
        }

        $this->transactions[$transactionId]['status'] = 'rolled_back';
        $this->transactions[$transactionId]['end_time'] = microtime(true);
        
        $this->releaseLocks($transactionId);
        $this->logTransaction($transactionId, 'rollback');
    }

    public function addOperation(string $transactionId, string $operation, array $data): void
    {
        if (!$this->validateTransaction($transactionId)) {
            throw new \InvalidArgumentException("Invalid transaction ID: {$transactionId}");
        }

        $this->transactions[$transactionId]['operations'][] = [
            'type' => $operation,
            'data' => $data,
            'timestamp' => microtime(true)
        ];
    }

    public function acquireLock(string $transactionId, string $resource): bool
    {
        if (isset($this->locks[$resource])) {
            return false;
        }

        $this->locks[$resource] = $transactionId;
        return true;
    }

    public function getTransactionStatus(string $transactionId): ?array
    {
        return $this->transactions[$transactionId] ?? null;
    }

    public function getTransactionLogs(string $transactionId): array
    {
        return array_filter($this->logs, function($log) use ($transactionId) {
            return $log['transaction_id'] === $transactionId;
        });
    }

    private function validateTransaction(string $transactionId): bool
    {
        return isset($this->transactions[$transactionId]) && 
               $this->transactions[$transactionId]['status'] === 'active';
    }

    private function releaseLocks(string $transactionId): void
    {
        foreach ($this->locks as $resource => $lockTransactionId) {
            if ($lockTransactionId === $transactionId) {
                unset($this->locks[$resource]);
            }
        }
    }

    private function logTransaction(string $transactionId, string $action): void
    {
        $transaction = $this->transactions[$transactionId];
        
        $this->logs[] = [
            'transaction_id' => $transactionId,
            'name' => $transaction['name'],
            'action' => $action,
            'duration' => $transaction['end_time'] - $transaction['start_time'],
            'operations_count' => count($transaction['operations']),
            'timestamp' => time()
        ];
    }

    private function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }
}
