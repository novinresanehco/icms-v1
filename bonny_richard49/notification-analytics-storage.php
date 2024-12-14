<?php

namespace App\Core\Notification\Analytics\Storage;

class StorageManager
{
    private array $adapters = [];
    private array $config;
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_adapter' => 'file',
            'chunk_size' => 1024 * 1024, // 1MB
            'compression' => true
        ], $config);
    }

    public function store(string $key, $data, array $options = []): bool
    {
        $adapter = $this->getAdapter($options['adapter'] ?? $this->config['default_adapter']);
        $startTime = microtime(true);

        try {
            if ($this->config['compression']) {
                $data = $this->compress($data);
            }

            $metadata = [
                'size' => strlen($data),
                'hash' => hash('sha256', $data),
                'timestamp' => time(),
                'compressed' => $this->config['compression']
            ];

            $success = $adapter->write($key, $data, array_merge($options, ['metadata' => $metadata]));
            $this->recordMetrics('store', $key, microtime(true) - $startTime, $success);

            return $success;
        } catch (\Exception $e) {
            $this->recordMetrics('store', $key, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function retrieve(string $key, array $options = [])
    {
        $adapter = $this->getAdapter($options['adapter'] ?? $this->config['default_adapter']);
        $startTime = microtime(true);

        try {
            $data = $adapter->read($key, $options);
            
            if ($data && $this->config['compression']) {
                $data = $this->decompress($data);
            }

            $this->recordMetrics('retrieve', $key, microtime(true) - $startTime, true);
            return $data;
        } catch (\Exception $e) {
            $this->recordMetrics('retrieve', $key, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function delete(string $key, array $options = []): bool
    {
        $adapter = $this->getAdapter($options['adapter'] ?? $this->config['default_adapter']);
        $startTime = microtime(true);

        try {
            $success = $adapter->delete($key, $options);
            $this->recordMetrics('delete', $key, microtime(true) - $startTime, $success);
            return $success;
        } catch (\Exception $e) {
            $this->recordMetrics('delete', $key, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function exists(string $key, array $options = []): bool
    {
        $adapter = $this->getAdapter($options['adapter'] ?? $this->config['default_adapter']);
        return $adapter->exists($key, $options);
    }

    public function registerAdapter(string $name, StorageAdapter $adapter): void
    {
        $this->adapters[$name] = $adapter;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function getAdapter(string $name): StorageAdapter
    {
        if (!isset($this->adapters[$name])) {
            throw new \InvalidArgumentException("Storage adapter not found: {$name}");
        }
        return $this->adapters[$name];
    }

    private function compress($data): string
    {
        return gzencode($data, 9);
    }

    private function decompress(string $data): string
    {
        return gzdecode($data);
    }

    private function recordMetrics(string $operation, string $key, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'total' => 0,
                'success' => 0,
                'failure' => 0,
                'total_duration' => 0
            ];
        }

        $this->metrics[$operation]['total']++;
        $this->metrics[$operation][$success ? 'success' : 'failure']++;
        $this->metrics[$operation]['total_duration'] += $duration;
    }
}

interface StorageAdapter
{
    public function write(string $key, $data, array $options = []): bool;
    public function read(string $key, array $options = []);
    public function delete(string $key, array $options = []): bool;
    public function exists(string $key, array $options = []): bool;
}

class FileStorageAdapter implements StorageAdapter
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function write(string $key, $data, array $options = []): bool
    {
        $path = $this->getPath($key);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents($path, $data);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to write data to {$path}");
        }

        if (!empty($options['metadata'])) {
            $this->writeMetadata($key, $options['metadata']);
        }

        return true;
    }

    public function read(string $key, array $options = [])
    {
        $path = $this->getPath($key);
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read data from {$path}");
        }

        return $data;
    }

    public function delete(string $key, array $options = []): bool
    {
        $path = $this->getPath($key);
        if (!file_exists($path)) {
            return false;
        }

        if (!unlink($path)) {
            throw new \RuntimeException("Failed to delete file: {$path}");
        }

        $this->deleteMetadata($key);
        return true;
    }

    public function exists(string $key, array $options = []): bool
    {
        return file_exists($this->getPath($key));
    }

    private function getPath(string $key): string
    {
        return $this->basePath . '/' . $key;
    }

    private function writeMetadata(string $key, array $metadata): void
    {
        $path = $this->getPath($key) . '.meta';
        file_put_contents($path, json_encode($metadata));
    }

    private function deleteMetadata(string $key): void
    {
        $path = $this->getPath($key) . '.meta';
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

class RedisStorageAdapter implements StorageAdapter
{
    private \Redis $redis;
    private string $prefix;

    public function __construct(\Redis $redis, string $prefix = 'analytics:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function write(string $key, $data, array $options = []): bool
    {
        $fullKey = $this->prefix . $key;
        $success = $this->redis->set($fullKey, $data);

        if ($success && !empty($options['metadata'])) {
            $this->redis->hMSet($fullKey . ':meta', $options['metadata']);
        }

        if (!empty($options['ttl'])) {
            $this->redis->expire($fullKey, $options['ttl']);
        }

        return (bool)$success;
    }

    public function read(string $key, array $options = [])
    {
        return $this->redis->get($this->prefix . $key);
    }

    public function delete(string $key, array $options = []): bool
    {
        $fullKey = $this->prefix . $key;
        $deleted = $this->redis->del($fullKey);
        $this->redis->del($fullKey . ':meta');
        return $deleted > 0;
    }

    public function exists(string $key, array $options = []): bool
    {
        return (bool)$this->redis->exists($this->prefix . $key);
    }
}
