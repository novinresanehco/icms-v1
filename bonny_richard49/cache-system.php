<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Cache;

class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private AuditLoggerInterface $audit;
    private array $config;
    private array $drivers = [];
    
    public function __construct(
        SecurityManagerInterface $security,
        AuditLoggerInterface $audit,
        array $config
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function get(string $key, $default = null)
    {
        return $this->security->executeCriticalOperation(
            new CacheReadOperation($key),
            function() use ($key, $default) {
                // Get appropriate cache driver
                $driver = $this->getDriver($key);
                
                // Get with validation
                $value = $driver->get($this->sanitizeKey($key));
                
                // Verify data integrity
                if ($value !== null && !$this->verifyIntegrity($key, $value)) {
                    $this->handleIntegrityFailure($key);
                    return $default;
                }
                
                // Log cache access
                $this->audit->logCacheAccess('read', $key, $value !== null);
                
                return $value ?? $default;
            }
        );
    }

    public function put(string $key, $value, $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheWriteOperation($key),
            function() use ($key, $value, $ttl) {
                // Validate data before caching
                $this->validateCacheData($value);
                
                // Get appropriate driver
                $driver = $this->getDriver($key);
                
                // Add integrity metadata
                $value = $this->addIntegrityMetadata($key, $value);
                
                // Store with configured TTL
                $success = $driver->put(
                    $this->sanitizeKey($key),
                    $value,
                    $ttl ?? $this->getDefaultTTL($key)
                );
                
                // Log cache operation
                $this->audit->logCacheAccess('write', $key, $success);
                
                return $success;
            }
        );
    }

    public function remember(string $key, callable $callback, $ttl = null)
    {
        return $this->security->executeCriticalOperation(
            new CacheRememberOperation($key),
            function() use ($key, $callback, $ttl) {
                // Try to get from cache first
                $value = $this->get($key);
                
                if ($value !== null) {
                    return $value;
                }
                
                // Generate new value
                $value = $callback();
                
                // Store in cache
                $this->put($key, $value, $ttl);
                
                return $value;
            }
        );
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this,
            $tags,
            $this->security,
            $this->audit
        );
    }

    public function flush(string $tag = null): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheFlushOperation($tag),
            function() use ($tag) {
                if ($tag) {
                    // Flush specific tag
                    Cache::tags($tag)->flush();
                    $this->audit->logCacheAccess('flush_tag', $tag, true);
                } else {
                    // Flush all cache
                    Cache::flush();
                    $this->audit->logCacheAccess('flush_all', null, true);
                }
                
                return true;
            }
        );
    }

    protected function getDriver(string $key): CacheDriver
    {
        $driverName = $this->determineDriver($key);
        
        if (!isset($this->drivers[$driverName])) {
            $this->drivers[$driverName] = $this->createDriver($driverName);
        }
        
        return $this->drivers[$driverName];
    }

    protected function determineDriver(string $key): string
    {
        // Check for specific driver mapping
        foreach ($this->config['key_patterns'] as $pattern => $driver) {
            if (preg_match($pattern, $key)) {
                return $driver;
            }
        }
        
        // Return default driver
        return $this->config['default_driver'];
    }

    protected function createDriver(string $driver): CacheDriver
    {
        return match($driver) {
            'redis' => new RedisDriver($this->config['redis']),
            'memcached' => new MemcachedDriver($this->config['memcached']),
            'file' => new FileDriver($this->config['file']),
            default => throw new InvalidCacheDriverException("Unknown driver: {$driver}")
        };
    }

    protected function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9:._-]/', '', $key);
    }

    protected function validateCacheData($data): void
    {
        if (!is_serializable($data)) {
            throw new InvalidCacheDataException('Cache data must be serializable');
        }

        // Size validation
        if (strlen(serialize($data)) > $this->config['max_size']) {
            throw new CacheSizeLimitException('Cache data exceeds size limit');
        }
    }

    protected function addIntegrityMetadata($key, $value): array
    {
        return [
            'data' => $value,
            'hash' => $this->calculateHash($key, $value),
            'timestamp' => time()
        ];
    }

    protected function verifyIntegrity(string $key, array $data): bool
    {
        if (!isset($data['hash'], $data['timestamp'])) {
            return false;
        }

        // Verify hash
        $expectedHash = $this->calculateHash($key, $data['data']);
        if ($data['hash'] !== $expectedHash) {
            return false;
        }

        // Verify timestamp is within acceptable range
        if (time() - $data['timestamp'] > $this->config['max_age']) {
            return false;
        }

        return true;
    }

    protected function calculateHash(string $key, $value): string
    {
        return hash_hmac(
            'sha256',
            $key . serialize($value),
            $this->config['integrity_key']
        );
    }

    protected function handleIntegrityFailure(string $key): void
    {
        // Log security event
        $this->audit->logSecurityEvent('cache_integrity_failure', [
            'key' => $key,
            'timestamp' => time()
        ]);

        // Remove corrupted data
        $this->forget($key);
    }

    protected function getDefaultTTL(string $key): int
    {
        // Check for specific TTL mapping
        foreach ($this->config['ttl_patterns'] as $pattern => $ttl) {
            if (preg_match($pattern, $key)) {
                return $ttl;
            }
        }
        
        return $this->config['default_ttl'];
    }
}
