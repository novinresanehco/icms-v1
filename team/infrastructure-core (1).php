<?php
namespace App\Core\Infrastructure;

class CacheManager implements CacheManagerInterface
{
    private Cache $store;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private int $defaultTtl = 3600;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        try {
            $ttl = $ttl ?? $this->defaultTtl;
            $this->validateKey($key);
            
            if ($cached = $this->get($key)) {
                $this->metrics->incrementCacheHit($key);
                return $cached;
            }

            $value = $callback();
            $this->put($key, $value, $ttl);
            $this->metrics->incrementCacheMiss($key);
            
            return $value;
        } catch (\Exception $e) {
            $this->metrics->incrementCacheError($key);
            throw new CacheException("Cache operation failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        $this->validateKey($key);
        $encrypted = $this->security->encryptCacheData($value);
        return $this->store->put($key, $encrypted, $ttl);
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $encrypted = $this->store->get($key);
        
        if ($encrypted === null) {
            return null;
        }
        
        return $this->security->decryptCacheData($encrypted);
    }

    public function invalidate(array|string $keys): bool
    {
        $keys = (array) $keys;
        foreach ($keys as $key) {
            $this->validateKey($key);
            $this->store->forget($key);
        }
        return true;
    }

    private function validateKey(string $key): void
    {
        if (!$this->security->validateCacheKey($key)) {
            throw new InvalidArgumentException('Invalid cache key');
        }
    }
}

class DatabaseManager implements DatabaseManagerInterface
{
    private ConnectionPool $pool;
    private QueryMonitor $monitor;
    private SecurityManager $security;
    private int $maxRetries = 3;

    public function executeQuery(string $query, array $params = []): mixed
    {
        $this->validateQuery($query);
        $attempt = 0;
        
        while ($attempt < $this->maxRetries) {
            try {
                $connection = $this->pool->getConnection();
                $this->monitor->startQuery($query, $params);
                
                $result = $connection->execute($query, $params);
                
                $this->monitor->endQuery();
                $this->pool->releaseConnection($connection);
                
                return $result;
            } catch (DatabaseException $e) {
                $attempt++;
                if ($attempt === $this->maxRetries) {
                    throw new DatabaseException(
                        "Query failed after {$this->maxRetries} attempts: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
                usleep(100000 * $attempt); // Exponential backoff
            }
        }
    }

    public function transaction(callable $callback): mixed
    {
        $connection = $this->pool->getConnection();
        
        try {
            $connection->beginTransaction();
            $result = $callback($connection);
            $connection->commit();
            
            return $result;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new DatabaseException(
                "Transaction failed: {$e->getMessage()}",
                0,
                $e
            );
        } finally {
            $this->pool->releaseConnection($connection);
        }
    }

    private function validateQuery(string $query): void
    {
        if (!$this->security->validateQuery($query)) {
            throw new SecurityException('Query validation failed');
        }
    }
}

class StorageManager implements StorageManagerInterface
{
    private Filesystem $disk;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function store(UploadedFile $file, string $path): string
    {
        $this->validateFile($file);
        $this->validatePath($path);
        
        $securePath = $this->security->generateSecurePath($path);
        $encryptedFile = $this->security->encryptFile($file);
        
        $this->metrics->trackStorage($file->getSize());
        
        return $this->disk->put($securePath, $encryptedFile);
    }

    public function retrieve(string $path): File
    {
        $this->validatePath($path);
        
        $encryptedFile = $this->disk->get($path);
        return $this->security->decryptFile($encryptedFile);
    }

    public function delete(string $path): bool
    {
        $this->validatePath($path);
        return $this->disk->delete($path);
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$this->security->validateFile($file)) {
            throw new SecurityException('File validation failed');
        }
    }

    private function validatePath(string $path): void
    {
        if (!$this->security->validatePath($path)) {
            throw new SecurityException('Invalid storage path');
        }
    }
}

interface CacheManagerInterface
{
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    public function put(string $key, mixed $value, int $ttl): bool;
    public function get(string $key): mixed;
    public function invalidate(array|string $keys): bool;
}

interface DatabaseManagerInterface
{
    public function executeQuery(string $query, array $params = []): mixed;
    public function transaction(callable $callback): mixed;
}

interface StorageManagerInterface
{
    public function store(UploadedFile $file, string $path): string;
    public function retrieve(string $path): File;
    public function delete(string $path): bool;
}
