<?php

namespace App\Core\Audit\Adapters;

class DatabaseAdapter
{
    private ConnectionInterface $connection;
    private string $table;
    private array $config;

    public function __construct(ConnectionInterface $connection, string $table, array $config = [])
    {
        $this->connection = $connection;
        $this->table = $table;
        $this->config = $config;
    }

    public function save(array $data): int
    {
        return $this->connection->table($this->table)->insertGetId($data);
    }

    public function load(int $id): ?array
    {
        return $this->connection->table($this->table)->find($id);
    }

    public function update(int $id, array $data): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->update($data);
    }

    public function delete(int $id): bool
    {
        return $this->connection->table($this->table)->delete($id);
    }
}

class CacheAdapter
{
    private CacheInterface $cache;
    private string $prefix;
    private array $config;

    public function __construct(CacheInterface $cache, string $prefix, array $config = [])
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->config = $config;
    }

    public function get(string $key)
    {
        return $this->cache->get($this->prefix . $key);
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return $this->cache->set(
            $this->prefix . $key,
            $value,
            $ttl ?? $this->config['ttl'] ?? 3600
        );
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->prefix . $key);
    }
}

class QueueAdapter
{
    private QueueInterface $queue;
    private string $channel;
    private array $config;

    public function __construct(QueueInterface $queue, string $channel, array $config = [])
    {
        $this->queue = $queue;
        $this->channel = $channel;
        $this->config = $config;
    }

    public function push(array $data): bool
    {
        return $this->queue->push($this->channel, $data);
    }

    public function pop(): ?array
    {
        return $this->queue->pop($this->channel);
    }
}

class StorageAdapter
{
    private StorageInterface $storage;
    private string $bucket;
    private array $config;

    public function __construct(StorageInterface $storage, string $bucket, array $config = [])
    {
        $this->storage = $storage;
        $this->bucket = $bucket;
        $this->config = $config;
    }

    public function store(string $path, $contents): bool
    {
        return $this->storage->put($this->getFullPath($path), $contents);
    }

    public function retrieve(string $path)
    {
        return $this->storage->get($this->getFullPath($path));
    }

    public function delete(string $path): bool
    {
        return $this->storage->delete($this->getFullPath($path));
    }

    private function getFullPath(string $path): string
    {
        return trim($this->bucket . '/' . $path, '/');
    }
}

class LogAdapter
{
    private LoggerInterface $logger;
    private string $channel;
    private array $config;

    public function __construct(LoggerInterface $logger, string $channel, array $config = [])
    {
        $this->logger = $logger;
        $this->channel = $channel;
        $this->config = $config;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $context['channel'] = $this->channel;
        $this->logger->log($level, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
}
