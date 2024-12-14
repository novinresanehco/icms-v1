<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\CacheManagerInterface;

class CacheManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private array $config;
    private array $tags = [];

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateSecureKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        $this->validateKey($key);
        $this->trackCacheOperation($key, 'read');

        try {
            return Cache::tags($this->tags)->remember($cacheKey, $ttl, function() use ($callback, $key) {
                $value = $callback();
                $this->validateData($value);
                $this->trackCacheOperation($key, 'write');
                return $this->encryptIfNeeded($value);
            });
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return $callback();
        }
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->generateSecureKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        $this->validateKey($key);
        $this->validateData($value);
        $this->trackCacheOperation($key, 'write');

        try {
            $value = $this->encryptIfNeeded($value);
            return Cache::tags($this->tags)->put($cacheKey, $value, $ttl);
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return false;
        }
    }

    public function get(string $key): mixed
    {
        $cacheKey = $this->generateSecureKey($key);
        
        $this->validateKey($key);
        $this->trackCacheOperation($key, 'read');

        try {
            $value = Cache::tags($this->tags)->get($cacheKey);
            return $value ? $this->decryptIfNeeded($value) : null;
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return null;
        }
    }

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function forget(string $key): bool
    {
        $cacheKey = $this->generateSecureKey($key);
        
        $this->validateKey($key);
        $this->trackCacheOperation($key, 'delete');

        try {
            return Cache::tags($this->tags)->forget($cacheKey);
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return false;
        }
    }

    public function flush(): bool
    {
        if (!empty($this->tags)) {
            return Cache::tags($this->tags)->flush();
        }
        return Cache::flush();
    }

    public function increment(string $key, int $value = 1): int
    {
        $cacheKey = $this->generateSecureKey($key);
        
        $this->validateKey($key);
        $this->trackCacheOperation($key, 'increment');

        try {
            return Cache::tags($this->tags)->increment($cacheKey, $value);
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return 0;
        }
    }

    public function decrement(string $key, int $value = 1): int
    {
        $cacheKey = $this->generateSecureKey($key);
        
        $this->validateKey($key);
        $this->trackCacheOperation($key, 'decrement');

        try {
            return Cache::tags($this->tags)->decrement($cacheKey, $value);
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return 0;
        }
    }

    private function generateSecureKey(string $key): string
    {
        return hash('sha256', $key . config('app.key'));
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > 250) {
            throw new CacheException('Cache key too long');
        }

        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateData(mixed $data): void
    {
        if (!is_null($data) && !is_scalar($data) && !is_array($data)) {
            throw new CacheException('Invalid data type for caching');
        }
    }

    private function trackCacheOperation(string $key, string $operation): void
    {
        DB::table('cache_operations')->insert([
            'key' => $key,
            'operation' => $operation,
            'tags' => json_encode($this->tags),
            'timestamp' => now()
        ]);
    }

    private function handleCacheFailure(\Exception $e, string $key): void
    {
        report($e);
        
        // Log failure
        DB::table('cache_failures')->insert([
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        // Alert if critical
        if ($this->isCriticalCacheKey($key)) {
            $this->alertCacheFailure($key, $e);
        }
    }

    private function encryptIfNeeded(mixed $value): mixed
    {
        if ($this->shouldEncrypt()) {
            return encrypt($value);
        }
        return $value;
    }

    private function decryptIfNeeded(mixed $value): mixed
    {
        if ($this->shouldEncrypt()) {
            return decrypt($value);
        }
        return $value;
    }

    private function shouldEncrypt(): bool
    {
        return in_array('encryption', $this->tags);
    }

    private function isCriticalCacheKey(string $key): bool
    {
        return str_starts_with($key, 'critical.');
    }

    private function alertCacheFailure(string $key, \Exception $e): void
    {
        app(AlertManager::class)->send(
            'cache_failure',
            "Cache failure for critical key: $key",
            [
                'key' => $key,
                'error' => $e->getMessage(),
                'tags' => $this->tags
            ]
        );
    }
}

class CacheMonitor
{
    private array $metrics = [];
    private MetricsCollector $collector;

    public function track(string $key, callable $operation): mixed
    {
        $start = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $start;

        $this->recordMetrics($key, $duration);
        return $result;
    }

    private function recordMetrics(string $key, float $duration): void
    {
        $this->metrics[$key] = [
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ];

        $this->collector->record('cache_operation', [
            'key' => $key,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class CacheOptimizer
{
    private CacheManager $cache;
    private CacheMonitor $monitor;

    public function optimize(): void
    {
        $metrics = $this->monitor->getMetrics();
        
        foreach ($metrics as $key => $data) {
            if ($data['duration'] > 0.1) { // 100ms threshold
                $this->optimizeKey($key);
            }
        }
    }

    private function optimizeKey(string $key): void
    {
        // Implement optimization strategies
        // Like adjusting TTL, pre-warming, etc.
    }
}
