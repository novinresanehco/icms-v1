namespace App\Core\Database;

class DatabaseService implements DatabaseInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private ConnectionManager $connection;
    private QueryBuilder $builder;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics,
        ConnectionManager $connection,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->connection = $connection;
        $this->audit = $audit;
        $this->builder = $this->connection->getQueryBuilder();
    }

    public function select(string $query, array $bindings = []): array
    {
        $startTime = microtime(true);

        try {
            $this->validateQuery($query, $bindings);

            return $this->security->executeCriticalOperation(
                new DatabaseSelectOperation(
                    $query,
                    $bindings,
                    $this->connection
                ),
                SecurityContext::fromRequest()
            );
        } finally {
            $this->recordMetrics('select', $startTime);
        }
    }

    public function insert(string $table, array $data): int
    {
        return $this->security->executeCriticalOperation(
            new DatabaseInsertOperation(
                $table,
                $this->validateData($data),
                $this->connection,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function update(string $table, array $data, array $conditions): int
    {
        return $this->security->executeCriticalOperation(
            new DatabaseUpdateOperation(
                $table,
                $this->validateData($data),
                $conditions,
                $this->connection,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function delete(string $table, array $conditions): int
    {
        return $this->security->executeCriticalOperation(
            new DatabaseDeleteOperation(
                $table,
                $conditions,
                $this->connection,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function transaction(callable $callback)
    {
        $startTime = microtime(true);

        try {
            $this->connection->beginTransaction();
            
            $result = $callback($this);
            
            $this->connection->commit();
            return $result;

        } catch (\Exception $e) {
            $this->connection->rollBack();
            $this->handleTransactionFailure($e);
            throw $e;
        } finally {
            $this->recordMetrics('transaction', $startTime);
        }
    }

    private function validateQuery(string $query, array $bindings): void
    {
        if (!$this->builder->validateQuery($query)) {
            throw new InvalidQueryException('Invalid SQL query structure');
        }

        if (!$this->validateBindings($bindings)) {
            throw new InvalidBindingException('Invalid query bindings');
        }

        $this->detectSQLInjection($query, $bindings);
    }

    private function validateData(array $data): array
    {
        foreach ($data as $field => $value) {
            if (!$this->validateField($field, $value)) {
                throw new ValidationException("Invalid value for field: {$field}");
            }
        }

        return $data;
    }

    private function validateField(string $field, $value): bool
    {
        $schema = $this->connection->getTableSchema();
        
        if (!isset($schema[$field])) {
            return false;
        }

        return $this->validateDataType($value, $schema[$field]['type']);
    }

    private function validateDataType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value) && strlen($value) <= 255,
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'date' => $value instanceof \DateTime,
            default => true
        };
    }

    private function validateBindings(array $bindings): bool
    {
        foreach ($bindings as $value) {
            if (!$this->isValidBindingValue($value)) {
                return false;
            }
        }
        
        return true;
    }

    private function detectSQLInjection(string $query, array $bindings): void
    {
        $patterns = [
            '/\bUNION\b/i',
            '/\bSELECT\b.*\bFROM\b.*\bINFORMATION_SCHEMA\b/i',
            '/;\s*DROP\s+TABLE/i',
            '/;\s*DELETE\s+FROM/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $this->audit->logSecurityEvent(
                    SecurityEventType::SQL_INJECTION_ATTEMPT,
                    ['query' => $query]
                );
                throw new SecurityException('Potential SQL injection detected');
            }
        }
    }

    private function handleTransactionFailure(\Exception $e): void
    {
        $this->metrics->increment('database.transaction.failures');
        
        $this->audit->logDatabaseEvent(
            DatabaseEventType::TRANSACTION_FAILURE,
            [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );
    }

    private function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->timing(
            "database.{$operation}.duration",
            $duration
        );

        if ($duration > 1.0) {
            $this->audit->logPerformanceEvent(
                PerformanceEventType::SLOW_QUERY,
                [
                    'operation' => $operation,
                    'duration' => $duration
                ]
            );
        }
    }
}
