<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\CacheException;
use Illuminate\Support\Facades\Redis;

class CacheManager implements CacheInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $drivers = [];

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeDrivers();
    }

    public function remember(string $key, mixed $data, int $ttl = 3600): mixed
    {
        $monitoringId = $this->monitor->startOperation('cache_remember');
        
        try {
            $this->validateKey($key);
            $this->validateTTL($ttl);
            
            if ($cached = $this->get($key)) {
                $this->monitor->recordSuccess($monitoringId);
                return $cached;
            }
            
            $value = $data instanceof \Closure ? $data() : $data;
            $this->set($key, $value, $ttl);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $value;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new CacheException('Cache remember failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $monitoringId = $this->monitor->startOperation('cache_set');
        
        try {
            $this->validateKey($key);
            $this->validateTTL($ttl);
            $this->validateValue($value);
            
            $secured = $this->secureValue($value);
            $success = $this->store($key, $secured, $ttl);
            
            if ($success) {
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new CacheException('Cache set failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function get(string $key): mixed
    {
        $monitoringId = $this->monitor->startOperation('cache_get');
        
        try {
            $this->validateKey($key);
            
            $value = $this->retrieve($key);
            
            if ($value !== null) {
                $value = $this->unsecureValue($value);
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $value;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new CacheException('Cache get failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function forget(string $key): bool
    {
        $monitoringId = $this->monitor->startOperation('cache_forget');
        
        try {
            $this->validateKey($key);
            
            $success = $this->delete($key);
            
            if ($success) {
                $this->monitor->recordSuccess($monitoringId);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new CacheException('Cache forget failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new CacheException('Cache key cannot be empty');
        }

        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateTTL(int $ttl): void
    {
        if ($ttl < 0) {
            throw new CacheException('TTL cannot be negative');
        }

        if ($ttl > $this->config['max_ttl']) {
            throw new CacheException('TTL exceeds maximum allowed value');
        }
    }

    private function validateValue(mixed $value): void
    {
        if (!$this->isSerializable($value)) {
            throw new CacheException('Cache value must be serializable');
        }

        $size = strlen(serialize($value));
        if ($size > $this->config['max_value_size']) {
            throw new CacheException('Cache value exceeds maximum size');
        }
    }

    private function secureValue(mixed $value): string
    {
        $serialized = serialize($value);
        return $this->security->encrypt($serialized);
    }

    private function unsecureValue(string $value): mixed
    {
        $decrypted = $this->security->decrypt($value);
        return unserialize($decrypted);
    }

    private function store(string $key, string $value, int $ttl): bool
    {
        $driver = $this->getDriver();
        return $driver->set($this->prefixKey($key), $value, $ttl);
    }

    private function retrieve(string $key): ?string
    {
        $driver = $this->getDriver();
        return $driver->get($this->prefixKey($key));
    }

    private function delete(string $key): bool
    {
        $driver = $this->getDriver();
        return $driver->delete($this->prefixKey($key));
    }

    private function initializeDrivers(): void
    {
        foreach ($this->config['drivers'] as $name => $config) {
            $this->drivers[$name] = $this->createDriver($name, $config);
        }
    }

    private function getDriver(): CacheDriverInterface
    {
        $driver = $this->config['default_driver'];
        
        if (!isset($this->drivers[$driver])) {
            throw new CacheException("Cache driver not found: {$driver}");
        }
        
        return $this->drivers[$driver];
    }

    private function createDriver(string $name, array $config): CacheDriverInterface
    {
        $class = $this->config['driver_map'][$name] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new CacheException("Invalid cache driver: {$name}");
        }
        
        return new $class($config);
    }

    private function prefixKey(string $key): string
    {
        return $this->config['key_prefix'] . ':' . $key;
    }

    private function isSerializable(mixed $value): bool
    {
        try {
            serialize($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
