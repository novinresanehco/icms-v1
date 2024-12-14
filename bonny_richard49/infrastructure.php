<?php
namespace App\Core\Infrastructure;

class CacheManager
{
    private array $stores;
    private string $default;
    private SecurityManager $security;

    public function remember(string $key, callable $callback, int $ttl = 3600)
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }

    public function put(string $key, $value, int $ttl = 3600): bool
    {
        try {
            $this->security->validateCacheData($value);
            return $this->store()->put($key, $value, $ttl);
        } catch (\Exception $e) {
            throw new CacheException('Cache write failed', 0, $e);
        }
    }

    public function tags(array $tags): static
    {
        return $this->store()->tags($tags);
    }
}

class DatabaseManager
{
    private SecurityManager $security;
    private QueryBuilder $builder;
    private LogManager $logger;

    public function transaction(callable $callback)
    {
        DB::beginTransaction();
        
        try {
            $result = $callback();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logQueryError($e);
            throw new DatabaseException('Transaction failed', 0, $e);
        }
    }

    public function query(string $sql, array $bindings = []): array
    {
        try {
            $this->security->validateQuery($sql, $bindings);
            return $this->executeQuery($sql, $bindings);
        } catch (\Exception $e) {
            $this->logger->logQueryError($e);
            throw new DatabaseException('Query failed', 0, $e);
        }
    }

    private function executeQuery(string $sql, array $bindings): array
    {
        $start = microtime(true);
        $result = $this->builder->raw($sql, $bindings);
        $time = microtime(true) - $start;
        
        if ($time > 1.0) {
            $this->logger->logSlowQuery($sql, $time);
        }
        
        return $result;
    }
}

class FileManager
{
    private StorageManager $storage;
    private SecurityManager $security;
    private string $defaultDisk = 'local';

    public function store(UploadedFile $file, string $path = ''): string
    {
        try {
            $this->security->validateFile($file);
            return $this->storage->disk($this->defaultDisk)->store($file, $path);
        } catch (\Exception $e) {
            throw new FileException('File store failed', 0, $e);
        }
    }

    public function delete(string $path): bool
    {
        try {
            return $this->storage->disk($this->defaultDisk)->delete($path);
        } catch (\Exception $e) {
            throw new FileException('File delete failed', 0, $e);
        }
    }
}