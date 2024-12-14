```php
namespace App\Core\Repository\Transaction;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\{TransactionException, RecoveryException};

trait TransactionManagement
{
    protected bool $transactionActive = false;
    protected int $transactionAttempts = 3;
    protected array $transactionLog = [];
    
    protected function executeInTransaction(callable $operation, array $options = []): mixed
    {
        $attempts = $options['attempts'] ?? $this->transactionAttempts;
        $attempt = 0;

        while ($attempt < $attempts) {
            try {
                $this->beginTransaction();
                $result = $operation();
                $this->commit();
                
                $this->logTransactionSuccess($operation);
                return $result;
                
            } catch (\Exception $e) {
                $this->handleTransactionError($e, ++$attempt, $attempts);
            }
        }

        throw new TransactionException("Transaction failed after {$attempts} attempts");
    }

    protected function beginTransaction(): void
    {
        if (!$this->transactionActive) {
            DB::beginTransaction();
            $this->transactionActive = true;
            $this->logTransactionEvent('begin');
        }
    }

    protected function commit(): void
    {
        if ($this->transactionActive) {
            DB::commit();
            $this->transactionActive = false;
            $this->logTransactionEvent('commit');
        }
    }

    protected function rollback(): void
    {
        if ($this->transactionActive) {
            DB::rollBack();
            $this->transactionActive = false;
            $this->logTransactionEvent('rollback');
        }
    }

    protected function handleTransactionError(\Exception $e, int $attempt, int $maxAttempts): void
    {
        $this->rollback();
        $this->logTransactionError($e, $attempt);

        if ($attempt >= $maxAttempts) {
            throw new TransactionException(
                "Maximum transaction attempts ({$maxAttempts}) reached: {$e->getMessage()}", 
                0, 
                $e
            );
        }

        // Wait before retrying (exponential backoff)
        sleep(min(5, $attempt * 2));
    }

    protected function logTransactionEvent(string $event): void
    {
        $this->transactionLog[] = [
            'event' => $event,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
    }

    protected function logTransactionError(\Exception $e, int $attempt): void
    {
        Log::error("Transaction attempt {$attempt} failed", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'transaction_log' => $this->transactionLog
        ]);
    }

    protected function logTransactionSuccess(callable $operation): void
    {
        Log::info("Transaction completed successfully", [
            'operation' => get_class($operation),
            'duration' => $this->calculateTransactionDuration(),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }

    protected function calculateTransactionDuration(): float
    {
        $start = collect($this->transactionLog)
            ->firstWhere('event', 'begin')['timestamp'] ?? 0;
        $end = collect($this->transactionLog)
            ->lastWhere('event', 'commit')['timestamp'] ?? microtime(true);

        return $end - $start;
    }
}

class ErrorRecoveryManager
{
    protected array $recoveryStrategies = [];
    protected array $errorLog = [];

    public function registerRecoveryStrategy(string $exceptionClass, callable $strategy): void
    {
        $this->recoveryStrategies[$exceptionClass] = $strategy;
    }

    public function attemptRecovery(\Exception $e, $context = null): mixed
    {
        $this->logError($e, $context);

        foreach ($this->recoveryStrategies as $exceptionClass => $strategy) {
            if ($e instanceof $exceptionClass) {
                try {
                    return $strategy($e, $context);
                } catch (\Exception $recoveryException) {
                    $this->logRecoveryFailure($e, $recoveryException);
                    continue;
                }
            }
        }

        throw new RecoveryException(
            "No successful recovery strategy found for: " . get_class($e),
            0,
            $e
        );
    }

    protected function logError(\Exception $e, $context): void
    {
        $this->errorLog[] = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context,
            'timestamp' => now(),
            'trace' => $e->getTraceAsString()
        ];

        Log::error("Error occurred requiring recovery", [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context
        ]);
    }

    protected function logRecoveryFailure(\Exception $original, \Exception $recovery): void
    {
        Log::error("Recovery attempt failed", [
            'original_exception' => get_class($original),
            'recovery_exception' => get_class($recovery),
            'message' => $recovery->getMessage()
        ]);
    }
}

abstract class TransactionalRepository
{
    use TransactionManagement;

    protected ErrorRecoveryManager $recoveryManager;

    public function __construct(ErrorRecoveryManager $recoveryManager)
    {
        $this->recoveryManager = $recoveryManager;
        $this->registerRecoveryStrategies();
    }

    abstract protected function registerRecoveryStrategies(): void;

    protected function executeWithRecovery(callable $operation, array $context = []): mixed
    {
        try {
            return $this->executeInTransaction($operation);
        } catch (TransactionException $e) {
            return $this->recoveryManager->attemptRecovery($e, $context);
        }
    }
}

class ContentRepository extends TransactionalRepository
{
    protected function registerRecoveryStrategies(): void
    {
        // Register specific recovery strategies for content operations
        $this->recoveryManager->registerRecoveryStrategy(
            \PDOException::class,
            function ($e, $context) {
                // Attempt to recover from database errors
                return $this->handleDatabaseError($e, $context);
            }
        );

        $this->recoveryManager->registerRecoveryStrategy(
            \Illuminate\Database\QueryException::class,
            function ($e, $context) {
                // Attempt to recover from query errors
                return $this->handleQueryError($e, $context);
            }
        );
    }

    public function createContent(array $data): mixed
    {
        return $this->executeWithRecovery(
            fn() => $this->performContentCreation($data),
            ['operation' => 'create', 'data' => $data]
        );
    }

    protected function handleDatabaseError(\PDOException $e, array $context): mixed
    {
        // Implement database error recovery logic
        if ($e->getCode() === '23000') { // Duplicate entry
            // Handle duplicate entry error
            return $this->handleDuplicateEntry($context);
        }

        throw $e;
    }

    protected function handleQueryError(\Illuminate\Database\QueryException $e, array $context): mixed
    {
        // Implement query error recovery logic
        if (str_contains($e->getMessage(), 'Deadlock')) {
            // Handle deadlock by retrying
            return $this->retryOperation($context);
        }

        throw $e;
    }
}
```

This implementation includes:

1. Transaction Management Trait:
   - Robust transaction handling
   - Automatic retries
   - Error logging
   - Performance monitoring

2. Error Recovery Manager:
   - Strategy pattern for error handling
   - Comprehensive error logging
   - Recovery attempt tracking
   - Context preservation

3. Transactional Repository:
   - Integration of transaction and recovery
   - Strategy registration
   - Context management

4. Content Repository Example:
   - Specific error handling strategies
   - Database error recovery
   - Query error handling

Would you like me to continue with:
1. Implementation of more recovery strategies
2. Advanced transaction monitoring
3. Performance optimization for transactions
4. Integration with event system
5. Additional repository implementations

Please let me know which aspect you'd like to focus on next.