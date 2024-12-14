```php
namespace App\Core\Transaction;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Database\DatabaseManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Exceptions\TransactionException;

class TransactionManager implements TransactionManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private DatabaseManagerInterface $database;
    private CacheManagerInterface $cache;
    private array $activeTransactions = [];

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        DatabaseManagerInterface $database,
        CacheManagerInterface $cache
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->database = $database;
        $this->cache = $cache;
    }

    /**
     * Execute critical transaction with comprehensive protection
     */
    public function executeTransaction(callable $operation, array $context): mixed
    {
        $transactionId = $this->startTransaction($context);

        try {
            // Execute with security verification
            $result = $this->security->executeCriticalOperation(function() use ($operation, $context) {
                // Begin database transaction
                $this->database->beginTransaction();

                // Execute operation
                $result = $operation();

                // Verify transaction integrity
                $this->verifyTransactionIntegrity();

                // Commit if verification passes
                $this->database->commit();

                return $result;
            }, $context);

            // Record successful transaction
            $this->recordTransactionSuccess($transactionId);

            return $result;

        } catch (\Throwable $e) {
            // Handle transaction failure
            $this->handleTransactionFailure($e, $transactionId);
            throw $e;
        } finally {
            $this->endTransaction($transactionId);
        }
    }

    /**
     * Start new transaction with monitoring
     */
    private function startTransaction(array $context): string
    {
        $transactionId = $this->security->generateSecureId();
        
        $this->activeTransactions[$transactionId] = [
            'start_time' => microtime(true),
            'context' => $context,
            'state' => 'started'
        ];

        $this->monitor->startOperation("transaction.$transactionId");

        // Track transaction metrics
        $this->monitor->recordMetric('transaction.started', [
            'id' => $transactionId,
            'type' => $context['type'] ?? 'unknown'
        ]);

        return $transactionId;
    }

    /**
     * Verify transaction integrity
     */
    private function verifyTransactionIntegrity(): void
    {
        // Verify database consistency
        if (!$this->database->verifyConsistency()) {
            throw new TransactionException('Database consistency check failed');
        }

        // Verify cache consistency
        if (!$this->cache->verifyConsistency()) {
            throw new TransactionException('Cache consistency check failed');
        }

        // Verify security state
        if (!$this->security->verifyTransactionSecurity()) {
            throw new TransactionException('Security verification failed');
        }
    }

    /**
     * Record successful transaction completion
     */
    private function recordTransactionSuccess(string $transactionId): void
    {
        $transaction = $this->activeTransactions[$transactionId];
        $duration = microtime(true) - $transaction['start_time'];

        $this->monitor->recordMetric('transaction.completed', [
            'id' => $transactionId,
            'duration' => $duration,
            'type' => $transaction['context']['type'] ?? 'unknown'
        ]);

        // Update transaction state
        $this->activeTransactions[$transactionId]['state'] = 'completed';
    }

    /**
     * Handle transaction failure with rollback
     */
    private function handleTransactionFailure(\Throwable $e, string $transactionId): void
    {
        try {
            // Rollback database changes
            $this->database->rollback();

            // Invalidate affected cache
            $this->invalidateAffectedCache($transactionId);

            // Record failure metrics
            $this->recordTransactionFailure($e, $transactionId);

            // Execute recovery procedures if needed
            $this->executeFailureRecovery($e, $transactionId);

        } catch (\Throwable $recoveryError) {
            // Log recovery failure
            $this->handleRecoveryFailure($recoveryError, $e, $transactionId);
        }
    }

    /**
     * End transaction and cleanup
     */
    private function endTransaction(string $transactionId): void
    {
        if (isset($this->activeTransactions[$transactionId])) {
            $this->monitor->stopOperation("transaction.$transactionId");
            unset($this->activeTransactions[$transactionId]);
        }
    }

    /**
     * Record transaction failure metrics
     */
    private function recordTransactionFailure(\Throwable $e, string $transactionId): void
    {
        $transaction = $this->activeTransactions[$transactionId];
        $duration = microtime(true) - $transaction['start_time'];

        $this->monitor->recordMetric('transaction.failed', [
            'id' => $transactionId,
            'duration' => $duration,
            'error' => $e->getMessage(),
            'type' => $transaction['context']['type'] ?? 'unknown'
        ]);

        $this->monitor->triggerAlert('transaction_failed', [
            'transaction_id' => $transactionId,
            'error' => $e->getMessage(),
            'context' => $transaction['context']
        ]);
    }

    /**
     * Execute failure recovery procedures
     */
    private function executeFailureRecovery(\Throwable $e, string $transactionId): void
    {
        $transaction = $this->activeTransactions[$transactionId];
        
        // Execute type-specific recovery
        if (isset($transaction['context']['type'])) {
            $this->executeTypeSpecificRecovery(
                $transaction['context']['type'],
                $e,
                $transactionId
            );
        }

        // Update transaction state
        $this->activeTransactions[$transactionId]['state'] = 'failed';
    }
}
```
