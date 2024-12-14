<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

class CacheManager implements CacheManagerInterface 
{
    private SecurityManager $security;
    private array $config;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        array $config,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleGet($key, $default),
            ['operation' => 'cache_get']
        );
    }

    public function set(string $key, $value, int $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleSet($key, $value, $ttl),
            ['operation' => 'cache_set']
        );
    }

    public function remember(string $key, callable $callback, int $ttl = null): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleRemember($key, $callback, $ttl),
            ['operation' => 'cache_remember']
        );
    }

    public function forget(string $key): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleForget($key),
            ['operation' => 'cache_forget']
        );
    }

    private function handleGet(string $key, $default = null): mixed
    {
        $startTime = microtime(true);
        try {
            $value = Cache::get($this->sanitizeKey($key), $default);
            $this->metrics->recordCacheHit($key, $value !== null);
            $this->metrics->recordCacheLatency($key, microtime(true) - $startTime);
            return $value;
        } catch (InvalidArgumentException $e) {
            Log::error('Cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            $this->metrics->recordCacheError($key, 'get');
            throw $e;
        }
    }

    private function handleSet(string $key, $value, ?int $ttl): bool
    {
        $startTime = microtime(true);
        try {
            $ttl = $ttl ?? $this->config['default_ttl'];
            $result = Cache::set($this->sanitizeKey($key), $value, $ttl);
            $this->metrics->recordCacheLatency($key, microtime(true) - $startTime);
            return $result;
        } catch (InvalidArgumentException $e) {
            Log::error('Cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            $this->metrics->recordCacheError($key, 'set');
            throw $e;
        }
    }

    private function handleRemember(string $key, callable $callback, ?int $ttl): mixed
    {
        $startTime = microtime(true);
        try {
            $ttl = $ttl ?? $this->config['default_ttl'];
            $value = Cache::remember(
                $this->sanitizeKey($key),
                $ttl,
                $callback
            );
            $this->metrics->recordCacheLatency($key, microtime(true) - $startTime);
            return $value;
        } catch (\Throwable $e) {
            Log::error('Cache remember failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            $this->metrics->recordCacheError($key, 'remember');
            throw $e;
        }
    }

    private function handleForget(string $key): bool
    {
        try {
            return Cache::forget($this->sanitizeKey($key));
        } catch (InvalidArgumentException $e) {
            Log::error('Cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            $this->metrics->recordCacheError($key, 'forget');
            throw $e;
        }
    }

    public function flush(): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => Cache::flush(),
            ['operation' => 'cache_flush']
        );
    }

    public function tags(array $tags): self
    {
        $instance = clone $this;
        Cache::tags($tags);
        return $instance;
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $key);
    }

    public function getMultiple(array $keys, $default = null): array 
    {
        return $this->security->executeCriticalOperation(
            fn() => array_map(
                fn($key) => $this->handleGet($key, $default),
                $keys
            ),
            ['operation' => 'cache_get_multiple']
        );
    }

    public function setMultiple(array $values, int $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => array_reduce(
                array_keys($values),
                fn($carry, $key) => $carry && $this->handleSet($key, $values[$key], $ttl),
                true
            ),
            ['operation' => 'cache_set_multiple']
        );
    }

    public function deleteMultiple(array $keys): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => array_reduce(
                $keys,
                fn($carry, $key) => $carry && $this->handleForget($key),
                true
            ),
            ['operation' => 'cache_delete_multiple']
        );
    }
}
