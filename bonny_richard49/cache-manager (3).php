<?php

namespace App\Core\Cache;

use App\Core\Interfaces\CacheManagerInterface;
use App\Core\Security\CoreSecurityManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

class CacheManager implements CacheManagerInterface
{
    private CoreSecurityManager $security;
    private array $config;
    private string $prefix;
    private array $stats;

    public function __construct(
        CoreSecurityManager $security,
        array $config = [],
        string $prefix = 'cms:'
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->prefix = $prefix;
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0
        ];
    }

    public function get(string $key)
    {
        try {
            $fullKey = $this->getFullKey($key);
            $value = Cache::get($fullKey);

            if ($value !== null) {
                $this->stats['hits']++;
                return $this->decryptIfNeeded($value);
            }

            $this->stats['misses']++;
            return null;

        } catch (\Exception $e) {
            $this->handleError('get', $e, ['key' => $key]);
            return null;
        }
    }

    public function set(string $key, $value, int $ttl = null): bool
    {
        try {
            $fullKey = $this->getFullKey($key);
            $encrypted = $this->encryptIfNeeded($value);
            
            $result = Cache::set($fullKey, $encrypted, $this->getTtl($ttl));
            
            if ($result) {
                $this->stats['writes']++;
            }
            
            return $result;

        } catch (\Exception $e) {
            $this->handleError('set', $e, ['key' => $key]);
            return false;
        }
    }

    public function remember(string $key, int $ttl = null, callable $callback)
    {
        try {
            $fullKey = $this->getFullKey($key);
            
            return Cache::remember(
                $fullKey,
                $this->getTtl($ttl),
                function() use ($callback) {
                    $value = $callback();
                    $this->stats['writes']++;
                    return $this->encryptIfNeeded($value);
                }
            );

        } catch (\Exception $e) {
            $this->handleError('remember', $e, ['key' => $key]);
            return $callback();
        }
    }

    public function invalidate(string $key): bool
    {
        try {
            $fullKey = $this->getFullKey($key);
            return Cache::forget($fullKey);

        } catch (\Exception $e) {
            $this->handleError('invalidate', $e, ['key' => $key]);
            return false;
        }
    }

    public function invalidatePattern(string $pattern): bool
    {
        try {
            $pattern = $this->getFullKey($pattern);
            $keys = $this->getKeysByPattern($pattern);
            
            foreach ($keys as $key) {
                Cache::forget($key);
            }
            
            return true;

        } catch (\Exception $e) {
            $this->handleError('invalidatePattern', $e, ['pattern' => $pattern]);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            $pattern = $this->getFullKey('*');
            $keys = $this->getKeysByPattern($pattern);
            
            foreach ($keys as $key) {
                Cache::forget($key);
            }
            
            return true;

        } catch (\Exception $e) {
            $this->handleError('flush', $e);
            return false;
        }
    }

    public function getStats(): array
    {
        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'writes' => $this->stats['writes'],
            'hit_ratio' => $this->calculateHitRatio(),
            'memory_usage' => $this->getMemoryUsage()
        ];
    }

    protected function getFullKey(string $key): string
    {
        return $this->prefix . $key;
    }

    protected function getTtl(?int $ttl): int
    {
        return $ttl ?? $this->config['default_ttl'] ?? 3600;
    }

    protected function encryptIfNeeded($value)
    {
        if ($this->shouldEncrypt($value)) {
            $serialized = serialize($value);
            return $this->security->encryptData($serialized);
        }
        return $value;
    }

    protected function decryptIfNeeded($value)
    {
        if ($this->isEncrypted($value)) {
            $decrypted = $this->security->decryptData($value);
            return unserialize($decrypted);
        }
        return $value;
    }

    protected function shouldEncrypt($value): bool
    {
        return $this->config['encrypt_all'] ?? false || 
               (is_array($value) && ($this->config['encrypt_arrays'] ?? true)) ||
               (is_object($value) && ($this->config['encrypt_objects'] ?? true));
    }

    protected function isEncrypted($value): bool
    {
        // Implement encryption detection logic
        return is_string($value) && str_starts_with($value, 'encrypted:');
    }

    protected function getKeysByPattern(string $pattern): array
    {
        // Implementation depends on cache driver
        // This is a basic example for file cache
        if (Cache::getStore() instanceof \Illuminate\Cache\FileStore) {
            $files = glob(storage_path('framework/cache/*'));
            $keys = [];
            
            foreach ($files as $file) {
                $key = basename($file);
                if (fnmatch($pattern, $key)) {
                    $keys[] = $key;
                }
            }
            
            return $keys;
        }
        
        return [];
    }

    protected function calculateHitRatio(): float
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        return $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;
    }

    protected function getMemoryUsage(): array
    {
        if (function_exists('memory_get_usage')) {
            return [
                'current' => memory_get_usage(),
                'peak' => memory_get_peak_usage()
            ];
        }
        return [];
    }

    protected function handleError(string $operation, \Exception $e, array $context = []): void
    {
        Log::error('Cache operation failed', [
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
