<?php

namespace App\Core\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Repository\RepositoryInterface;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Security\AuditService;
use App\Core\Monitoring\PerformanceMonitor;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Exceptions\ServiceException;

abstract class BaseService implements ServiceInterface 
{
    protected RepositoryInterface $repository;
    protected SecurityManagerInterface $security;
    protected AuditService $audit;
    protected PerformanceMonitor $monitor;
    protected CacheManagerInterface $cache;

    public function __construct(
        RepositoryInterface $repository,
        SecurityManagerInterface $security,
        AuditService $audit,
        PerformanceMonitor $monitor,
        CacheManagerInterface $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->audit = $audit;
        $this->monitor = $monitor;
        $this->cache = $cache;
    }

    protected function executeSecureOperation(string $operation, callable $action, array $context = []): mixed
    {
        $startTime = microtime(true);
        $operationId = uniqid('op_', true);

        try {
            // Security pre-check
            $this->security->validateOperation($operation, $context);
            
            // Start transaction and monitoring
            DB::beginTransaction();
            $this->monitor->startOperation($operationId, $operation);
            
            // Execute operation
            $result = $this->executeWithRetry($action, $context);
            
            // Validate result and commit
            $this->validateOperationResult($result, $operation);
            DB::commit();
            
            // Log success
            $this->logSuccess($operation, $context, $operationId);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $operation, $context, $operationId);
            throw $e;
            
        } finally {
            // Record metrics
            $duration = microtime(true) - $startTime;
            $this->monitor->recordMetrics($operationId, $operation, $duration);
            $this->monitor->stopOperation($operationId);
        }
    }

    protected function executeWithRetry(callable $action, array $context, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $action();
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                
                if (!$this->isRetryableException($e) || $attempt >= $maxRetries) {
                    throw $e;
                }
                
                $this->handleRetryAttempt($e, $attempt, $context);
                usleep(min(100 * pow(2, $attempt), 1000000)); // Exponential backoff
            }
        }

        throw $lastException;
    }

    protected function validateOperationResult($result, string $operation): void
    {
        if ($result === null || $result === false) {
            throw new ServiceException("Operation $operation failed validation");
        }

        if (is_object($result) && method_exists($result, 'isValid')) {
            if (!$result->isValid()) {
                throw new ServiceException("Operation $operation produced invalid result");
            }
        }
    }

    protected function handleOperationFailure(
        \Throwable $e, 
        string $operation, 
        array $context,
        string $operationId
    ): void {
        // Log detailed error
        Log::error("Operation $operation failed", [
            'operation_id' => $operationId,
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        // Record failure metrics
        $this->monitor->recordFailure($operationId, $operation, $e);

        // Audit log
        $this->audit->logFailure($operation, $context, $e);

        // Invalidate relevant caches
        $this->invalidateRelatedCaches($operation, $context);

        // Execute failure recovery if defined
        $this->executeFailureRecovery($operation, $context, $e);
    }

    protected function logSuccess(string $operation, array $context, string $operationId): void
    {
        $this->audit->logSuccess($operation, $context, [
            'operation_id' => $operationId,
            'timestamp' => now()
        ]);
    }

    protected function isRetryableException(\Throwable $e): bool
    {
        return $e instanceof \PDOException || 
               $e instanceof \Illuminate\Database\QueryException ||
               ($e instanceof ServiceException && $e->isRetryable());
    }

    protected function handleRetryAttempt(\Throwable $e, int $attempt, array $context): void
    {
        Log::warning("Retry attempt $attempt for operation", [
            'exception' => $e->getMessage(),
            'context' => $context
        ]);
    }

    protected function invalidateRelatedCaches(string $operation, array $context): void
    {
        $tags = $this->getCacheTagsForOperation($operation, $context);
        if (!empty($tags)) {
            $this->cache->tags($tags)->flush();
        }
    }

    protected function executeFailureRecovery(
        string $operation, 
        array $context, 
        \Throwable $e
    ): void {
        // Implement specific recovery logic in child classes
    }

    abstract protected function getCacheTagsForOperation(
        string $operation, 
        array $context
    ): array;
}
