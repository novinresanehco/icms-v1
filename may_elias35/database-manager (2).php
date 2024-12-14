<?php

namespace App\Core\Database;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class DatabaseManager implements DatabaseInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private ConnectionManager $connections;
    private array $config;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        ConnectionManager $connections,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->connections = $connections;
        $this->config = $config;
    }

    public function executeTransaction(callable $operation): mixed
    {
        $monitoringId = $this->monitor->startOperation('database_transaction');
        
        DB::beginTransaction();
        
        try {
            $result = $this->executeWithRetry($operation);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->monitor->recordFailure($monitoringId, $e);
            
            throw new DatabaseException(
                'Transaction failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function executeQuery(string $query, array $params = []): QueryResult
    {
        $monitoringId = $this->monitor->startOperation('database_query');
        
        try {
            $this->validateQuery($query);
            
            $startTime = microtime(true);
            
            $result = $this->executeWithRetry(function() use ($query, $params) {
                return DB::select($query, $params);
            });
            
            $duration = microtime(true) - $startTime;
            
            $this->monitor->recordMetric($monitoringId, 'query_time', $duration);
            
            if ($duration > $this->config['slow_query_threshold']) {
                $this->monitor->recordSlowQuery($query, $duration);
            }
            
            return new QueryResult($result);
            
        } catch (\Exception $e) {
            $this->handleQueryError($e, $query, $params);
            throw $e;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function executeWithRetry(callable $operation): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $operation();
            } catch (QueryException $e) {
                if (!$this->isRetryableError($e)) {
                    throw $e;
                }
                
                $lastException = $e;
                $attempts++;
                
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    break;
                }
                
                usleep(self::RETRY_DELAY_MS * $attempts);
                
                $this->connections->reconnect();
            }
        }

        throw new DatabaseException(
            'Operation failed after retries',
            previous: $lastException
        );
    }

    private function validateQuery(string $query): void
    {
        if (!$this->security->validateDatabaseOperation($query)) {
            throw new SecurityException('Invalid database operation');
        }
    }

    private function isRetryableError(QueryException $e): bool
    {
        $errorCode = $e->getCode();
        return in_array($errorCode, $this->config['retryable_errors']);
    }

    private function handleQueryError(\Exception $e, string $query, array $params): void
    {
        $this->monitor->recordDatabaseError([
            'query' => $query,
            'params' => $params,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class QueryResult
{
    private array $data;
    private int $count;
    private array $metadata;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->count = count($data);
        $this->metadata = [
            'timestamp' => time(),
            'row_count' => $this->count
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface DatabaseInterface
{
    public function executeTransaction(callable $operation): mixed;
    public function executeQuery(string $query, array $params = []): QueryResult;
}
