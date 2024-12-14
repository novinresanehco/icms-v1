<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\{CacheManagerInterface, SecurityManagerInterface};
use App\Core\Security\Context\CacheContext;
use App\Core\Exceptions\{CacheException, ValidationException};

class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private array $config;
    private array $metrics;
    private string $prefix;

    public function __construct(
        SecurityManagerInterface $security,
        array $config
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->metrics = [];
        $this->prefix = $this->config['cache_prefix'] ?? 'cms';
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateCacheKey($key);
        $startTime = microtime(true);

        try {
            if ($this->has($cacheKey)) {
                $this->recordHit($cacheKey);
                return $this->get($cacheKey);
            }

            $value = $callback();
            $this->set($cacheKey, $value, $ttl);
            $this->recordMiss($cacheKey, microtime(true) - $startTime);

            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($cacheKey, $e);
            return $callback();
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->generateCacheKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        try {
            $this->validateValue($value);
            $secureValue = $this->prepareForCache($value);
            
            Cache::put($cacheKey, $secureValue, $ttl);
            $this->recordWrite($cacheKey);
            
            return true;

        } catch (\Exception $e) {
            $this->handleCacheFailure($cacheKey, $e);
            return false;
        }
    }

    public function get(string $key): mixed
    {
        $cacheKey = $this->generateCacheKey($key);

        try {
            if (!$this->has($cacheKey)) {
                $this->recordMiss($cacheKey);
                return null;
            }

            $value = Cache::get($cacheKey);
            $this->validateCachedValue($value);
            
            $this->recordHit($cacheKey);
            return $this->restoreFromCache($value);

        } catch (\Exception $e) {
            $this->handleCacheFailure($cacheKey, $e);
            return null;
        }
    }

    public function has(string $key): bool
    {
        return Cache::has($this->generateCacheKey($key));
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->generateCacheKey($key);
        Cache::forget($cacheKey);
        $this->recordDelete($cacheKey);
        return true;
    }

    public function flush(): bool
    {
        $pattern = $this->prefix . ':*';
        $keys = $this->scanKeys($pattern);
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        
        $this->recordFlush();
        return true;
    }

    public function tags(array $tags): static
    {
        $this->prefix = $this->prefix . ':' . implode(':', $tags);
        return $this;
    }

    public function getMetrics(): array
    {
        return [
            'hits' => $this->metrics['hits'] ?? 0,
            'misses' => $this->metrics['misses'] ?? 0,
            'writes' => $this->metrics['writes'] ?? 0,
            'deletes' => $this->metrics['deletes'] ?? 0,
            'hit_ratio' => $this->calculateHitRatio(),
            'average_latency' => $this->calculateAverageLatency()
        ];
    }

    protected function generateCacheKey(string $key): string
    {
        $hash = hash('xxh3', $key);
        return sprintf('%s:%s:%s', $this->prefix, $key, $hash);
    }

    protected function validateValue(mixed $value): void
    {
        if (!$this->isSerializable($value)) {
            throw new ValidationException('Value cannot be serialized');
        }

        if ($this->exceedsMaxSize($value)) {
            throw new ValidationException('Value exceeds maximum cache size');
        }
    }

    protected function validateCachedValue(mixed $value): void
    {
        if (!$this->verifyIntegrity($value)) {
            throw new CacheException('Cache integrity check failed');
        }
    }

    protected function prepareForCache(mixed $value): mixed
    {
        $prepared = [
            'data' => $value,
            'timestamp' => time(),
            'checksum' => $this->calculateChecksum($value)
        ];

        return $this->config['encryption_enabled'] 
            ? $this->encrypt($prepared) 
            : $prepared;
    }

    protected function restoreFromCache(mixed $value): mixed
    {
        $data = $this->config['encryption_enabled'] 
            ? $this->decrypt($value) 
            : $value;

        return $data['data'];
    }

    protected function calculateChecksum(mixed $value): string
    {
        return hash_hmac(
            'xxh3',
            serialize($value),
            $this->config['integrity_key']
        );
    }

    protected function verifyIntegrity(mixed $value): bool
    {
        $data = $this->config['encryption_enabled'] 
            ? $this->decrypt($value) 
            : $value;

        return hash_equals(
            $data['checksum'],
            $this->calculateChecksum($data['data'])
        );
    }

    protected function scanKeys(string $pattern): array
    {
        return Cache::getRedis()->keys($pattern);
    }

    protected function recordHit(string $key): void
    {
        $this->metrics['hits'] = ($this->metrics['hits'] ?? 0) + 1;
        $this->metrics['keys'][$key]['hits'] = ($this->metrics['keys'][$key]['hits'] ?? 0) + 1;
    }

    protected function recordMiss(string $key, float $latency = 0): void
    {
        $this->metrics['misses'] = ($this->metrics['misses'] ?? 0) + 1;
        $this->metrics['keys'][$key]['misses'] = ($this->metrics['keys'][$key]['misses'] ?? 0) + 1;
        $this->metrics['latencies'][] = $latency;
    }

    protected function calculateHitRatio(): float
    {
        $hits = $this->metrics['hits'] ?? 0;
        $total = $hits + ($this->metrics['misses'] ?? 0);
        return $total > 0 ? $hits / $total : 0;
    }

    protected function calculateAverageLatency(): float
    {
        $latencies = $this->metrics['latencies'] ?? [];
        return !empty($latencies) ? array_sum($latencies) / count($latencies) : 0;
    }

    protected function handleCacheFailure(string $key, \Exception $e): void
    {
        Log::error('Cache operation failed', [
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
