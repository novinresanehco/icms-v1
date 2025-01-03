<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\DatabaseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use PDO;

/**
 * Core Database Management System
 * CRITICAL COMPONENT - Handles all database operations with security and optimization
 */
class DatabaseManager
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    private CacheManager $cache;

    // Query cache configuration
    private const QUERY_CACHE_PREFIX = 'query.';
    private const QUERY_CACHE_TTL = 300; // 5 minutes

    // Connection pool configuration
    private const MIN_CONNECTIONS = 5;
    private const MAX_CONNECTIONS = 20;
    private const CONNECTION_TIMEOUT = 5; // seconds

    // Query timeout limits
    private const QUERY_TIMEOUT = 30; // seconds
    private const TRANSACTION_TIMEOUT = 60; // seconds

    private array $connectionPool = [];
    private array $preparedStatements = [];

    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        
        // Initialize connection pool
        $this->initializeConnectionPool();
    }

    /**
     * Executes query with security validation and optimization
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return mixed
     * @throws DatabaseException
     */
    public function query(string $query, array $params = [])
    {
        $operationId = $this->monitor->startOperation('database.query');
        
        try {
            // Validate query security
            $this->security->validateDatabaseQuery($query, $params);
            
            // Get cached result if available
            $cacheKey = $this->generateQueryCacheKey($query, $params);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->monitor->incrementMetric('database.cache.hits');
                return $cached;
            }
            
            // Get connection from pool
            $connection = $this->getConnection();
            
            // Prepare and execute query
            $statement = $this->prepareStatement($connection, $query);
            $this->bindParameters($statement, $params);
            
            // Set query timeout
            $statement->setAttribute(PDO::ATTR_TIMEOUT, self::QUERY_TIMEOUT);
            
            // Execute query with monitoring
            $startTime = microtime(true);
            $result = $statement->execute() ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            $duration = microtime(true) - $startTime;
            
            // Monitor query performance
            $this->monitorQueryPerformance($query, $duration);
            
            // Cache result if appropriate
            if ($this->shouldCacheQuery($query)) {
                $this->cache->set($cacheKey, $result, self::QUERY_CACHE_TTL);
            }
            
            // Return connection to pool
            $this->releaseConnection($connection);
            
            return $result;
            
        } catch (\Throwable $e) {
            Log::error('Database query failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            
            throw new DatabaseException(
                'Failed to execute query: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Executes transaction with proper handling and monitoring
     *
     * @param callable $callback Transaction callback
     * @return mixed
     * @throws DatabaseException
     */
    public function transaction(callable $callback)
    {
        $operationId = $this->monitor->startOperation('database.transaction');
        
        try {
            // Get dedicated connection for transaction
            $connection = $this->getConnection();
            
            // Set transaction timeout
            $connection->setAttribute(PDO::ATTR_TIMEOUT, self::TRANSACTION_TIMEOUT);
            
            // Start transaction
            $connection->beginTransaction();
            
            try {
                $result = $callback($connection);
                $connection->commit();
                return $result;
                
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
            
        } catch (\Throwable $e) {
            Log::error('Database transaction failed', [
                'error' => $e->getMessage()
            ]);
            
            throw new DatabaseException(
                'Transaction failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Return connection to pool
            if (isset($connection)) {
                $this->releaseConnection($connection);
            }
            
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Initializes connection pool
     */
    private function initializeConnectionPool(): void
    {
        for ($i = 0; $i < self::MIN_CONNECTIONS; $i++) {
            $this->connectionPool[] = $this->createConnection();
        }
    }

    /**
     * Creates new database connection
     */
    private function createConnection(): PDO
    {
        $config = config('database.connections.' . config('database.default'));
        
        $connection = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true
            ]
        );
        
        // Set connection timeout
        $connection->setAttribute(PDO::ATTR_TIMEOUT, self::CONNECTION_TIMEOUT);
        
        return $connection;
    }

    /**
     * Gets available connection from pool
     */
    private function getConnection(): PDO
    {
        // Try to get available connection
        foreach ($this->connectionPool as $connection) {
            if (!$connection->inTransaction()) {
                return $connection;
            }
        }
        
        // Create new connection if under limit
        if (count($this->connectionPool) < self::MAX_CONNECTIONS) {
            $connection = $this->createConnection();
            $this->connectionPool[] = $connection;
            return $connection;
        }
        
        // Wait for available connection
        $startTime = time();
        while (time() - $startTime < self::CONNECTION_TIMEOUT) {
            foreach ($this->connectionPool as $connection) {
                if (!$connection->inTransaction()) {
                    return $connection;
                }
            }
            usleep(100000); // 100ms
        }
        
        throw new DatabaseException('No available database connections');
    }

    /**
     * Releases connection back to pool
     */
    private function releaseConnection(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    /**
     * Prepares SQL statement with caching
     */
    private function prepareStatement(PDO $connection, string $query): \PDOStatement
    {
        $key = md5($query);
        
        if (!isset($this->preparedStatements[$key])) {
            $this->preparedStatements[$key] = $connection->prepare($query);
        }
        
        return $this->preparedStatements[$key];
    }

    /**
     * Binds parameters to prepared statement
     */
    private function bindParameters(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = match(gettype($value)) {
                'integer' => PDO::PARAM_INT,
                'boolean' => PDO::PARAM_BOOL,
                'NULL' => PDO::PARAM_NULL,
                default => PDO::PARAM_STR
            };
            
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                $type
            );
        }
    }

    /**
     * Monitors query performance
     */
    private function monitorQueryPerformance(string $query, float $duration): void
    {
        $this->monitor->recordMetric('database.query.time', $duration);
        
        if ($duration > 1.0) { // Slow query threshold: 1 second
            Log::warning('Slow database query detected', [
                'query' => $query,
                'duration' => $duration
            ]);
            
            $this->monitor->incrementMetric('database.slow_queries');
        }
    }

    /**
     * Generates cache key for query
     */
    private function generateQueryCacheKey(string $query, array $params): string
    {
        return self::QUERY_CACHE_PREFIX . md5($query . serialize($params));
    }

    /**
     * Determines if query should be cached
     */
    private function shouldCacheQuery(string $query): bool
    {
        // Don't cache write operations
        $writeOperations = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE'];
        foreach ($writeOperations as $operation) {
            if (stripos($query, $operation) === 0) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Gets database statistics
     */
    public function getStats(): array
    {
        return [
            'connections' => [
                'total' => count($this->connectionPool),
                'active' => count(array_filter($this->connectionPool, fn($conn) => $conn->inTransaction())),
                'idle' => count(array_filter($this->connectionPool, fn($conn) => !$conn->inTransaction()))
            ],
            'prepared_statements' => count($this->preparedStatements),
            'metrics' => [
                'query_time_avg' => $this->monitor->getMetric('database.query.time.avg'),
                'slow_queries' => $this->monitor->getMetric('database.slow_queries'),
                'cache_hits' => $this->monitor->getMetric('database.cache.hits')
            ]
        ];
    }

    /**
     * Resets connection pool
     */
    public function resetConnectionPool(): void
    {
        foreach ($this->connectionPool as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
        
        $this->connectionPool = [];
        $this->initializeConnectionPool();
    }
    
    /**
     * Closes all database connections
     */
    public function closeConnections(): void
    {
        foreach ($this->connectionPool as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
        
        $this->connectionPool = [];
    }
}
