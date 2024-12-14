```php
namespace App\Core\Storage;

class DatabaseManager implements StorageInterface
{
    private PDO $connection;
    private QueryBuilder $builder;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function executeQuery(string $sql, array $params = []): Result
    {
        return $this->security->executeProtected(function() use ($sql, $params) {
            $this->validateQuery($sql);
            
            return $this->metrics->track('database.query', function() use ($sql, $params) {
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return new Result($stmt);
            });
        });
    }

    private function validateQuery(string $sql): void
    {
        if (!$this->builder->isQuerySafe($sql)) {
            throw new UnsafeQueryException();
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->connection->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->connection->commit();
            return $result;
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}

class CacheManager implements CacheInterface 
{
    private array $stores = [];
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        return $this->metrics->track('cache.operation', function() use ($key, $callback, $ttl) {
            if ($cached = $this->get($key)) {
                $this->metrics->increment('cache.hit');
                return $this->security->decryptData($cached);
            }

            $value = $callback();
            $this->put($key, $this->security->encryptData($value), $ttl);
            $this->metrics->increment('cache.miss');
            
            return $value;
        });
    }

    private function get(string $key): mixed
    {
        return $this->stores['primary']->get($key);
    }

    private function put(string $key, mixed $value, int $ttl): void
    {
        foreach ($this->stores as $store) {
            $store->put($key, $value, $ttl);
        }
    }

    public function invalidate(string $key): void
    {
        foreach ($this->stores as $store) {
            $store->forget($key);
        }
    }
}

class QueryBuilder 
{
    private array $allowedCommands = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
    private array $blockedKeywords = ['UNION', 'EXECUTE', 'TRUNCATE'];

    public function isQuerySafe(string $sql): bool
    {
        $normalized = strtoupper($sql);

        // Check for allowed commands
        if (!$this->hasAllowedCommand($normalized)) {
            return false;
        }

        // Check for blocked keywords
        if ($this->hasBlockedKeywords($normalized)) {
            return false;
        }

        // Validate query structure
        return $this->validateQueryStructure($normalized);
    }

    private function hasAllowedCommand(string $sql): bool
    {
        foreach ($this->allowedCommands as $command) {
            if (strpos($sql, $command) === 0) {
                return true;
            }
        }
        return false;
    }

    private function hasBlockedKeywords(string $sql): bool
    {
        foreach ($this->blockedKeywords as $keyword) {
            if (strpos($sql, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function validateQueryStructure(string $sql): bool
    {
        // Implement query structure validation
        return true;
    }
}

class Result
{
    private PDOStatement $statement;

    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function all(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(): ?array
    {
        $result = $this->statement->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    public function count(): int
    {
        return $this->statement->rowCount();
    }
}
```
